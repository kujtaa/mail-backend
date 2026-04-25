<?php
namespace Tests\Feature;

use App\Models\BatchEmail;
use App\Models\Business;
use App\Models\Category;
use App\Models\City;
use App\Models\Company;
use App\Models\EmailBatch;
use App\Models\SentEmail;
use App\Services\EmailService;
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

    public function test_browse_emails_shows_full_email_for_standard_user(): void
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
        $this->assertContains('john@example.com', $emails);
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

    public function test_purchase_batch_is_free_without_credits(): void
    {
        [$company, $token] = $this->actingAsApproved(['credit_balance' => 0.0, 'allowed_sources' => 'local.ch']);
        $city = City::factory()->create(['name' => 'Zurich']);
        $cat = Category::factory()->create(['name' => 'Restaurants']);
        Business::factory()->count(5)->create([
            'city_id' => $city->id, 'category_id' => $cat->id, 'source' => 'local.ch',
        ]);

        $this->withToken($token)->postJson('/dashboard/purchase-batch', [
            'category' => 'Restaurants',
            'batch_size' => 3,
        ])->assertStatus(200)->assertJsonPath('cost', 0);

        $this->assertSame(0.0, $company->fresh()->credit_balance);
    }

    public function test_purchase_batch_multi_is_free_without_credits(): void
    {
        [$company, $token] = $this->actingAsApproved(['credit_balance' => 0.0, 'allowed_sources' => 'local.ch']);
        $city = City::factory()->create();
        $cat = Category::factory()->create(['name' => 'Test']);
        Business::factory()->count(3)->create(['city_id' => $city->id, 'category_id' => $cat->id, 'source' => 'local.ch']);

        $this->withToken($token)->postJson('/dashboard/purchase-batch-multi', [
            'categories' => ['Test'],
        ])->assertStatus(200)->assertJsonPath('cost', 0);

        $this->assertSame(0.0, $company->fresh()->credit_balance);
    }

    public function test_browse_overview_returns_category_and_city_counts(): void
    {
        [$company, $token] = $this->actingAsApproved(['allowed_sources' => 'local.ch']);
        $city = City::factory()->create(['name' => 'Zurich']);
        $cat = Category::factory()->create(['name' => 'Restaurants']);
        Business::factory()->count(2)->create([
            'city_id' => $city->id,
            'category_id' => $cat->id,
            'source' => 'local.ch',
        ]);

        $this->withToken($token)->getJson('/dashboard/browse-overview')
            ->assertStatus(200)
            ->assertJsonPath('total_available', 2)
            ->assertJsonPath('by_category.0.name', 'Restaurants')
            ->assertJsonPath('by_category.0.count', 2)
            ->assertJsonPath('by_city.0.name', 'Zurich')
            ->assertJsonPath('by_city.0.count', 2);
    }

    public function test_batch_emails_excludes_already_sent_recipients(): void
    {
        [$company, $token] = $this->actingAsApproved(['allowed_sources' => 'local.ch']);
        $city = City::factory()->create(['name' => 'Zurich']);
        $cat = Category::factory()->create(['name' => 'Restaurants']);
        $batch = EmailBatch::create([
            'company_id' => $company->id,
            'category_id' => $cat->id,
            'city_id' => $city->id,
            'label' => 'Restaurants — Zurich',
            'batch_size' => 2,
            'price_paid' => 0,
            'purchased_at' => now(),
        ]);
        $sentBusiness = Business::factory()->create([
            'city_id' => $city->id,
            'category_id' => $cat->id,
            'email' => 'sent@example.com',
            'source' => 'local.ch',
        ]);
        $freshBusiness = Business::factory()->create([
            'city_id' => $city->id,
            'category_id' => $cat->id,
            'email' => 'fresh@example.com',
            'source' => 'local.ch',
        ]);
        $sentBatchEmail = BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $sentBusiness->id]);
        BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $freshBusiness->id]);
        SentEmail::create([
            'company_id' => $company->id,
            'batch_email_id' => $sentBatchEmail->id,
            'subject' => 'Previous send',
            'body' => '<p>Hello</p>',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $response = $this->withToken($token)->getJson("/dashboard/my-batches/{$batch->id}/emails");

        $response->assertStatus(200);
        $emails = collect($response->json())->pluck('email');
        $this->assertNotContains('sent@example.com', $emails);
        $this->assertContains('fresh@example.com', $emails);
    }

    public function test_send_email_only_queues_unsent_batch_recipients(): void
    {
        [$company, $token] = $this->actingAsApproved([
            'allowed_sources' => 'local.ch',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'secret',
            'smtp_enabled' => true,
        ]);
        $city = City::factory()->create(['name' => 'Zurich']);
        $cat = Category::factory()->create(['name' => 'Restaurants']);
        $batch = EmailBatch::create([
            'company_id' => $company->id,
            'category_id' => $cat->id,
            'city_id' => $city->id,
            'label' => 'Restaurants — Zurich',
            'batch_size' => 2,
            'price_paid' => 0,
            'purchased_at' => now(),
        ]);
        $sentBusiness = Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id]);
        $freshBusiness = Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id]);
        $sentBatchEmail = BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $sentBusiness->id]);
        $freshBatchEmail = BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $freshBusiness->id]);
        SentEmail::create([
            'company_id' => $company->id,
            'batch_email_id' => $sentBatchEmail->id,
            'subject' => 'Previous send',
            'body' => '<p>Hello</p>',
            'status' => 'sent',
        ]);

        $this->mock(EmailService::class, function ($mock) use ($freshBatchEmail) {
            $mock->shouldReceive('sendBatch')->once()->withArgs(function ($records) use ($freshBatchEmail) {
                return count($records) === 1 && $records[0]->batch_email_id === $freshBatchEmail->id;
            });
        });

        $this->withToken($token)->postJson('/dashboard/send-email', [
            'batch_email_ids' => [$sentBatchEmail->id, $freshBatchEmail->id],
            'subject' => 'New send',
            'body' => '<p>Hello again</p>',
        ])->assertStatus(200)->assertJsonPath('queued', 1);

        $this->assertDatabaseHas('sent_emails', [
            'company_id' => $company->id,
            'batch_email_id' => $freshBatchEmail->id,
            'subject' => 'New send',
        ]);
        $this->assertSame(2, SentEmail::where('company_id', $company->id)->count());
    }

    public function test_premium_daily_send_limit_does_not_block_batch_sending(): void
    {
        [$company, $token] = $this->actingAsApproved([
            'allowed_sources' => 'local.ch',
            'plan' => 'premium',
            'plan_expires_at' => now()->addDay(),
            'daily_send_limit' => 0,
            'daily_sends_used' => 0,
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'secret',
            'smtp_enabled' => true,
        ]);
        $city = City::factory()->create(['name' => 'Zurich']);
        $cat = Category::factory()->create(['name' => 'Restaurants']);
        $batch = EmailBatch::create([
            'company_id' => $company->id,
            'category_id' => $cat->id,
            'city_id' => $city->id,
            'label' => 'Restaurants — Zurich',
            'batch_size' => 1,
            'price_paid' => 0,
            'purchased_at' => now(),
        ]);
        $business = Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id]);
        $batchEmail = BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $business->id]);

        $this->mock(EmailService::class, function ($mock) {
            $mock->shouldReceive('sendBatch')->once();
        });

        $this->withToken($token)->postJson('/dashboard/send-email', [
            'batch_email_ids' => [$batchEmail->id],
            'subject' => 'New send',
            'body' => '<p>Hello again</p>',
        ])->assertStatus(200)->assertJsonPath('queued', 1);

        $this->assertSame(0, $company->fresh()->daily_sends_used);
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
