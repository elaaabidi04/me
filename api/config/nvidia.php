<?php

// 2026-10-25: defaults moved off retired models (llama-3.1-70b, nemotron-3-super,
// llama-3.2-nv-embedqa-1b-v2 all return 404/410 now). NVIDIA's free tier retires
// models often -- if replies stop, check storage/logs/laravel.log for
// "NvidiaClient: non-200" and test candidates from GET /v1/models.
return [
    'endpoint' => 'https://integrate.api.nvidia.com/v1/chat/completions',
    'api_key'  => env('NVIDIA_API_KEY'),
    'model'    => env('NVIDIA_MODEL', 'nvidia/nemotron-3.5-lightning-30b-a3b'),
    'timeout'  => 30,

    // Same provider, same key — just two model names, since extraction
    // (strict JSON, low creativity needed) and the coach (tone/nuance is
    // the whole point) have different requirements. Both fall back to
    // NVIDIA_MODEL above until you set these explicitly.
    'model_extraction' => env('NVIDIA_MODEL_EXTRACTION', env('NVIDIA_MODEL', 'nvidia/nemotron-3.5-lightning-30b-a3b')),
    'model_coach'       => env('NVIDIA_MODEL_COACH', env('NVIDIA_MODEL', 'nvidia/nemotron-3.5-lightning-30b-a3b')),

    // Canon-event memory retrieval (2026-08-12, see NvidiaClient::embed()
    // and docs/ai-behavior.md §11). A separate endpoint on the same NVIDIA
    // NIM catalog, not the chat-completions one above -- OpenAI-compatible
    // /v1/embeddings shape (model, input, input_type, truncate). Default
    // model is NVIDIA's own text-embedding NIM; override via env if the
    // account this runs against has a different one provisioned.
    'embedding_endpoint' => 'https://integrate.api.nvidia.com/v1/embeddings',
    // 2026-08-26: nv-embedqa-e5-v5 reached EOL on 2026-08-25 (confirmed
    // via 410 response in the log). Replaced with its direct successor.
    'model_embedding'    => env('NVIDIA_MODEL_EMBEDDING', 'nvidia/nemotron-3-embed-1b'),
    // Shorter than the coach/extraction timeouts on purpose -- embedding a
    // single entry or canon-event description is a much smaller request
    // than a chat completion, and this call is best-effort (see embed()),
    // so it shouldn't be allowed to eat much of the coach job's budget.
    'embedding_timeout'  => 15,
];
