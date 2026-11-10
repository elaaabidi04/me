<?php

namespace App\Support;

use App\Mail\InjectionAttemptMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 2026-11-07: hard block for clear prompt-injection attempts.
 *
 * CoachService::looksLikeInjection() is a broad, soft net: it only adds a
 * reinforcement wrapper and still lets the model answer. This guard is the
 * strict layer in front of it: text that unmistakably tries to take over
 * the AI never reaches any model at all, gets a fixed reply instead, and
 * the owner (ADMIN_EMAIL) is emailed.
 *
 * Precision over recall, on purpose: a false positive refuses a real
 * journal entry, so every rule here targets wording that only makes sense
 * as an attack ("ignore your instructions", forged trust tags, "system
 * prompt", jailbreak names) -- never normal feelings language like
 * "ignore my inner critic" or "I feel like ignoring everyone". Anything
 * subtler is still caught by the soft layer + the system prompt + the
 * reply leak filter.
 *
 * Crisis always wins: callers must check crisis language FIRST and never
 * block an entry that also reads as a crisis (EntryController does).
 */
class InjectionGuard
{
    /** rule label => pattern (all case-insensitive, unicode) */
    private const RULES = [
        'forged trust tag'        => '/\[\s*\/?\s*(TRUSTED_SYSTEM|UNTRUSTED_[A-Z_]+)\s*\]/iu',
        // instructions / prompt: any determiner is suspicious enough
        'ignore instructions'     => '/\b(ignore|disregard|forget|override|bypass)\s+(all\s+)?(of\s+)?(the\s+|your\s+|these\s+|those\s+)?(previous\s+|prior\s+|above\s+|earlier\s+|system\s+)?(instructions|prompt)\b(?!\s+(of|on|for|from)\b)/iu',
        // rules / guidelines: only when clearly the AI's ("your rules", "all previous rules"), never "the rules of dating"
        'ignore your rules'       => '/\b(ignore|disregard|forget|override|bypass)\s+(all\s+(of\s+)?)?(your\s+|(the\s+)?(previous|prior|system|above)\s+)(rules|guidelines|guardrails|restrictions)\b(?!\s+(of|on|for|at)\b)/iu',
        'system prompt request'   => '/\b(system|hidden|internal|initial|original)\s+(prompt|instructions?|message)\b/iu',
        'reveal instructions'     => '/\b(print|show|reveal|repeat|output|dump|leak|tell me)\b.{0,25}\b(your|the)\s+(instructions?|rules|prompt|guidelines)\b/iu',
        'repeat text above'       => '/\brepeat\s+(everything|all|the\s+text|the\s+words)\s+(above|before)\b/iu',
        'instructions overridden' => '/\b(previous|prior|your|old|above)\s+instructions\s+(are|were)\s+(obsolete|void|cancelled|canceled|outdated|no\s+longer)/iu',
        'fake system update'      => '/^\s*(system\s+(update|override|message)|new\s+instructions)\s*:/imu',
        'jailbreak persona'       => '/\b(jailbreak|DAN\s+mode|developer\s+mode|god\s+mode|you\s+are\s+(now\s+)?DAN)\b/iu',
        'telling the AI to drop rules' => '/\byou\s+(can|may|should|must|are\s+allowed\s+to)\s+(now\s+)?(drop|ignore|turn\s+off|disable|stop\s+following|forget)\s+(all\s+(of\s+)?)?(your|the|these|those)\s+(rules|instructions|restrictions|filters?|guidelines|guardrails)\b(?!\s+(of|on|for|at|that|which|who)\b)/iu',
        'fake developer'          => '/\b(i\'?m|i\s+am|this\s+is)\s+(the|your|a)\s+(developer|admin|administrator|creator|owner)\b.{0,60}\b(rules|instructions|restrictions|filters?)\b/iu',
        // French
        'fr: ignore instructions' => '/\b(oublie|ignore|ignorez|oubliez)\s+(toutes\s+)?(tes|les|vos|ces)\s+(instructions|règles|regles|consignes)\b/iu',
        'fr: prompt request'      => '/\b(ton|votre|le)\s+prompt(\s+syst[eè]me)?\b/iu',
        // Tunisian Darija (Latin script)
        'darija: ignore rules'    => '/\b(ensa|insa|nsa|n7i|na7i)\s+(el\s*|l\s*)?(instructions?|rules|prompt|9awa3ed|qawa3ed)\b/iu',
        'darija: your prompt'     => '/\b(prompt|instructions?)\s+(mte3ek|mta3ek|mtaak|mte3k|mtek)\b/iu',
    ];

    /** The rule that fired, or null if the text is fine. */
    public static function match(string $text): ?string
    {
        foreach (self::RULES as $label => $pattern) {
            if (preg_match($pattern, $text)) {
                return $label;
            }
        }

        return null;
    }

    /** Fixed reply shown instead of an AI answer. */
    public static function reply(?User $user): string
    {
        $base = "That one looked like an attempt to change how I work, so I didn't pass it on — I only answer journal writing. If that wasn't what you meant, try saying it a different way 💛";

        return $user?->is_demo
            ? $base."\n\n(Demo note: attempts like this are logged and visible to the developer.)"
            : $base;
    }

    /**
     * Log + email the owner. Demo sandboxes include the text (visitors are
     * told everything they write is visible); real accounts get an alert
     * WITHOUT the text -- a real user's journal stays private even here.
     * At most one email per account per cooldown window; every attempt is
     * still logged and shows up on /admin.
     */
    public static function report(User $user, string $text, string $rule, string $where): void
    {
        Log::warning('InjectionGuard: blocked a prompt-injection attempt', [
            'user_id' => $user->id, 'rule' => $rule, 'where' => $where, 'demo' => (bool) $user->is_demo,
        ]);

        $to = config('demo.admin_email');
        if (! $to) {
            return;
        }

        $cooldown = (int) config('demo.injection_mail_cooldown_minutes', 10) * 60;
        RateLimiter::attempt('injection-mail:'.$user->id, 1, function () use ($to, $user, $text, $rule, $where) {
            try {
                Mail::to($to)->queue(new InjectionAttemptMail(
                    account: $user->is_demo
                        ? 'demo visitor '.substr((string) DemoSandbox::tokenOf($user->email), 0, 6).' ('.$user->name.')'
                        : 'real account #'.$user->id,
                    where: $where,
                    rule: $rule,
                    text: $user->is_demo ? mb_substr($text, 0, 2000) : null,
                    at: now()->toDayDateTimeString(),
                ));
            } catch (\Throwable $e) {
                Log::error('InjectionGuard: could not queue the alert email', ['error' => $e->getMessage()]);
            }
        }, $cooldown);
    }
}
