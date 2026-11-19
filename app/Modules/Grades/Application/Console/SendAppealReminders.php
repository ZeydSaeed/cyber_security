<?php

namespace App\Modules\Grades\Application\Console;

use App\Modules\Grades\Application\AppealReminders;
use Illuminate\Console\Command;

/**
 * Reminds the department of appeals about to close, and of appeals
 * waiting too long (ADR-0045). Scheduled hourly by the module's provider.
 */
class SendAppealReminders extends Command
{
    protected $signature = 'grades:appeal-reminders';

    protected $description = 'Remind the department of appeals closing within a day and of appeals waiting two days or more';

    public function handle(AppealReminders $reminders): int
    {
        $sent = $reminders->send();
        $this->info("Closing reminders: {$sent['closing']}; overdue reminders: {$sent['overdue']}.");

        return self::SUCCESS;
    }
}
