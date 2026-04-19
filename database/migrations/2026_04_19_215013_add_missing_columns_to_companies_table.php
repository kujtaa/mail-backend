<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'email_signature')) {
                $table->text('email_signature')->nullable();
            }
            if (!Schema::hasColumn('companies', 'allowed_sources')) {
                $table->text('allowed_sources')->nullable();
            }
            if (!Schema::hasColumn('companies', 'plan')) {
                $table->string('plan', 20)->default('free');
            }
            if (!Schema::hasColumn('companies', 'plan_expires_at')) {
                $table->dateTime('plan_expires_at')->nullable();
            }
            if (!Schema::hasColumn('companies', 'daily_send_limit')) {
                $table->integer('daily_send_limit')->default(0);
            }
            if (!Schema::hasColumn('companies', 'daily_sends_used')) {
                $table->integer('daily_sends_used')->default(0);
            }
            if (!Schema::hasColumn('companies', 'daily_sends_reset_at')) {
                $table->dateTime('daily_sends_reset_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['email_signature', 'allowed_sources', 'plan', 'plan_expires_at',
                      'daily_send_limit', 'daily_sends_used', 'daily_sends_reset_at'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
