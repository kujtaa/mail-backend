<?php
use App\Models\UnsubscribedEmail;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('unsubscribed_emails', function (Blueprint $table) {
            if (!Schema::hasColumn('unsubscribed_emails', 'source')) {
                $table->string('source', 20)->default(UnsubscribedEmail::SOURCE_LINK)->after('token');
            }
            if (!Schema::hasColumn('unsubscribed_emails', 'added_by')) {
                $table->foreignId('added_by')->nullable()->after('source')
                    ->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('unsubscribed_emails', 'note')) {
                $table->string('note', 500)->nullable()->after('added_by');
            }
        });

        // Existing rows were written with whatever casing the link carried.
        // Lower-case them so the case-insensitive lookups can use the unique index.
        DB::table('unsubscribed_emails')->update(['email' => DB::raw('LOWER(email)')]);
    }

    public function down(): void
    {
        Schema::table('unsubscribed_emails', function (Blueprint $table) {
            if (Schema::hasColumn('unsubscribed_emails', 'added_by')) {
                $table->dropConstrainedForeignId('added_by');
            }
            foreach (['note', 'source'] as $col) {
                if (Schema::hasColumn('unsubscribed_emails', $col)) $table->dropColumn($col);
            }
        });
    }
};
