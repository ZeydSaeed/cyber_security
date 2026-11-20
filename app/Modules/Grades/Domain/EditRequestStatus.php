<?php

namespace App\Modules\Grades\Domain;

/**
 * A teacher's request to change locked marks (ADR-0037):
 *
 *   pending --approve--> approved
 *   pending --reject---> rejected
 *
 * A decided request is final; the teacher may ask again.
 */
enum EditRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function canBecome(self $next): bool
    {
        return $this === self::Pending && $next !== self::Pending;
    }
}
