<?php

namespace Tests\Unit;

use App\Support\CanadianAddress;
use PHPUnit\Framework\TestCase;

class CanadianAddressTest extends TestCase
{
    public function test_provinces_normalise_to_two_letter_codes(): void
    {
        $this->assertSame('BC', CanadianAddress::normalizeProvince('bc'));
        $this->assertSame('BC', CanadianAddress::normalizeProvince('B.C.'));
        $this->assertSame('BC', CanadianAddress::normalizeProvince('British Columbia'));
        $this->assertSame('QC', CanadianAddress::normalizeProvince('PQ'));
        $this->assertNull(CanadianAddress::normalizeProvince('Ontaria'));
        $this->assertNull(CanadianAddress::normalizeProvince(''));
    }

    public function test_postal_codes_normalise_to_canada_post_format(): void
    {
        $this->assertSame('V2P 2M9', CanadianAddress::normalizePostalCode('v2p2m9'));
        $this->assertSame('M5V 2T6', CanadianAddress::normalizePostalCode(' m5v-2t6 '));
        $this->assertNull(CanadianAddress::normalizePostalCode('12345'));
        $this->assertNull(CanadianAddress::normalizePostalCode('D1A 1A1')); // D is never a first letter
    }

    public function test_postal_code_prefix_identifies_the_province(): void
    {
        $this->assertSame('BC', CanadianAddress::provinceForPostalCode('V2P 2M9'));
        $this->assertSame('ON', CanadianAddress::provinceForPostalCode('M5V 2T6'));
        $this->assertNull(CanadianAddress::provinceForPostalCode('X0A 0H0')); // shared by NT and NU
    }

    public function test_default_timezones_are_iana_zones_and_nunavut_has_none(): void
    {
        $this->assertSame('America/Vancouver', CanadianAddress::defaultTimezoneFor('BC'));
        $this->assertSame('America/Toronto', CanadianAddress::defaultTimezoneFor('on'));
        $this->assertSame('America/Regina', CanadianAddress::defaultTimezoneFor('SK'));
        $this->assertNull(CanadianAddress::defaultTimezoneFor('NU'));

        foreach (array_keys(CanadianAddress::PROVINCES) as $province) {
            $zone = CanadianAddress::defaultTimezoneFor($province);
            $this->assertTrue($zone === null || in_array($zone, CanadianAddress::timezones(), true), "{$province} → {$zone}");
        }
    }
}
