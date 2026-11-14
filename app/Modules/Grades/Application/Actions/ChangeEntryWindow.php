<?php

namespace App\Modules\Grades\Application\Actions;

use App\Models\User;
use App\Modules\Grades\Domain\Period;
use App\Modules\Grades\Domain\WindowStatus;
use App\Modules\Grades\Models\GradePeriod;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Opens, closes or reopens a period for mark entry (ADR-0034). Idempotent:
 * opening an open period changes nothing and records nothing. The period's
 * row is locked for update, and SaveMarks reads it under a share lock, so a
 * save never commits into a period that closed before it.
 */
class ChangeEntryWindow
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $actor, Period $period, WindowStatus $target): GradePeriod
    {
        GradePeriod::for($period);

        return DB::transaction(function () use ($actor, $period, $target): GradePeriod {
            $locked = GradePeriod::query()->where('period', $period->value)->lockForUpdate()->firstOrFail();
            EnsureManager::check($actor);

            if (! $locked->status->canBecome($target)) {
                return $locked;
            }

            $locked->forceFill($target === WindowStatus::Open
                ? ['status' => $target, 'opened_at' => now(), 'closed_at' => null, 'lock_version' => $locked->lock_version + 1]
                : ['status' => $target, 'closed_at' => now(), 'lock_version' => $locked->lock_version + 1])->save();

            $this->audit->record(
                $target === WindowStatus::Open ? 'grades.window_opened' : 'grades.window_closed',
                $locked,
                ['period' => $period->value],
                actor: $actor,
            );

            return $locked;
        }, 3);
    }
}
