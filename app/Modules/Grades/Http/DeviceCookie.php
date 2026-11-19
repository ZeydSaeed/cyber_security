<?php

namespace App\Modules\Grades\Http;

use App\Modules\Grades\Domain\DeviceHolder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The cookie of a remembered device (ADR-0038): one for teachers on
 * /teacher, one for students on /student, HttpOnly, SameSite=Lax, secure
 * as the session cookie, and encrypted like every application cookie.
 */
final class DeviceCookie
{
    public static function name(DeviceHolder $kind): string
    {
        return 'grades_'.$kind->value.'_device';
    }

    public static function read(Request $request, DeviceHolder $kind): ?string
    {
        $value = $request->cookie(self::name($kind));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function put(DeviceHolder $kind, string $token, int $days): void
    {
        Cookie::queue(Cookie::make(
            self::name($kind),
            $token,
            $days * 24 * 60,
            self::path($kind),
            null,
            config('session.secure'),
            true,
            false,
            'lax',
        ));
    }

    public static function forget(DeviceHolder $kind): void
    {
        Cookie::queue(Cookie::forget(self::name($kind), self::path($kind)));
    }

    private static function path(DeviceHolder $kind): string
    {
        return $kind === DeviceHolder::Teacher ? '/teacher' : '/student';
    }
}
