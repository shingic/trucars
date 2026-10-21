<?php

namespace Tests\Unit;

use App\Support\FeedText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeedTextTest extends TestCase
{
    #[DataProvider('brokenText')]
    public function test_feed_text_comes_out_clean(string $input, string $expected): void
    {
        $this->assertSame($expected, FeedText::clean($input));
    }

    public static function brokenText(): array
    {
        return [
            'double-encoded'          => ['4Ã—4', '4×4'],
            'triple-encoded'          => ['4ÃƒÂ—4', '4×4'],
            'accents'                 => ['SociÃ©tÃ© GÃ©nÃ©rale', 'Société Générale'],
            'smart quotes'            => ['itâ€™s', 'it’s'],
            'mixed good and broken'   => ['CafÃ© & Café', 'Café & Café'],
            'windows-1252 bytes'      => ["Caf\xE9 4\xD74", 'Café 4×4'],
            'html entities'           => ['4&times;4', '4×4'],
            'escaped twice'           => ['Black &amp;amp; Grey', 'Black & Grey'],
            'invisible characters'    => ["  Blue\u{00A0}\u{00A0}Metallic\u{200B}\u{FEFF} ", 'Blue Metallic'],
            'emoji'                   => ['ðŸš—', '🚗'],
            'clean text is untouched' => ['2023 Mitsubishi Outlander 4×4 Café', '2023 Mitsubishi Outlander 4×4 Café'],
            'a lone Ã is left alone'  => ['Ãpple', 'Ãpple'],
        ];
    }
}
