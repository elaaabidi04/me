<?php

namespace App\Jobs;

use App\Models\Entry;
use App\Services\CoachService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateCoachMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 90;

    public function __construct(public Entry $entry) {}

    public function handle(CoachService $coach): void
    {
        $fresh = $this->entry->fresh();

        if ($fresh->in_vault) {
            return;
        }

        // Guard against duplicate dispatch (e.g. job retried after a partial run).
        if ($fresh->coachMessage()->exists()) {
            return;
        }

        // CoachService::generate() now throws on every failure path (LLM
        // call failure, sanitizer discard, DB save failure — see its
        // docblock) instead of always returning a CoachMessage, which is
        // what makes tries=2 above a REAL retry now rather than a number
        // that was always silently ignored (generate() previously created
        // a fallback message on the very first failure, which counts as
        // job success, so a second attempt never ran). Only fall back once
        // this was genuinely the last allowed attempt; otherwise rethrow so
        // Laravel's queue does the retry.
        try {
            $coach->generate($fresh);
        } catch (\Throwable $e) {
            if ($this->attempts() >= $this->tries) {
                Log::warning('GenerateCoachMessageJob: retries exhausted, falling back to a canned reply', [
                    'entry_id' => $fresh->id,
                    'attempts' => $this->attempts(),
                    'error'    => $e->getMessage(),
                ]);
                $coach->fallbackFor($fresh, 'llm_retries_exhausted: '.$e->getMessage());
                return;
            }

            throw $e;
        }
    }
}
