<?php
namespace Tests\Feature;

use App\Models\BatchEmail;
use App\Models\Business;
use App\Models\City;
use App\Models\Category;
use App\Models\Company;
use App\Models\EmailBatch;
use App\Models\SentEmail;
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

    private function adminToken(): array
    {
        $admin = Company::factory()->admin()->create();
        return [$admin, $admin->createToken('t')->plainTextToken];
    }

    // ── Public unsubscribe link ─────────────────────────────────────────────

    public function test_valid_token_unsubscribes_email(): void
    {
        $token = $this->emailService->generateUnsubscribeToken('user@example.com');

        $this->getJson("/unsubscribe/{$token}")
             ->assertStatus(200)
             ->assertJsonPath('email', 'user@example.com');

        $this->assertDatabaseHas('unsubscribed_emails', [
            'email' => 'user@example.com', 'source' => UnsubscribedEmail::SOURCE_LINK,
        ]);
    }

    public function test_already_unsubscribed_returns_ok(): void
    {
        $token = $this->emailService->generateUnsubscribeToken('already@example.com');
        UnsubscribedEmail::create(['email' => 'already@example.com', 'token' => $token, 'unsubscribed_at' => now()]);

        $this->getJson("/unsubscribe/{$token}")
             ->assertStatus(200)
             ->assertJsonPath('email', 'already@example.com');

        $this->assertSame(1, UnsubscribedEmail::count());
    }

    public function test_invalid_token_returns_400(): void
    {
        $this->getJson('/unsubscribe/invalid.token')->assertStatus(400);
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

    public function test_email_is_stored_lowercase_and_matched_case_insensitively(): void
    {
        $token = $this->emailService->generateUnsubscribeToken('Mixed.Case@Example.COM');
        $this->getJson("/unsubscribe/{$token}")->assertStatus(200)
             ->assertJsonPath('email', 'mixed.case@example.com');

        $this->assertTrue(UnsubscribedEmail::contains('MIXED.CASE@example.com'));
        $this->assertFalse(UnsubscribedEmail::contains('other@example.com'));
    }

    // ── Permanence ──────────────────────────────────────────────────────────

    public function test_unsubscribed_rows_cannot_be_deleted(): void
    {
        $row = UnsubscribedEmail::create(['email' => 'keep@example.com', 'token' => 'tok-keep', 'unsubscribed_at' => now()]);

        $this->assertFalse($row->delete());
        $this->assertDatabaseHas('unsubscribed_emails', ['email' => 'keep@example.com']);
    }

    public function test_remove_endpoint_no_longer_exists(): void
    {
        [, $token] = $this->adminToken();
        $row = UnsubscribedEmail::create(['email' => 'gone@example.com', 'token' => 'tok-gone', 'unsubscribed_at' => now()]);

        $this->withToken($token)->deleteJson("/admin/unsubscribed/{$row->id}")->assertStatus(404);
        $this->assertDatabaseHas('unsubscribed_emails', ['email' => 'gone@example.com']);
    }

    // ── Admin: list & manual add ────────────────────────────────────────────

    public function test_list_requires_admin(): void
    {
        $company = Company::factory()->create();
        $token = $company->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/admin/unsubscribed')->assertStatus(403);
        $this->withToken($token)->postJson('/admin/unsubscribed', ['email' => 'x@example.com'])->assertStatus(403);
    }

    public function test_admin_can_add_single_email_manually(): void
    {
        [$admin, $token] = $this->adminToken();

        $this->withToken($token)->postJson('/admin/unsubscribed', [
            'email' => '  Manual@Example.com ', 'note' => 'asked by phone',
        ])->assertStatus(200)
          ->assertJsonPath('added.0', 'manual@example.com');

        $this->assertDatabaseHas('unsubscribed_emails', [
            'email' => 'manual@example.com',
            'source' => UnsubscribedEmail::SOURCE_MANUAL,
            'added_by' => $admin->id,
            'note' => 'asked by phone',
        ]);
    }

    public function test_admin_bulk_add_reports_added_existing_and_invalid(): void
    {
        [, $token] = $this->adminToken();
        UnsubscribedEmail::create(['email' => 'dup@example.com', 'token' => 'tok-dup', 'unsubscribed_at' => now()]);

        $this->withToken($token)->postJson('/admin/unsubscribed', [
            'emails' => ['new@example.com', 'DUP@example.com', 'not-an-email', 'new@example.com'],
        ])->assertStatus(200)
          ->assertJson([
              'added' => ['new@example.com'],
              'existing' => ['dup@example.com'],
              'invalid' => ['not-an-email'],
          ]);

        $this->assertSame(2, UnsubscribedEmail::count());
    }

    public function test_admin_add_with_only_invalid_emails_returns_422(): void
    {
        [, $token] = $this->adminToken();
        $this->withToken($token)->postJson('/admin/unsubscribed', ['emails' => ['nope']])->assertStatus(422);
    }

    public function test_list_returns_items_counts_and_source_filter(): void
    {
        [, $token] = $this->adminToken();
        UnsubscribedEmail::create(['email' => 'a@example.com', 'token' => 'ta', 'unsubscribed_at' => now(), 'source' => 'link']);
        UnsubscribedEmail::create(['email' => 'b@example.com', 'token' => 'tb', 'unsubscribed_at' => now(), 'source' => 'manual']);

        $this->withToken($token)->getJson('/admin/unsubscribed')
             ->assertStatus(200)
             ->assertJsonPath('total', 2)
             ->assertJsonPath('counts.link', 1)
             ->assertJsonPath('counts.manual', 1);

        $this->withToken($token)->getJson('/admin/unsubscribed?source=manual')
             ->assertJsonPath('total', 1)
             ->assertJsonPath('items.0.email', 'b@example.com');

        $this->withToken($token)->getJson('/admin/unsubscribed?search=A@EX')
             ->assertJsonPath('total', 1)
             ->assertJsonPath('items.0.email', 'a@example.com');
    }

    // ── Sending never reaches a suppressed address ──────────────────────────

    public function test_unsubscribed_business_is_hidden_from_browse_and_batches(): void
    {
        $company = Company::factory()->create(['allowed_sources' => 'local.ch']);
        $token = $company->createToken('t')->plainTextToken;
        $city = City::factory()->create();
        $cat = Category::factory()->create(['name' => 'Cat']);
        Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id, 'email' => 'Visible@example.com', 'source' => 'local.ch']);
        Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id, 'email' => 'Hidden@example.com', 'source' => 'local.ch']);
        UnsubscribedEmail::create(['email' => 'hidden@example.com', 'token' => 'th', 'unsubscribed_at' => now()]);

        $emails = collect($this->withToken($token)->getJson('/dashboard/browse-emails')->assertStatus(200)->json())
            ->pluck('email');
        $this->assertTrue($emails->contains('Visible@example.com'));
        $this->assertFalse($emails->contains('Hidden@example.com'));

        $this->withToken($token)->getJson('/dashboard/browse-overview')->assertJsonPath('total_available', 1);
    }

    public function test_new_batch_never_contains_an_unsubscribed_address(): void
    {
        $company = Company::factory()->create(['allowed_sources' => 'local.ch']);
        $token = $company->createToken('t')->plainTextToken;
        $city = City::factory()->create(['name' => 'Zurich']);
        $cat = Category::factory()->create(['name' => 'Restaurants']);
        $ok = Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id, 'email' => 'ok@example.com', 'source' => 'local.ch']);
        $gone = Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id, 'email' => 'Gone@Example.com', 'source' => 'local.ch']);
        UnsubscribedEmail::create(['email' => 'gone@example.com', 'token' => 'tg', 'unsubscribed_at' => now()]);

        $this->withToken($token)->postJson('/dashboard/purchase-batch-multi', [
            'categories' => ['Restaurants'], 'city' => 'Zurich',
        ])->assertStatus(200)->assertJsonPath('batch_size', 1);

        $this->assertDatabaseHas('batch_emails', ['business_id' => $ok->id]);
        $this->assertDatabaseMissing('batch_emails', ['business_id' => $gone->id]);
    }

    public function test_existing_batch_hides_and_never_queues_an_address_unsubscribed_later(): void
    {
        $company = Company::factory()->create([
            'allowed_sources' => 'local.ch',
            'smtp_host' => 'smtp.example.com', 'smtp_user' => 'u', 'smtp_pass' => 'p', 'smtp_enabled' => true,
        ]);
        $token = $company->createToken('t')->plainTextToken;
        $city = City::factory()->create();
        $cat = Category::factory()->create();
        $batch = EmailBatch::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'city_id' => $city->id,
            'label' => 'old batch', 'batch_size' => 2, 'price_paid' => 0, 'purchased_at' => now(),
        ]);
        $ok = Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id, 'email' => 'ok@example.com', 'source' => 'local.ch']);
        $gone = Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id, 'email' => 'Gone@Example.com', 'source' => 'local.ch']);
        $okRow = BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $ok->id]);
        $goneRow = BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $gone->id]);

        // Unsubscribe AFTER the batch was created.
        $link = $this->emailService->generateUnsubscribeToken('gone@example.com');
        $this->getJson("/unsubscribe/{$link}")->assertStatus(200);

        // The Send page's recipient list no longer shows the address.
        $listed = collect($this->withToken($token)->getJson("/dashboard/my-batches/{$batch->id}/emails")->assertStatus(200)->json())
            ->pluck('id');
        $this->assertTrue($listed->contains($okRow->id));
        $this->assertFalse($listed->contains($goneRow->id));

        // Even if the id is forced into the request, it is not queued.
        $this->withToken($token)->postJson('/dashboard/send-email', [
            'batch_email_ids' => [$okRow->id, $goneRow->id], 'subject' => 's', 'body' => 'b',
        ])->assertStatus(200)
          ->assertJsonPath('queued', 1)
          ->assertJsonPath('skipped_unsubscribed', 1);

        $this->assertDatabaseHas('sent_emails', ['batch_email_id' => $okRow->id, 'status' => 'pending']);
        $this->assertDatabaseMissing('sent_emails', ['batch_email_id' => $goneRow->id]);

        // Selecting only the unsubscribed address is rejected outright.
        $this->withToken($token)->postJson('/dashboard/send-email', [
            'batch_email_ids' => [$goneRow->id], 'subject' => 's', 'body' => 'b',
        ])->assertStatus(400);
    }

    public function test_unsubscribing_cancels_emails_already_waiting_in_the_queue(): void
    {
        $company = Company::factory()->create();
        $city = City::factory()->create();
        $cat = Category::factory()->create();
        $batch = EmailBatch::create([
            'company_id' => $company->id, 'category_id' => $cat->id, 'city_id' => $city->id,
            'label' => 'b', 'batch_size' => 1, 'price_paid' => 0, 'purchased_at' => now(),
        ]);
        $biz = Business::factory()->create(['city_id' => $city->id, 'category_id' => $cat->id, 'email' => 'Queued@Example.com']);
        $row = BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $biz->id]);
        $pending = SentEmail::create(['company_id' => $company->id, 'batch_email_id' => $row->id, 'subject' => 's', 'body' => 'b', 'status' => 'pending']);
        $sent = SentEmail::create(['company_id' => $company->id, 'batch_email_id' => $row->id, 'subject' => 's', 'body' => 'b', 'status' => 'sent', 'sent_at' => now()]);

        [, $token] = $this->adminToken();
        $this->withToken($token)->postJson('/admin/unsubscribed', ['email' => 'queued@example.com'])->assertStatus(200);

        $this->assertSame('unsubscribed', $pending->fresh()->status);
        $this->assertSame('sent', $sent->fresh()->status);
    }

    public function test_manual_send_skips_unsubscribed_recipient(): void
    {
        $company = Company::factory()->create([
            'smtp_host' => 'smtp.example.com', 'smtp_user' => 'u', 'smtp_pass' => 'p', 'smtp_enabled' => true,
        ]);
        $token = $company->createToken('t')->plainTextToken;
        UnsubscribedEmail::create(['email' => 'stop@example.com', 'token' => 'ts', 'unsubscribed_at' => now()]);

        $this->mock(EmailService::class, function ($mock) {
            $mock->shouldReceive('buildUnsubscribeUrl')->andReturn('http://x/unsubscribe/t');
            $mock->shouldReceive('sendSingle')->never();
        });

        $res = $this->withToken($token)->postJson('/dashboard/send-manual', [
            'emails' => ['STOP@example.com'], 'subject' => 's', 'body' => 'b',
        ])->assertStatus(200);

        $this->assertSame('unsubscribed', $res->json('results.0.status'));
    }
}
