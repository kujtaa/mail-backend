<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sent_emails') && !Schema::hasColumn('sent_emails', 'error_message')) {
            Schema::table('sent_emails', function (Blueprint $table) {
                $table->text('error_message')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sent_emails') && Schema::hasColumn('sent_emails', 'error_message')) {
            Schema::table('sent_emails', function (Blueprint $table) {
                $table->dropColumn('error_message');
            });
        }
    }
};
