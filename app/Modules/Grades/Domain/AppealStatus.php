<?php

namespace App\Modules\Grades\Domain;

/**
 * A student's appeal of a mark (ADR-0042):
 *
 *   pending --accept--> accepted
 *   pending --reject--> rejected
 *
 * Answered once; accepting does not change the mark by itself (the owner
 * corrects it on the sheet, recorded there).
 */
enum AppealStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function canBecome(self $next): bool
    {
        return $this === self::Pending && $next !== self::Pending;
    }
}
