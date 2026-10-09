<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Accent- and case-insensitive whole-word matching on ad titles/descriptions,
 * shared by the body-type guard and the model-reputation check.
 */
final class TitleText
{
    /** Lower-case and strip accents, so "Citroën", "berlină" and "uși" match plain-ASCII keywords. */
    public static function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower(trim($text)));
    }

    /**
     * Whole-word/phrase match against already-normalized text: "mini" doesn't hit "minivan",
     * "mazda 2" doesn't hit "mazda 2013" or "mazda 2.0", and spaces in a phrase match any run
     * of whitespace.
     */
    public static function containsWord(string $haystack, string $keyword): bool
    {
        $pattern = str_replace(' ', '\s+', preg_quote(self::normalize($keyword), '/'));

        return preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])(?![.,]\d)/u', $haystack) === 1;
    }
}
