<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 2026-10-15: public demo guard on routes that call the LLM (see
        // config/demo.php). Demo accounts are shared by every visitor, so
        // the limit is keyed by IP, not user id. Real accounts are
        // unaffected (they keep only their existing per-route throttles).
        RateLimiter::for('demo-ai', function (Request $request) {
            if (! $request->user()?->is_demo || \App\Support\DemoSandbox::unlimited($request)) {
                return Limit::none();   // real accounts, and the admin's own test sessions
            }

            // 2026-11-08: the cap is now PER DEMO COPY (each visitor's own
            // sandbox account), not per network -- a whole class on one
            // school Wi-Fi used to share 15/hour between them. A much higher
            // per-network ceiling stays as protection against someone
            // scripting new sandboxes to farm the API key.
            $tooMany = fn () => response()->json([
                'message' => "You've hit the demo's hourly limit for AI features. The seeded data is all still there to explore.",
            ], 429);

            return [
                Limit::perHour(config('demo.ai_requests_per_hour', 15))
                    ->by('demo-ai:user:'.$request->user()->id)->response($tooMany),
                Limit::perHour(config('demo.ai_requests_per_hour_per_network', 300))
                    ->by('demo-ai:ip:'.$request->ip())->response($tooMany),
            ];
        });

        // One-click demo login. 2026-11-08: 10/min per IP locked out a
        // classroom clicking "test user" at the same moment; 60/min still
        // stops scripted hammering.
        RateLimiter::for('demo-login', fn (Request $request) => Limit::perMinute(config('demo.logins_per_minute_per_network', 60))->by($request->ip()));
    }
}
