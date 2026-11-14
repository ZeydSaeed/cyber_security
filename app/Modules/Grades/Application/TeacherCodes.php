<?php

namespace App\Modules\Grades\Application;

use App\Platform\Exceptions\DomainRuleViolation;
use RuntimeException;

/**
 * Teacher codes (ADR-0034). A code is kept as an HMAC-SHA256 digest under a
 * key of its own, so the table never holds a code, a stolen table cannot be
 * checked against guesses without the key, and a teacher is found by one
 * indexed lookup. The key is GRADES_CODE_KEY, or one derived from APP_KEY
 * (rotating APP_KEY then means setting every code again).
 */
final class TeacherCodes
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 64;

    public function digest(string $code): string
    {
        return hash_hmac('sha256', self::normalise($code), $this->key());
    }

    /**
     * The rules for a code the owner sets: 8 to 64 characters, no spaces.
     * Throws with the field name, so a form shows the message beside it.
     */
    public function assertAcceptable(string $code, string $field = 'code'): void
    {
        $code = self::normalise($code);
        $length = mb_strlen($code);

        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new DomainRuleViolation(__('The code must be between :min and :max characters.', ['min' => self::MIN_LENGTH, 'max' => self::MAX_LENGTH]), $field);
        }

        if (preg_match('/\s/u', $code) === 1) {
            throw new DomainRuleViolation(__('The code may not contain spaces.'), $field);
        }
    }

    /**
     * Codes are compared as typed, without surrounding spaces and with
     * Arabic-Indic digits read as 0-9 (a phone keyboard may type either).
     */
    public static function normalise(string $code): string
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
