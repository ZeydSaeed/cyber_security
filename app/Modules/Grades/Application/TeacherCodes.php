<?php

namespace App\Modules\Grades\Application;

use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Teacher codes (ADR-0034). A code is kept as an HMAC-SHA256 digest under a
 * key of its own, so the table never holds a code, a stolen table cannot be
 * checked against guesses without the key, and a teacher is found by one
 * indexed lookup. The key is GRADES_CODE_KEY, or one derived from APP_KEY
 * (rotating APP_KEY then means setting every code again).
 *
 * A copy encrypted with the application key lets the owner see it again.
 *
 * Since 2026-11-16 (owner's request) the application makes each code in
 * a student's shape, XXXX-XXXX from the same alphabet, but always starting
 * with T, which no student's code does: the first letter tells a teacher's
 * code from a student's. The seven random characters give 31^7, about
 * 2.8 * 10^10 codes; with the sign-in limits (5 a minute, 20 an hour per
 * address) guessing one is out of reach. As for students, case, spaces and
 * dashes do not matter. A code typed by the owner (SaveTeacher::changeCode,
 * 8 to 64 characters) is compared the same way.
 */
final class TeacherCodes
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 64;

    /**
     * Every code the application makes starts with this letter; a
     * student's code never does (StudentCodes::generate).
     */
    public const MARK = 'T';

    /**
     * A new random code shaped like a student's (XXXX-XXXX, from the same
     * alphabet without look-alikes) but starting with T: "TK7M-Q2XZ".
     */
    public function generate(): string
    {
        $code = self::MARK;
        $last = strlen(StudentCodes::ALPHABET) - 1;

        for ($i = 1; $i < StudentCodes::LENGTH; $i++) {
            $code .= StudentCodes::ALPHABET[random_int(0, $last)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /** Whether a code, as typed, is one of the teachers' made codes. */
    public static function isTeacherCode(string $code): bool
    {
        return str_starts_with(self::normalise($code), self::MARK);
    }

    public function digest(string $code): string
    {
        return hash_hmac('sha256', self::normalise($code), $this->key());
    }

    public function encrypt(string $code): string
    {
        return Crypt::encryptString(self::normalise($code));
    }

    public function decrypt(string $encrypted): string
    {
        $code = Crypt::decryptString($encrypted);

        // A made code reads as XXXX-XXXX, as a student's does.
        return strlen($code) === StudentCodes::LENGTH ? substr($code, 0, 4).'-'.substr($code, 4) : $code;
    }

    /**
     * The rules for a code the owner sets: 8 to 64 characters, no spaces.
     * Throws with the field name, so a form shows the message beside it.
     */
    public function assertAcceptable(string $code, string $field = 'code'): void
    {
        // Spaces are checked as written: comparison would drop them.
        $written = self::digits($code);
        $length = mb_strlen(self::normalise($code));

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new DomainRuleViolation(__('The code must be between :min and :max characters.', ['min' => self::MIN_LENGTH, 'max' => self::MAX_LENGTH]), $field);
        }

        if (preg_match('/\s/u', $written) === 1) {
            throw new DomainRuleViolation(__('The code may not contain spaces.'), $field);
        }
    }

    /**
     * Codes are compared without case, spaces or dashes, with Arabic-Indic
     * digits read as 0-9 (a phone keyboard may type either).
     */
    public static function normalise(string $code): string
    {
        return self::compact(strtoupper(self::digits($code)));
    }

    /** Drops spaces, dashes and underscores. */
    private static function compact(string $code): string
    {
        return (string) preg_replace('/[\s\-_]+/u', '', $code);
    }

    /**
     * Arabic-Indic digits as 0-9, surrounding spaces dropped; shared with
     * StudentCodes.
     */
    public static function digits(string $code): string
    {
        return trim(strtr($code, [
            "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
            "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
            "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
            "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
        ]));
    }

    private function key(): string
    {
        $key = (string) config('grades.code_key');

        if ($key !== '') {
            return $key;
        }

        $appKey = (string) config('app.key');

        if ($appKey === '') {
            throw new RuntimeException('Neither GRADES_CODE_KEY nor APP_KEY is set.');
        }

        return hash_hmac('sha256', 'grades.teacher-codes', $appKey);
    }
}
