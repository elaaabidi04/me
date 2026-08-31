<?php

namespace App\Jobs;

use App\Models\Entry;
use App\Services\EntryExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractEntryJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    // 50s HTTP timeout (EntryExtractionService) plus overhead needs more
    // than the previous 60s budget to leave room for an actual attempt at
    // both tries rather than getting killed mid-first-attempt.
    public int $timeout = 120;

    public function __construct(public Entry $entry) {}

    public function handle(EntryExtractionService $service): void
    {
        // Re-check at job-run time: user may have vaulted the entry after creation.
        $fresh = $this->entry->fresh();
        if ($fresh->in_vault) {
            return;
        }

        // Only fall back once this was genuinely the last allowed attempt;
        // otherwise rethrow so Laravel's queue does the retry (2026-08-14 —
        // same pattern as GenerateCoachMessageJob, added after a real
        // adversarial-content entry exhausted all 3 tries and was left with
        // no EntryAnalysis row at all, invisible everywhere including the
        // memory pipeline — see EntryExtractionService::fallbackFor()).
        try {
            $service->extract($fresh);
        } catch (\Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                Log::warning('ExtractEntryJob: retries exhausted, recording a minimal not-analyzed row', [
                    'entry_id' => $fresh->id,
                    'attempts' => $this->attempts(),
                    'error'    => $e->getMessage(),
                ]);
                $service->fallbackFor($fresh, 'extraction_retries_exhausted: '.$e->getMessage());
                return;
            }

            throw $e;
        }
    }
}
