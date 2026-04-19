<?php
namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): array
    {
        $admin = Company::factory()->admin()->create();
        return [$admin, $admin->createToken('t')->plainTextToken];
    }

    public function test_list_companies_requires_admin(): void
    {
        $company = Company::factory()->create();
        $token = $company->createToken('t')->plainTextToken;
        $this->withToken($token)->getJson('/admin/companies')->assertStatus(403);
    }

    public function test_list_companies_returns_all(): void
    {
        [$admin, $token] = $this->adminToken();
        Company::factory()->count(3)->create();

        $this->withToken($token)->getJson('/admin/companies')
             ->assertStatus(200)
             ->assertJsonCount(4); // 3 + admin
    }

    public function test_approve_company(): void
    {
        [$admin, $token] = $this->adminToken();
        $company = Company::factory()->unapproved()->create();

        $this->withToken($token)->postJson("/admin/approve/{$company->id}")
             ->assertStatus(200);

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'is_approved' => 1]);
    }

    public function test_add_credits(): void
    {
        [$admin, $token] = $this->adminToken();
        $company = Company::factory()->create(['credit_balance' => 10.0]);

        $this->withToken($token)->postJson('/admin/add-credits', [
            'company_id' => $company->id,
            'amount' => 50.0,
            'description' => 'Bonus credits',
        ])->assertStatus(200)->assertJsonPath('new_balance', 60.0);

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'credit_balance' => 60.0]);
    }

    public function test_set_plan(): void
    {
        [$admin, $token] = $this->adminToken();
        $company = Company::factory()->create();

        $this->withToken($token)->postJson('/admin/set-plan', [
            'company_id' => $company->id,
            'plan' => 'premium',
            'daily_limit' => 300,
            'days' => 30,
        ])->assertStatus(200);

        $this->assertDatabaseHas('companies', ['id' => $company->id, 'plan' => 'premium', 'daily_send_limit' => 300]);
    }

    public function test_delete_company_non_admin(): void
    {
        [$admin, $token] = $this->adminToken();
        $company = Company::factory()->create();

        $this->withToken($token)->deleteJson("/admin/companies/{$company->id}")
             ->assertStatus(200);

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_cannot_delete_admin(): void
    {
        [$admin, $token] = $this->adminToken();

        $this->withToken($token)->deleteJson("/admin/companies/{$admin->id}")
             ->assertStatus(400);
    }
}
