<?php
namespace Tests\Feature;

use App\Models\Business;
use App\Models\Category;
use App\Models\City;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsApproved(array $state = []): array
    {
        $company = Company::factory()->create($state);
        $token = $company->createToken('t')->plainTextToken;
        return [$company, $token];
    }

    public function test_stats_requires_auth(): void
    {
        $this->getJson('/dashboard/stats')->assertStatus(401);
    }

    public function test_stats_requires_approved(): void
    {
        [$company, $token] = $this->actingAsApproved(['is_approved' => false]);
        $this->withToken($token)->getJson('/dashboard/stats')->assertStatus(403);
    }

    public function test_stats_returns_structure(): void
    {
        [$company, $token] = $this->actingAsApproved();

        $this->withToken($token)->getJson('/dashboard/stats')
             ->assertStatus(200)
             ->assertJsonStructure([
                 'total_emails_available', 'total_businesses', 'total_with_website',
                 'total_without_website', 'total_categories', 'total_cities',
                 'emails_purchased', 'emails_sent', 'emails_failed',
                 'credit_balance', 'batches_count', 'smtp_configured', 'plan',
                 'daily_send_limit', 'daily_sends_remaining',
             ]);
    }

    public function test_browse_emails_masks_for_free_user(): void
    {
        [$company, $token] = $this->actingAsApproved(['allowed_sources' => 'local.ch']);
        $city = City::factory()->create();
        $cat = Category::factory()->create();
        Business::factory()->create([
            'city_id' => $city->id, 'category_id' => $cat->id,
            'email' => 'john@example.com', 'source' => 'local.ch',
        ]);

        $response = $this->withToken($token)->getJson('/dashboard/browse-emails');
        $response->assertStatus(200);
        $emails = collect($response->json())->pluck('email');
        $this->assertNotContains('john@example.com', $emails);
        $this->assertTrue($emails->every(fn($e) => str_contains($e, '***')));
    }

    public function test_browse_emails_shows_full_email_for_admin(): void
    {
        [$company, $token] = $this->actingAsApproved(['is_admin' => true, 'allowed_sources' => 'local.ch']);
        $city = City::factory()->create();
        $cat = Category::factory()->create();
        Business::factory()->create([
            'city_id' => $city->id, 'category_id' => $cat->id,
            'email' => 'admin@example.com', 'source' => 'local.ch',
        ]);

        $response = $this->withToken($token)->getJson('/dashboard/browse-emails');
        $response->assertStatus(200);
        $emails = collect($response->json())->pluck('email');
        $this->assertContains('admin@example.com', $emails);
    }

    public function test_purchase_batch_deducts_credits(): void
    {
        [$company, $token] = $this->actingAsApproved(['credit_balance' => 100.0, 'allowed_sources' => 'local.ch']);
        $city = City::factory()->create(['name' => 'Zurich']);
        $cat = Category::factory()->create(['name' => 'Restaurants']);
        Business::factory()->count(5)->create([
            'city_id' => $city->id, 'category_id' => $cat->id, 'source' => 'local.ch',
        ]);

        $this->withToken($token)->postJson('/dashboard/purchase-batch', [
            'category' => 'Restaurants',
            'batch_size' => 3,
        ])->assertStatus(200)->assertJsonStructure(['batch_id', 'batch_size', 'cost', 'remaining_credits']);

        $this->assertLessThan(100.0, $company->fresh()->credit_balance);
    }

    public function test_purchase_batch_insufficient_credits(): void
    {
        [$company, $token] = $this->actingAsApproved(['credit_balance' => 0.0, 'allowed_sources' => 'local.ch']);
        $city = City::factory()->create();
        $cat = Category::factory()->create(['name' => 'Test']);
        Business::factory()->count(3)->create(['city_id' => $city->id, 'category_id' => $cat->id, 'source' => 'local.ch']);

        $this->withToken($token)->postJson('/dashboard/purchase-batch', [
            'category' => 'Test',
            'batch_size' => 3,
        ])->assertStatus(400);
    }

    public function test_smtp_settings_get_and_save(): void
    {
        [$company, $token] = $this->actingAsApproved();

        $this->withToken($token)->getJson('/dashboard/smtp-settings')
             ->assertStatus(200)
             ->assertJsonStructure(['smtp_host', 'smtp_port', 'smtp_user', 'smtp_enabled', 'has_password']);

        $this->withToken($token)->putJson('/dashboard/smtp-settings', [
            'smtp_host' => 'smtp.test.com',
            'smtp_port' => 587,
            'smtp_user' => 'user@test.com',
            'smtp_pass' => 'secret',
            'smtp_from_email' => 'user@test.com',
        ])->assertStatus(200)->assertJsonPath('smtp_host', 'smtp.test.com');
    }
}
