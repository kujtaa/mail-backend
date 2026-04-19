<?php
namespace Tests\Feature;

use App\Models\Business;
use App\Models\City;
use App\Models\Category;
use App\Models\UnsubscribedEmail;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private EmailService $emailService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emailService = new EmailService();
    }

    public function test_valid_token_unsubscribes_email(): void
    {
        $token = $this->emailService->generateUnsubscribeToken('user@example.com');

        $this->getJson("/unsubscribe/{$token}")
             ->assertStatus(200)
             ->assertJsonPath('email', 'user@example.com');

        $this->assertDatabaseHas('unsubscribed_emails', ['email' => 'user@example.com']);
    }

    public function test_already_unsubscribed_returns_ok(): void
    {
        $token = $this->emailService->generateUnsubscribeToken('already@example.com');
        UnsubscribedEmail::create([
            'email' => 'already@example.com',
            'token' => $token,
            'unsubscribed_at' => now(),
        ]);

        $this->getJson("/unsubscribe/{$token}")
             ->assertStatus(200)
             ->assertJsonPath('email', 'already@example.com');
    }

    public function test_invalid_token_returns_400(): void
    {
        $this->getJson('/unsubscribe/invalid.token')
             ->assertStatus(400);
    }

    public function test_unsubscribe_links_business_id(): void
    {
        $city = City::factory()->create();
        $cat = Category::factory()->create();
        $biz = Business::factory()->create([
            'city_id' => $city->id, 'category_id' => $cat->id, 'email' => 'biz@example.com',
        ]);

        $token = $this->emailService->generateUnsubscribeToken('biz@example.com');
        $this->getJson("/unsubscribe/{$token}")->assertStatus(200);

        $this->assertDatabaseHas('unsubscribed_emails', [
            'email' => 'biz@example.com', 'business_id' => $biz->id,
        ]);
    }

    public function test_post_unsubscribe_also_works(): void
    {
        $token = $this->emailService->generateUnsubscribeToken('post@example.com');

        $this->postJson("/unsubscribe/{$token}")
             ->assertStatus(200)
             ->assertJsonPath('email', 'post@example.com');

        $this->assertDatabaseHas('unsubscribed_emails', ['email' => 'post@example.com']);
    }
}
