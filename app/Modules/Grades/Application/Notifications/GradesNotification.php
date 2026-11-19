<?php

namespace App\Modules\Grades\Application\Notifications;

use Illuminate\Notifications\Notification;

/**
 * A Grades event for the owner (ADR-0034): a teacher finished a section.
 * Database channel; the fields are facts, and the interface writes the
 * sentence in the reader's language (ADR-0029).
 */
class GradesNotification extends Notification
{
    public const SECTION_COMPLETED = 'grades_section_completed';

    /** A teacher asks to change locked marks (ADR-0042). */
    public const EDIT_REQUESTED = 'grades_edit_requested';

    /** A student appeals a mark (ADR-0042). */
    public const APPEAL_SUBMITTED = 'grades_appeal_submitted';

    /** A teacher answered an appeal of their subject (ADR-0044). */
    public const APPEAL_ANSWERED = 'grades_appeal_answered';

    /** A section's appeals close within a day (ADR-0045). */
    public const APPEALS_CLOSING = 'grades_appeals_closing';

    /** Appeals have waited two days or more (ADR-0045). */
    public const APPEALS_OVERDUE = 'grades_appeals_overdue';

    /**
     * @param  array<string, string>  $facts
     */
    public function __construct(private readonly string $kind, private readonly array $facts) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, ...$this->facts];
    }
}
