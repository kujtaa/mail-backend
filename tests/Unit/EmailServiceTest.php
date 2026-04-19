<?php
namespace Tests\Unit;

use App\Services\EmailService;
use Tests\TestCase;

class EmailServiceTest extends TestCase
{
    private EmailService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EmailService();
    }

    public function test_generate_and_verify_unsubscribe_token(): void
    {
        $email = 'user@example.com';
        $token = $this->service->generateUnsubscribeToken($email);
        $this->assertStringContainsString('.', $token);
        $this->assertSame($email, $this->service->verifyUnsubscribeToken($token));
    }

    public function test_invalid_token_returns_null(): void
    {
        $this->assertNull($this->service->verifyUnsubscribeToken('invalid.token'));
    }

    public function test_tampered_token_returns_null(): void
    {
        $token = $this->service->generateUnsubscribeToken('user@example.com');
        $this->assertNull($this->service->verifyUnsubscribeToken($token . 'x'));
    }

    public function test_mask_email(): void
    {
        $this->assertSame('j***e@example.com', $this->service->maskEmail('jane@example.com'));
        $this->assertSame('a***@example.com', $this->service->maskEmail('ab@example.com'));
    }

    public function test_build_unsubscribe_url(): void
    {
        $url = $this->service->buildUnsubscribeUrl('user@example.com');
        $this->assertStringStartsWith('http://localhost:5173/unsubscribe/', $url);
    }
}
