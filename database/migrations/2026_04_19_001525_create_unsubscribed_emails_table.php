<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('unsubscribed_emails')) return;
        Schema::create('unsubscribed_emails', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique()->index();
            $table->foreignId('business_id')->nullable()->constrained('businesses')->nullOnDelete();
            $table->dateTime('unsubscribed_at')->nullable()->useCurrent();
            $table->string('token', 255)->unique()->index();
        });
    }
    public function down(): void { Schema::dropIfExists('unsubscribed_emails'); }
};
