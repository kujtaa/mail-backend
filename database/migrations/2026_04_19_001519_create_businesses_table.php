<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('businesses')) return;
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191)->index();
            $table->string('phone', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('address')->nullable();
            $table->string('website', 500)->nullable();
            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('category_id')->constrained('categories');
            $table->string('source', 50)->default('local.ch')->index();
            $table->index(['city_id', 'category_id', 'name']);
        });
    }
    public function down(): void { Schema::dropIfExists('businesses'); }
};
