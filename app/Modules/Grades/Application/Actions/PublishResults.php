<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Models\GradePeriod;
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
            $this->audit->record($publish ? 'grades.results_published' : 'grades.results_unpublished', $locked, ['period' => $period->value], actor: $actor);

            return $locked;
        }, 3);
    }
}
