<?php
namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_token(): void
    {
        $response = $this->postJson('/auth/register', [
            'company_name' => 'Acme Corp',
            'email' => 'acme@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['access_token', 'token_type']);

        $this->assertDatabaseHas('companies', ['email' => 'acme@example.com']);
    }

    public function test_first_registration_is_admin_and_approved(): void
    {
        $this->postJson('/auth/register', [
            'company_name' => 'First',
            'email' => 'first@example.com',
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('companies', [
            'email' => 'first@example.com',
            'is_admin' => 1,
            'is_approved' => 1,
        ]);
    }

    public function test_duplicate_email_returns_400(): void
    {
        Company::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/auth/register', [
            'company_name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'password',
        ])->assertStatus(400)->assertJsonPath('detail', 'Email already registered');
    }

    public function test_login_returns_token_and_company(): void
    {
        $company = Company::factory()->create(['hashed_password' => Hash::make('secret')]);

        $this->postJson('/auth/login', [
            'email' => $company->email,
            'password' => 'secret',
        ])->assertStatus(200)
          ->assertJsonStructure(['access_token', 'token_type', 'company']);
    }

    public function test_login_wrong_password_returns_401(): void
    {
        $company = Company::factory()->create(['hashed_password' => Hash::make('secret')]);

        $this->postJson('/auth/login', [
            'email' => $company->email,
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    public function test_me_returns_profile(): void
    {
        $company = Company::factory()->create();
        $token = $company->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/auth/me')
             ->assertStatus(200)
             ->assertJsonPath('email', $company->email);
    }

    public function test_me_requires_auth(): void
    {
        $this->getJson('/auth/me')->assertStatus(401);
    }
}
