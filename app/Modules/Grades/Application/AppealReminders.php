<?php

namespace App\Modules\Grades\Application;

use App\Modules\Grades\Application\Actions\ManageAppeals;
use App\Modules\Grades\Application\Jobs\NotifyGradesManagers;
use App\Modules\Grades\Application\Notifications\GradesNotification;
use App\Modules\Grades\Domain\AppealStatus;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\Stage;
use App\Modules\Grades\Models\GradeAppeal;
use App\Modules\Grades\Models\GradePeriod;
use App\Modules\Grades\Models\GradeSettings;
use App\Modules\Grades\Models\Student;
use App\Platform\Outbox\Outbox;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reminders to the department about appeals (ADR-0045), run every hour:
 *
 * - a day before a published section's appeals close, how many of its
 *   appeals still wait (once per section and closing moment);
 * - once a day, how many appeals have waited two days or more.
 *
 * Each goes through the outbox to everyone with grades.manage, like the
 * other Grades alerts. A cache key, added in the same transaction, makes
 * each reminder go once even if the schedule runs twice.
 */
final class AppealReminders
{
    /** An appeal waiting this long is overdue. */
    public const OVERDUE_HOURS = 48;

    public function __construct(private readonly Outbox $outbox) {}

    /**
     * @return array{closing: int, overdue: int} reminders recorded
     */
    public function send(Period $period = Period::Month1): array
    {
        $settings = GradeSettings::current();
        $window = GradePeriod::for($period);
        $sent = ['closing' => 0, 'overdue' => 0];

        if ($window->anyPublished()) {
            foreach (Stage::cases() as $stage) {
                foreach ($stage->sections() as $section) {
                    $published = $window->publishedAtFor($stage, $section);
                    $closes = $published === null ? null : ManageAppeals::closesAt($settings, $published);
                    if ($closes === null || $closes->isPast() || $closes->greaterThan(now()->addDay())) {
                        continue;
                    }
                    // A section without students has no one to appeal.
                    if (! Student::query()->where('stage', $stage->value)->where('section', $section->value)->exists()) {
                        continue;
                    }

                    $waiting = GradeAppeal::query()
                        ->where('period', $period->value)
                        ->where('status', AppealStatus::Pending->value)
                        ->whereHas('student', fn ($query) => $query->where('stage', $stage->value)->where('section', $section->value))
                        ->count();

                    $key = "grades:appeals-closing:{$period->value}:{$stage->value}{$section->value}:{$closes->getTimestamp()}";
                    $sent['closing'] += $this->once($key, GradesNotification::APPEALS_CLOSING, [
                        'stage' => (string) $stage->value,
                        'section' => $section->value,
                        'at' => $closes->toIso8601String(),
                        'count' => (string) $waiting,
                    ]);
                }
            }
        }

        $overdue = GradeAppeal::query()
            ->where('period', $period->value)
            ->where('status', AppealStatus::Pending->value)
            ->where('created_at', '<=', now()->subHours(self::OVERDUE_HOURS))
            ->count();
        if ($overdue > 0) {
            $key = "grades:appeals-overdue:{$period->value}:".now()->toDateString();
            $sent['overdue'] += $this->once($key, GradesNotification::APPEALS_OVERDUE, ['count' => (string) $overdue]);
        }

        return $sent;
    }

    /**
     * @param  array<string, string>  $facts
     */
    private function once(string $key, string $kind, array $facts): int
    {
        return DB::transaction(function () use ($key, $kind, $facts): int {
            if (! Cache::add($key, true, now()->addDays(3))) {
                return 0;
            }

            $this->outbox->record(NotifyGradesManagers::TOPIC, ['kind' => $kind, 'facts' => $facts]);

            return 1;
        }, 3);
    }
}
