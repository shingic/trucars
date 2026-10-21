<?php

declare(strict_types=1);

namespace App\Support;

use Normalizer;

/**
 * Text from dealer feeds arrives in every state: clean UTF-8, Windows-1252
 * (Excel exports), UTF-8 encoded twice or three times ("4×4" → "4Ã—4" →
 * "4ÃƒÂ—4"), HTML entities ("4&times;4"), non-breaking spaces and invisible
 * characters. clean() turns all of it into plain UTF-8 and leaves good text
 * exactly as it was.
 */
final class FeedText
{
    /** Windows-1252 characters for bytes 0x80–0x9F — what a UTF-8 continuation byte turns into when misread. */
    private const CP1252 = [
        "\u{20AC}" => 0x80, "\u{201A}" => 0x82, "\u{0192}" => 0x83, "\u{201E}" => 0x84, "\u{2026}" => 0x85,
        "\u{2020}" => 0x86, "\u{2021}" => 0x87, "\u{02C6}" => 0x88, "\u{2030}" => 0x89, "\u{0160}" => 0x8A,
        "\u{2039}" => 0x8B, "\u{0152}" => 0x8C, "\u{017D}" => 0x8E, "\u{2018}" => 0x91, "\u{2019}" => 0x92,
        "\u{201C}" => 0x93, "\u{201D}" => 0x94, "\u{2022}" => 0x95, "\u{2013}" => 0x96, "\u{2014}" => 0x97,
        "\u{02DC}" => 0x98, "\u{2122}" => 0x99, "\u{0161}" => 0x9A, "\u{203A}" => 0x9B, "\u{0153}" => 0x9C,
        "\u{017E}" => 0x9E, "\u{0178}" => 0x9F,
    ];

    public static function clean(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        // Not UTF-8 at all: a Windows/Excel export. Read it as Windows-1252.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }

        $text = self::repairDoubleEncoding($text);

        // "4&times;4", "Black &amp; Grey" — sometimes escaped twice.
        for ($i = 0; $i < 2 && preg_match('/&(?:#\d+|#x[0-9a-f]+|[a-z]+\d*);/i', $text); $i++) {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $text = self::repairDoubleEncoding($text);

        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        }

        // Non-breaking and odd-width spaces become plain spaces; invisible and
        // control characters go (tabs and line breaks stay).
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $text);
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}\x{FFFD}]/u', '', $text);

        return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
    }

    /**
     * Undo UTF-8 that was misread as Windows-1252 and saved again, one
     * character at a time so text mixing good and broken characters is
     * fixed too. Repeated for text that went through it more than once.
     */
    private static function repairDoubleEncoding(string $text): string
    {
        $continuation = '[\x{0080}-\x{00BF}' . implode('', array_keys(self::CP1252)) . ']';
        $pattern = "/[\x{00C2}-\x{00DF}]{$continuation}|[\x{00E0}-\x{00EF}]{$continuation}{2}|[\x{00F0}-\x{00F4}]{$continuation}{3}/u";

        for ($i = 0; $i < 3; $i++) {
            $repaired = preg_replace_callback($pattern, function (array $match) {
                $bytes = '';
                foreach (mb_str_split($match[0]) as $char) {
                    $bytes .= chr(self::CP1252[$char] ?? mb_ord($char));
                }

                // Only a sequence that decodes to a real character is replaced.
                return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $match[0];
            }, $text);

            if ($repaired === null || $repaired === $text) {
                break;
            }

            $text = $repaired;
        }

        return $text;
    }
}
