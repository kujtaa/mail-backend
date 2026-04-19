<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('batch_emails')) return;
        Schema::create('batch_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('email_batches')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses');
        });
    }
    public function down(): void { Schema::dropIfExists('batch_emails'); }
};
