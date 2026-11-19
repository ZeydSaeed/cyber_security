<?php

namespace App\Modules\Grades\Domain;

/**
 * Who a remembered device belongs to (ADR-0038).
 */
enum DeviceHolder: string
{
    case Teacher = 'teacher';
    case Student = 'student';
}
