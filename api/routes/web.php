<?php

use Illuminate\Support\Facades\Route;

// This is a pure API backend — the SPA lives in the sibling `spa/` repo.
// This route just confirms the API is up when someone hits the root domain.
Route::get('/', function () {
    return response()->json([
        'name' => 'me API',
        'status' => 'ok',
    ]);
});
