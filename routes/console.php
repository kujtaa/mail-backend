<?php

use App\Jobs\SendQueuedEmail;
use App\Models\SentEmail;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('emails:process-pending {--delay=10 : Seconds to wait between each email}', function () {
    $delay = (int) $this->option('delay');
    $processed = 0;
    $failed = 0;

    $this->info('Processing pending emails...');

    while (true) {
        $record = SentEmail::where('status', 'pending')->orderBy('id')->first();

        if (!$record) {
            $this->info("Done. Sent: {$processed}, Failed: {$failed}");
            return;
        }

        app()->call([new SendQueuedEmail($record->id), 'handle']);
        $record->refresh();

        if ($record->status === 'sent') {
            $processed++;
            $this->line("[{$processed}] Sent → {$record->id}");
        } else {
            $failed++;
            $this->warn("[failed] {$record->id}: {$record->error_message}");
        }

        $remaining = SentEmail::where('status', 'pending')->count();
        $this->line("  {$remaining} remaining");

        if ($remaining > 0) {
            sleep($delay);
        }
    }
})->purpose('Send all pending queued emails one by one with a delay between each');
