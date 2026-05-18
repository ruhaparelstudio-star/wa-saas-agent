<?php

namespace Tests\Unit\QualityGuard;

use App\Modules\Shared\Services\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /**
     * @dataProvider normalizeCases
     */
    public function test_normalize(string $raw, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($raw));
    }

    public static function normalizeCases(): array
    {
        return [
            'plain'             => ['+628121234567', '+628121234567'],
            'no plus'           => ['628121234567',  '+628121234567'],
            'lid prefix'        => ['lid:244529684836573', '+244529684836573'],
            '@lid suffix'       => ['244529684836573@lid', '+244529684836573'],
            '@s.whatsapp.net'   => ['628121234567@s.whatsapp.net', '+628121234567'],
            'with dashes'       => ['+62-812-1234-567', '+628121234567'],
            'with spaces'       => ['+62 812 1234 567', '+628121234567'],
        ];
    }

    public function test_normalize_throws_when_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhoneNormalizer::normalize('@lid');
    }

    public function test_is_malformed_detects_lid_prefix(): void
    {
        $this->assertTrue(PhoneNormalizer::isMalformed('lid:244529684836573'));
        $this->assertTrue(PhoneNormalizer::isMalformed('244529684836573@lid'));
        $this->assertTrue(PhoneNormalizer::isMalformed(''));
        $this->assertTrue(PhoneNormalizer::isMalformed(null));
        $this->assertFalse(PhoneNormalizer::isMalformed('+628121234567'));
        $this->assertFalse(PhoneNormalizer::isMalformed('628121234567'));
    }
}
