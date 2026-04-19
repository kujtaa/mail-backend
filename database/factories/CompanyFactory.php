<?php
namespace Database\Factories;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class CompanyFactory extends Factory {
    protected $model = Company::class;

    public function definition(): array {
        return [
            'name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'hashed_password' => Hash::make('password'),
            'credit_balance' => 0.0,
            'is_admin' => false,
            'is_approved' => true,
            'plan' => 'free',
            'daily_send_limit' => 0,
            'daily_sends_used' => 0,
            'smtp_enabled' => false,
        ];
    }

    public function admin(): static {
        return $this->state(['is_admin' => true, 'is_approved' => true]);
    }

    public function unapproved(): static {
        return $this->state(['is_approved' => false]);
    }

    public function premium(int $dailyLimit = 200, int $days = 30): static {
        return $this->state([
            'plan' => 'premium',
            'plan_expires_at' => now()->addDays($days),
            'daily_send_limit' => $dailyLimit,
        ]);
    }

    public function withSmtp(): static {
        return $this->state([
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'secret',
            'smtp_from_email' => 'user@example.com',
            'smtp_from_name' => 'Test',
            'smtp_enabled' => true,
        ]);
    }
}
