<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('email_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
            $table->string('label', 500)->nullable();
            $table->integer('batch_size');
            $table->float('price_paid');
            $table->dateTime('purchased_at')->nullable()->useCurrent();
        });
    }
    public function down(): void { Schema::dropIfExists('email_batches'); }
};
