<?php

namespace App\Modules\Grades\Domain;

/**
 * Who may appeal while appeals are open (ADR-0043): every student, or only
 * the students the owner allowed one by one.
 */
enum AppealScope: string
{
    case All = 'all';
    case Selected = 'selected';
}
