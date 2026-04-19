<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('companies')) return;
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('email', 255)->unique()->index();
            $table->string('hashed_password', 255);
            $table->float('credit_balance')->default(0.0);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_approved')->default(false);
            $table->string('plan', 20)->default('free');
            $table->dateTime('plan_expires_at')->nullable();
            $table->integer('daily_send_limit')->default(0);
            $table->integer('daily_sends_used')->default(0);
            $table->dateTime('daily_sends_reset_at')->nullable();
            $table->string('smtp_host', 255)->nullable();
            $table->integer('smtp_port')->nullable();
            $table->string('smtp_user', 255)->nullable();
            $table->string('smtp_pass', 500)->nullable();
            $table->string('smtp_from_email', 255)->nullable();
            $table->string('smtp_from_name', 255)->nullable();
            $table->boolean('smtp_enabled')->default(false);
            $table->text('email_signature')->nullable();
            $table->text('allowed_sources')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('companies'); }
};
