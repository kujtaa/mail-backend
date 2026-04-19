<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('sent_emails')) return;
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('batch_email_id')->constrained('batch_emails');
            $table->string('subject', 500)->nullable();
            $table->text('body')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('status', 20)->default('pending');
        });
    }
    public function down(): void { Schema::dropIfExists('sent_emails'); }
};
