<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NvidiaClient
{
    /**
     * @param array $messages OpenAI-style [{role, content}, ...]
     * @param array $options  Per-call overrides (e.g. ['temperature' => 0.85]).
     *                        Merged over the defaults below — callers that want
     *                        deterministic output (extraction) can leave this
     *                        empty; callers that want natural, varied replies
     *                        (the coach) should raise temperature explicitly.
     */
    public function chat(array $messages, array $options = []): ?string
    {
        // 'timeout' is an HTTP client setting, not an OpenAI-style body
        // param — pull it out before merging the rest of $options into the
        // request body, or it would get sent to NVIDIA as a bogus field.
        $timeout = $options['timeout'] ?? config('nvidia.timeout', 30);
        unset($options['timeout']);

        $payload = array_merge([
            'model'       => config('nvidia.model'),
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => 512,
            // 2026-08-15 — found via a real leaked reply, not assumed:
            // this app's configured model (nvidia/nemotron-3-super-
            // 120b-a12b) is a reasoning model that defaults to
            // enable_thinking=true (confirmed against NVIDIA's own
            // model docs, docs.api.nvidia.com), and for a plain
            // one-shot chat.completions call its chain-of-thought
            // trace lands directly in choices[0].message.content —
            // there is no separate reasoning field to filter out in
            // this non-streaming shape. A real reply showed exactly
            // this: third-person meta-commentary about "the user"
            // ("My job? Match that calm energy...", "Key
            // constraints:") that consumed the whole max_tokens
            // budget and cut off before the model ever produced the
            // actual 2-3 sentence answer. None of this app's callers
            // (coach, extraction, evaluator) want a reasoning trace —
            // extraction/evaluator need clean JSON, the coach needs a
            // short warm reply, not the model's internal planning for
            // one. Disabled by default; a future caller that
            // genuinely wants reasoning can still override this key
            // in $options like any other default here.
            'chat_template_kwargs' => ['enable_thinking' => false],
        ], $options);

        // Retry up to 3 attempts on 503 (transient server overload) —
        // 2026-08-26: first real instance observed in the log when the
        // chatbot called the API and NVIDIA returned "Service temporarily
        // overloaded". Every other error (4xx, other 5xx) is non-retryable
        // and returns null immediately. Backoff: 1s after attempt 1, 2s
        // after attempt 2.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::withToken(config('nvidia.api_key'))
                    ->timeout($timeout)
                    ->post(config('nvidia.endpoint'), $payload);

                if (!$response->successful()) {
                    if ($response->status() === 503 && $attempt < 3) {
                        sleep($attempt);
                        continue;
                    }
                    Log::error('NvidiaClient: non-200 response', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);
                    return null;
                }

                return $response->json('choices.0.message.content');
            } catch (\Throwable $e) {
                Log::error('NvidiaClient: request failed', ['error' => $e->getMessage()]);
                return null;
            }
        }

        return null;
    }

    /**
     * Embed a single string of text via NVIDIA's /v1/embeddings endpoint
     * (2026-08-12, for canon-event memory retrieval — see
     * CanonMemoryService and docs/ai-behavior.md §11). Separate endpoint
     * and separate model from chat() above; verified against NVIDIA NIM's
     * documented OpenAI-compatible embeddings shape (model/input/
     * input_type/truncate request, response at data.0.embedding) rather
     * than assumed.
     *
     * $inputType matters: NVIDIA's embedding models are asymmetric —
     * "passage" is for text being stored/indexed (canon-event descriptions),
     * "query" is for text used to search with (the current entry, when
     * looking for relevant canon events). Mixing these up doesn't error,
     * it just quietly makes retrieval worse, so callers must pass the
     * right one rather than relying on the default.
     *
     * Always best-effort: returns null on any failure (never throws) so a
     * caller can treat "no embedding" as "skip this enhancement" rather
     * than a hard failure — retrieval is a nice-to-have layered on top of
     * the coach reply, never something that should be able to break it.
     *
     * @return float[]|null
     */
    public function embed(string $text, string $inputType = 'passage'): ?array
    {
        try {
            $response = Http::withToken(config('nvidia.api_key'))
                ->timeout(config('nvidia.embedding_timeout', 15))
                ->post(config('nvidia.embedding_endpoint'), [
                    'model'      => config('nvidia.model_embedding'),
                    'input'      => [$text],
                    'input_type' => $inputType,
                    // Truncate rather than error on an oversized entry —
                    // this is a supporting feature, not something that
                    // should ever cause a hard failure over input length.
                    'truncate'   => 'END',
                ]);

            if (!$response->successful()) {
                Log::error('NvidiaClient: embedding non-200 response', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $vector = $response->json('data.0.embedding');

            return is_array($vector) ? $vector : null;
        } catch (\Throwable $e) {
            Log::error('NvidiaClient: embedding request failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
