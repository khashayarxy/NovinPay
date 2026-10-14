<?php

namespace App\Support;

/**
 * Normalizes Persian/Arabic text and digits into a single canonical form so
 * that catalogue search matches regardless of which character variant the
 * shopper typed or the product row was stored with.
 *
 * Display columns (name / summary / description) are never modified — only
 * the derived `products.search_text` value and the incoming query are
 * normalized. No extra dependency is introduced.
 */
final class SearchNormalizer
{
    /**
     * Normalize a single string: Persian/Arabic digits to ASCII and the
     * Arabic yeh/kaf variants to their Persian counterparts, then lowercase.
     */
    public static function normalize(string $value): string
    {
        $value = FaNumber::normalizeDigits($value);

        // Arabic yeh (ي U+064A) -> Persian yeh (ی U+06CC)
        // Arabic kaf (ك U+0643) -> Persian kaf (ک U+06A9)
        $value = str_replace(
            ["\u{064A}", "\u{0643}"],
            ["\u{06CC}", "\u{06A9}"],
            $value
        );

        // ZWNJ (U+200C) is pervasive in Persian orthography (می‌شود) but is
        // routinely dropped when typing; remove it so both forms match the
        // same haystack. Collapse Unicode whitespace runs (including NBSP)
        // to a single space and trim so multi-spaced input still matches.
        $value = str_replace("\u{200C}", '', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value), 'UTF-8');
    }

    /**
     * Build the normalized search haystack from the display fields. Empty or
     * whitespace-only fields are skipped so stray spaces do not accumulate.
     */
    public static function forProduct(?string $name, ?string $summary, ?string $description): string
    {
        $parts = array_filter(
            [$name, $summary, $description],
            fn ($part): bool => is_string($part) && trim($part) !== ''
        );

        return self::normalize(implode(' ', $parts));
    }
}
