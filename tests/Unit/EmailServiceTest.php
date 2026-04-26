<?php
namespace Tests\Unit;

use App\Services\EmailService;
use Symfony\Component\Mime\Email;
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

    public function test_embed_inline_data_images_replaces_data_uri_with_cid_attachment(): void
    {
        $email = new Email();
        $html = '<p>Logo</p><img src="data:image/png;base64,' . base64_encode('fake-image') . '" width="120">';

        $result = $this->service->embedInlineDataImages($email, $html);

        $this->assertStringContainsString('src="cid:', $result);
        $this->assertStringNotContainsString('data:image/png;base64', $result);
        $this->assertCount(1, $email->getAttachments());
        $this->assertSame('image/png', $email->getAttachments()[0]->getMediaType() . '/' . $email->getAttachments()[0]->getMediaSubtype());
    }
}
