<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Section;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\SectionPublication;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * Shows or hides a period's results to the students (ADR-0034). Published
 * only while mark entry is closed, so a student never sees marks that are
 * still changing; opening entry again needs the results hidden first
 * (ChangeEntryWindow). Idempotent and audited.
 */
class PublishResults
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Period $period, bool $publish): GradePeriod
    {
        GradePeriod::for($period);

        return DB::transaction(function () use ($actor, $period, $publish): GradePeriod {
            $locked = GradePeriod::query()->where('period', $period->value)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($publish === ($locked->results_published_at !== null)) {
                return $locked;
            }

            if ($publish && $locked->isOpen()) {
                throw new DomainRuleViolation(__('Close mark entry before publishing the results.'), 'publish');
            }

            $locked->forceFill(['results_published_at' => $publish ? now() : null, 'lock_version' => $locked->lock_version + 1])->save();
            // Hiding all hides the sections published on their own too.
            if (! $publish) {
                SectionPublication::query()->where('period', $period->value)->delete();
            }
            $this->audit->record($publish ? 'grades.results_published' : 'grades.results_unpublished', $locked, ['period' => $period->value], actor: $actor);

            return $locked;
        }, 3);
    }

    /**
     * Publishes several sections at once (owner's request, 2026-11-20: the
     * stages and sections are ticked, then published together), under the
     * rules of section(): all or none, in one transaction. A section
     * already published is left as it is.
     *
     * @param  list<array{0: Stage, 1: Section}>  $places
     */
    public function sections(User $actor, Period $period, array $places): void
    {
        foreach ($places as [$stage, $section]) {
            if (! $stage->hasSection($section)) {
                throw new DomainRuleViolation(__('This stage has no such section.'), 'publish');
            }
        }

        GradePeriod::for($period);

        DB::transaction(function () use ($actor, $period, $places): void {
            $locked = GradePeriod::query()->where('period', $period->value)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($locked->isPublished()) {
                throw new DomainRuleViolation(__('Every section is published. Hide the results first to publish sections one by one.'), 'publish');
            }
            if ($locked->isOpen()) {
                throw new DomainRuleViolation(__('Close mark entry before publishing the results.'), 'publish');
            }

            foreach ($places as [$stage, $section]) {
                $exists = SectionPublication::query()->where('period', $period->value)->where('stage', $stage->value)->where('section', $section->value)->exists();
                if ($exists) {
                    continue;
                }

                (new SectionPublication)->forceFill(['stage' => $stage, 'section' => $section, 'period' => $period, 'published_at' => now()])->save();
                $this->audit->record('grades.section_published', $locked, ['stage' => $stage->value, 'section' => $section->value], actor: $actor);
            }
        }, 3);
    }

    /**
     * Shows or hides one section's results (ADR-0042), as the whole period
     * is: only while mark entry is closed, and only while the period is not
     * published to every section already.
     */
    public function section(User $actor, Period $period, Stage $stage, Section $section, bool $publish): void
    {
        if (! $stage->hasSection($section)) {
            throw new DomainRuleViolation(__('This stage has no such section.'), 'publish');
        }

        GradePeriod::for($period);

        DB::transaction(function () use ($actor, $period, $stage, $section, $publish): void {
            $locked = GradePeriod::query()->where('period', $period->value)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if ($locked->isPublished()) {
                throw new DomainRuleViolation(__('Every section is published. Hide the results first to publish sections one by one.'), 'publish');
            }
            if ($publish && $locked->isOpen()) {
                throw new DomainRuleViolation(__('Close mark entry before publishing the results.'), 'publish');
            }

            $rows = SectionPublication::query()->where('period', $period->value)->where('stage', $stage->value)->where('section', $section->value);
            if ($publish === $rows->exists()) {
                return;
            }

            if ($publish) {
                (new SectionPublication)->forceFill(['stage' => $stage, 'section' => $section, 'period' => $period, 'published_at' => now()])->save();
            } else {
                $rows->delete();
            }

            $this->audit->record($publish ? 'grades.section_published' : 'grades.section_unpublished', $locked, ['stage' => $stage->value, 'section' => $section->value], actor: $actor);
        }, 3);
    }
}
