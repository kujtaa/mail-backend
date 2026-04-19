<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class Company extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'companies';
    protected $authPasswordName = 'hashed_password';

    protected $fillable = [
        'name', 'email', 'hashed_password', 'credit_balance',
        'is_admin', 'is_approved', 'plan', 'plan_expires_at',
        'daily_send_limit', 'daily_sends_used', 'daily_sends_reset_at',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass',
        'smtp_from_email', 'smtp_from_name', 'smtp_enabled',
        'email_signature', 'allowed_sources',
    ];

    protected $hidden = ['hashed_password'];

    protected $casts = [
        'credit_balance' => 'float',
        'is_admin' => 'boolean',
        'is_approved' => 'boolean',
        'smtp_enabled' => 'boolean',
        'plan_expires_at' => 'datetime',
        'daily_sends_reset_at' => 'datetime',
    ];

    public function getAllowedSources(): array
    {
        if (!$this->allowed_sources) return [];
        return array_values(array_filter(array_map('trim', explode(',', $this->allowed_sources))));
    }

    public function setAllowedSources(array $sources): void
    {
        $this->allowed_sources = empty($sources) ? null : implode(',', $sources);
    }

    public function emailBatches() { return $this->hasMany(EmailBatch::class); }
    public function sentEmails() { return $this->hasMany(SentEmail::class); }
    public function creditTransactions() { return $this->hasMany(CreditTransaction::class); }
}
