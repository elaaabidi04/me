<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DailyIntentController extends Controller
{
    public function show(Request $request)
    {
        $intent = $request->user()
            ->dailyIntents()
            ->whereDate('date', Carbon::today())
            ->first();

        return response()->json($intent);
    }

    public function store(Request $request)
    {
        // Collapsed from 4 picks to 2 (2026-08-12) — 'light'/'rest' retired
        // from the picker; see CoachService::PICK_TO_MODE for why.
        $data = $request->validate([
            'pick' => ['required', 'string', 'in:engage,witness'],
        ]);

        $intent = DailyIntent::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'date'    => Carbon::today()->toDateString(),
            ],
            ['pick' => $data['pick']]
        );

        return response()->json($intent, $intent->wasRecentlyCreated ? 201 : 200);
    }
}
