<?php

namespace App\Modules\Grades\Application;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Students' codes (ADR-0034, owner's decision of 2026-11-15): made by the
 * application, eight characters from an alphabet without look-alikes (no
 * 0/O, 1/I/L), shown as XXXX-XXXX. 31^8 is about 8.5 * 10^11 codes, so with
 * the sign-in limits guessing one is out of reach. Kept as an HMAC digest
 * to find the student and encrypted with the application key so the owner
 * can print the list again; never in the audit trail.
 */
final class StudentCodes
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    public function generate(): string
    {
        $code = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $last)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
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

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * As typed: case, spaces and dashes do not matter, and Arabic-Indic
     * digits are read as 0-9.
     */
    public static function normalise(string $code): string
    {
        $code = strtoupper(TeacherCodes::normalise($code));

        return (string) preg_replace('/[\s\-_]+/u', '', $code);
    }

    private function key(): string
    {
        $key = (string) config('grades.code_key');
        $base = $key !== '' ? $key : (string) config('app.key');

        if ($base === '') {
            throw new RuntimeException('Neither GRADES_CODE_KEY nor APP_KEY is set.');
        }

        // Separate from the teachers' digests: one code never matches both.
        return hash_hmac('sha256', 'grades.student-codes', $base);
    }
}
