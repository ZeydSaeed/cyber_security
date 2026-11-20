<?php

namespace App\Modules\Grades\Domain;

use DateTimeInterface;

/**
 * Whether a teacher may change one section's marks now, and which marks
 * (ADR-0037). Pure: the caller reads the settings, the teacher, the section
 * and the period, and passes what they say.
 *
 * - Published results are never changed by a teacher.
 * - An unexpired grant also reopens marks the teacher approved (ADR-0041);
 *   without one, approved marks are read-only.
 * - An unexpired grant (the owner's unlock, or an approved request) allows
 *   every change, even with mark entry closed or a view-only mode.
 * - Otherwise mark entry must be open, and the mode decides: Edit and
 *   Timed allow, ViewOnly and Blocked refuse.
 * - In Edit and Timed, a time limit (ADR-0043) locks a mark once the given
 *   minutes have passed since it was first entered, and every mark at the
 *   deadline, whichever comes first. New marks can still be entered. Edit
 *   may have no limit; Timed always has one (ManageAccess checks).
 */
final readonly class EditPolicy
{
    public function __construct(
        public TeacherAccess $mode,
        /** Minutes a mark stays changeable after it is first entered. */
        public ?int $editMinutes,
        public ?DateTimeInterface $grantUntil,
        public bool $windowOpen,
        public bool $published,
        public DateTimeInterface $now,
        /** The teacher approved this section's marks (ADR-0041). */
        public bool $approved = false,
        /** A fixed moment after which no mark can be changed (ADR-0043). */
        public ?DateTimeInterface $editDeadline = null,
    ) {}

    /** Whether a time limit applies to marks already entered. */
    public function isLimited(): bool
    {
        return in_array($this->mode, [TeacherAccess::Edit, TeacherAccess::Timed], true)
            && ($this->editMinutes !== null || $this->editDeadline !== null);
    }

    public function hasGrant(): bool
    {
        return ! $this->published
            && $this->grantUntil !== null
            && $this->grantUntil->getTimestamp() > $this->now->getTimestamp();
    }

    /** Whether the section's sheet accepts any change. */
    public function canWrite(): bool
    {
        if ($this->published) {
            return false;
        }

        if ($this->hasGrant()) {
            return true;
        }

        if ($this->approved) {
            return false;
        }

        return $this->windowOpen && in_array($this->mode, [TeacherAccess::Edit, TeacherAccess::Timed], true);
    }

    /**
     * Whether a mark first entered at the given time can still be changed
     * or removed. Null: the student has no mark yet.
     */
    public function canChange(?DateTimeInterface $enteredAt): bool
    {
        if (! $this->canWrite()) {
            return false;
        }

        if ($enteredAt === null) {
            return true;
        }

        $until = $this->editableUntil($enteredAt);

        return $until === null || $until > $this->now->getTimestamp();
    }

    /** Until when a mark entered at the given time stays editable (null: no limit). */
    public function editableUntil(DateTimeInterface $enteredAt): ?int
    {
        if ($this->hasGrant() || ! $this->isLimited()) {
            return null;
        }

        $byTime = $this->editMinutes === null ? null : $enteredAt->getTimestamp() + $this->editMinutes * 60;
        $byDate = $this->editDeadline?->getTimestamp();

        // Whichever comes first; isLimited() means at least one is set.
        return match (true) {
            $byTime === null => $byDate,
            $byDate === null => $byTime,
            default => min($byTime, $byDate),
        };
    }
}
