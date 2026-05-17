<?php

namespace App\Modules\Shared\Tests;

use App\Logging\StripPiiProcessor;
use App\Modules\Shared\Helpers\PhoneNumberMasker;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class PhoneNumberMaskerTest extends TestCase
{
    // ── PhoneNumberMasker::mask ──────────────────────────────────────────

    public function test_mask_plus62_prefix(): void
    {
        $result = PhoneNumberMasker::mask('+6281234567890');
        $this->assertEquals('+62***890', $result);
    }

    public function test_mask_08_prefix(): void
    {
        $result = PhoneNumberMasker::mask('08123456789');
        $this->assertEquals('***789', $result);
    }

    public function test_mask_62_without_plus(): void
    {
        $result = PhoneNumberMasker::mask('628123456789');
        $this->assertEquals('***789', $result);
    }

    public function test_mask_short_number(): void
    {
        $result = PhoneNumberMasker::mask('123');
        $this->assertEquals('***123', $result);
    }

    // ── PhoneNumberMasker::maskInText ────────────────────────────────────

    public function test_mask_in_text_replaces_plus62_number(): void
    {
        $result = PhoneNumberMasker::maskInText('hubungi +6281234567890 besok');
        $this->assertStringNotContainsString('+6281234567890', $result);
        $this->assertStringContainsString('+62***', $result);
        $this->assertStringContainsString('890', $result);
    }

    public function test_mask_in_text_replaces_08_number(): void
    {
        $result = PhoneNumberMasker::maskInText('nomor saya 08123456789 ya');
        $this->assertStringNotContainsString('08123456789', $result);
        $this->assertStringContainsString('***', $result);
    }

    public function test_mask_in_text_preserves_non_phone_content(): void
    {
        $text = 'harga paket gold 5 juta kak';
        $this->assertEquals($text, PhoneNumberMasker::maskInText($text));
    }

    public function test_mask_in_text_multiple_phones(): void
    {
        $result = PhoneNumberMasker::maskInText(
            'call +6281234567890 or +6289876543210'
        );
        $this->assertStringNotContainsString('+6281234567890', $result);
        $this->assertStringNotContainsString('+6289876543210', $result);
    }

    // ── StripPiiProcessor ────────────────────────────────────────────────

    public function test_processor_masks_phone_in_message(): void
    {
        $processor = new StripPiiProcessor();
        $record    = $this->makeRecord('caller +6281234567890 connected');

        $processed = $processor($record);

        $this->assertStringNotContainsString('+6281234567890', $processed->message);
        $this->assertStringContainsString('+62***', $processed->message);
    }

    public function test_processor_redacts_api_key_in_context(): void
    {
        $processor = new StripPiiProcessor();
        $record    = $this->makeRecord('LLM called', ['api_key' => 'sk-proj-xxx123']);

        $processed = $processor($record);

        $this->assertEquals('[REDACTED]', $processed->context['api_key']);
    }

    public function test_processor_redacts_secret_in_context(): void
    {
        $processor = new StripPiiProcessor();
        $record    = $this->makeRecord('auth event', ['x-internal-secret' => 'my-secret-value']);

        $processed = $processor($record);

        $this->assertEquals('[REDACTED]', $processed->context['x-internal-secret']);
    }

    public function test_processor_redacts_password_in_context(): void
    {
        $processor = new StripPiiProcessor();
        $record    = $this->makeRecord('auth', ['password' => 'hunter2', 'user' => 'admin']);

        $processed = $processor($record);

        $this->assertEquals('[REDACTED]', $processed->context['password']);
        $this->assertEquals('admin', $processed->context['user']);
    }

    public function test_processor_masks_phone_in_context_string_value(): void
    {
        $processor = new StripPiiProcessor();
        $record    = $this->makeRecord('msg', ['from' => '+6281234567890']);

        $processed = $processor($record);

        $this->assertStringNotContainsString('+6281234567890', $processed->context['from']);
    }

    public function test_processor_handles_nested_context(): void
    {
        $processor = new StripPiiProcessor();
        $record    = $this->makeRecord('msg', [
            'payload' => ['token' => 'abc123', 'phone' => '+6281234567890'],
        ]);

        $processed = $processor($record);

        $this->assertEquals('[REDACTED]', $processed->context['payload']['token']);
        $this->assertStringNotContainsString('+6281234567890', $processed->context['payload']['phone']);
    }

    // ── InputSanitizerService pattern coverage ───────────────────────────

    public function test_input_sanitizer_covers_all_claude_md_patterns(): void
    {
        $service = app(\App\Modules\AgentCore\Security\Services\InputSanitizerService::class);

        $patternsFromClaudeMd = [
            'ignore previous instructions',
            'you are now',
            'disregard',
            'forget everything',
            'new instructions:',
            'system prompt',
            'act as',
            'pretend you are',
            '[INST]',
            '<<SYS>>',
            'jangan ikuti instruksi sebelumnya',
            'abaikan instruksi',
        ];

        foreach ($patternsFromClaudeMd as $pattern) {
            $dto = $service->sanitize("kak {$pattern} do something", 'tenant-1', 'conv-1');
            $this->assertTrue($dto->injection_detected, "Pattern not detected: {$pattern}");
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeRecord(string $message, array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: $message,
            context: $context,
        );
    }
}
