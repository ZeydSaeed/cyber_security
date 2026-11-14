<?php

namespace App\Modules\Grades\Domain;

/**
 * A comparison key for names typed by different people (ADR-0034): the same
 * student written with or without hamza on the alef, with a final teh
 * marbuta or heh, with diacritics or extra spaces, gets the same key. Used to
 * match an import to existing students; the name itself is stored as
 * written.
 */
final class NameKey
{
    private const LETTERS = [
        "\u{0623}" => "\u{0627}", // alef with hamza above -> alef
        "\u{0625}" => "\u{0627}", // alef with hamza below -> alef
        "\u{0622}" => "\u{0627}", // alef with madda -> alef
        "\u{0671}" => "\u{0627}", // alef wasla -> alef
        "\u{0629}" => "\u{0647}", // teh marbuta -> heh
        "\u{0649}" => "\u{064A}", // alef maksura -> yeh
        "\u{0624}" => "\u{0648}", // waw with hamza -> waw
        "\u{0626}" => "\u{064A}", // yeh with hamza -> yeh
    ];

    public static function of(string $name): string
    {
        $key = mb_strtolower($name);
        // Diacritics, the superscript alef and the tatweel.
        $key = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $key);
        $key = strtr($key, self::LETTERS);
        // Direction marks and zero-width characters go; any run of spaces
        // (including no-break spaces) becomes one.
        $key = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $key);
        $key = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $key);

        return trim($key);
    }

    /**
     * The name as it will be shown: invisible characters removed, trimmed,
     * single spaces.
     */
    public static function clean(string $name): string
    {
        $name = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $name);

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $name));
    }
}
