<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-18, "share to therapist" — see docs/therapist-bridge.md §1 and
 * docs/dashboard-logic.md. This app already had an unused, empty
 * `shares` table (2026-08-04, day one) built exactly for this: per-item
 * consent via a polymorphic `shareable` morph + revocable granted_at/
 * revoked_at. Rather than inventing a parallel sharing mechanism, this
 * migration adds the one piece that didn't exist yet — something for a
 * `Share` row to actually point AT. A dashboard "panel" (feelings that
 * showed up, what's been weighing on you, etc.) isn't a stored Eloquent
 * entity, it's a computed aggregate recomputed live every time — so
 * sharing it means freezing a copy of what was on screen at share-time,
 * not creating a live/revocable link into the user's raw entries. That
 * frozen copy is `InsightSnapshot`; a `Share` row with
 * shareable_type=InsightSnapshot then grants/revokes visibility to it,
 * same mechanism as any other future shareable type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Which panel this came from, e.g. 'emotion_breakdown' (weekly)
            // or 'emotion_trends' (monthly) -- matches the *_breakdown /
            // *_trends keys the dashboards already use, so nothing new to
            // invent on the frontend for "which panel is this."
            $table->string('panel_key');
            $table->string('scope'); // 'week' | 'month'
            $table->string('title'); // e.g. "the feelings that showed up"
            $table->string('period_label'); // e.g. "week of Aug 11" / "August 2026"

            // Optional one-line summary shown above the rows (e.g. the mood
            // ring's caption sentence) -- most panels leave this null and
            // rely on rows alone.
            $table->text('caption')->nullable();

            // The frozen {label, detail}[] pairs actually shown at
            // share-time -- deliberately generic (not a foreign key into
            // entry_analyses) so one shape covers every panel type (bar
            // rows, pie slices, trend lines, the mood ring) without
            // coupling this table to any one panel's internal data shape.
            $table->json('rows');

            // Optional note the client can attach when sharing, separate
            // from the panel data itself.
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_snapshots');
    }
};
