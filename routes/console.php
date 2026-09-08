<?php

use App\Services\AbandonedQuotaReconciler;
use App\Services\CallContentRetentionService;
use App\Services\PlatformContentKeyProvider;
use App\Services\TelemetryRollupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('queue:prune-failed --hours=168')->daily();
Artisan::command('quota:reconcile-abandoned', function () {
    $count = app(AbandonedQuotaReconciler::class)
        ->reconcile((int) config('quota.recovery_batch_size'));
    $this->info("Reconciled {$count} abandoned quota reservations.");
})->purpose('Conservatively reconcile abandoned gateway quota reservations');
Schedule::command('quota:reconcile-abandoned')->everyMinute()->withoutOverlapping();

/*
 | Telemetry rollups feed dashboard aggregates so summaries never scan the raw
 | attempt table. The job recomputes a bounded lookback window, which makes it
 | idempotent and tolerant of streaming calls that finish after midnight UTC.
 */
Artisan::command('telemetry:rollup', function () {
    $rows = app(TelemetryRollupService::class)->rebuild();
    $this->info("Rebuilt {$rows} telemetry rollup rows.");
})->purpose('Rebuild daily gateway usage rollups for the configured lookback window');
Schedule::command('telemetry:rollup')->hourly()->withoutOverlapping();

/*
 | Content key material lives outside APP_KEY so that application key rotation
 | never destroys retained content. This helper only prints a candidate key; it
 | must be stored in the platform secret store, not in source control.
 */
Artisan::command('telemetry:content-key-generate', function () {
    $this->line(PlatformContentKeyProvider::generateKey());
    $this->comment('Add this to BCAIGW_CONTENT_KEYRING as {"<kid>":"<key>"} and set BCAIGW_CONTENT_KEY_ID=<kid>.');
})->purpose('Generate a base64 AES-256 content encryption key');

/*
 | Rotation rewraps per-record data keys under the currently active master key.
 | Payload ciphertext is never decrypted to disk and plaintext never leaves the
 | process.
 */
Artisan::command('telemetry:content-key-rotate {--limit=500}', function () {
    $rotated = app(CallContentRetentionService::class)->rotate((int) $this->option('limit'));
    $this->info("Rewrapped {$rotated} content records under the active key.");
})->purpose('Rewrap retained content data keys under the active content key');
