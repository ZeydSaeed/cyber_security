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
 * - Otherwise mark entry must be open, and the mode decides: Edit allows,
 *   ViewOnly and Blocked refuse, Timed allows new marks and those entered
 *   less than the given hours ago.
 */
final readonly class EditPolicy
{
    public function __construct(
        public TeacherAccess $mode,
        public ?int $editHours,
        public ?DateTimeInterface $grantUntil,
        public bool $windowOpen,
        public bool $published,
        public DateTimeInterface $now,
        /** The teacher approved this section's marks (ADR-0041). */
        public bool $approved = false,
    ) {}

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

        if ($enteredAt === null || $this->hasGrant() || $this->mode !== TeacherAccess::Timed || $this->editHours === null) {
            return true;
        }

        return $enteredAt->getTimestamp() + $this->editHours * 3600 > $this->now->getTimestamp();
    }

    /** Until when a mark entered at the given time stays editable (timed mode). */
    public function editableUntil(DateTimeInterface $enteredAt): ?int
    {
        if ($this->hasGrant() || $this->mode !== TeacherAccess::Timed || $this->editHours === null) {
            return null;
        }

        return $enteredAt->getTimestamp() + $this->editHours * 3600;
    }
}
