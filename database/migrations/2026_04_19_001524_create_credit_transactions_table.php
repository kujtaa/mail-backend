<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies');
            $table->float('amount');
            $table->string('type', 20);
            $table->text('description')->nullable();
            $table->dateTime('created_at')->nullable()->useCurrent();
        });
    }
    public function down(): void { Schema::dropIfExists('credit_transactions'); }
};
