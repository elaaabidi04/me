<?php

use Illuminate\Support\Facades\Route;

// Locally the SPA runs on its own (npm run dev in spa/). In production
// (2026-11-10, see docs/deploy.md) the Docker build copies the built SPA
// into public/ as spa.html + assets/, and Laravel serves it from the same
// address as the API -- so the login cookie is first-party everywhere.
$spa = public_path('spa.html');

if (is_file($spa)) {
    // any non-API URL (/, /dashboard, /journal, ...) gets the Vue app;
    // vue-router takes it from there
    Route::fallback(function () use ($spa) {
        abort_if(request()->is('api/*', 'sanctum/*'), 404);   // unknown API URL: a real 404, not the app

        return response()->file($spa, ['Cache-Control' => 'no-cache']);
    });
} else {
    Route::get('/', fn () => response()->json(['name' => 'me API', 'status' => 'ok']));
}
