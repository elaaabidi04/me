<?php

// MOCKUP NOTICE (2026-08-14): this project is an academic mockup — see
// docs/sujet-journal-ai (1).md — not a production deployment reaching
// real at-risk users. The "REQUIRED BEFORE ANY REAL USER SEES THIS APP"
// warning below still stands verbatim for that hypothetical future, but
// for THIS demo the crisis contact intentionally points at the
// developer's own name/number (set in .env, not committed — see
// .env.example) instead of a verified Tunisian national crisis line.
// The architectural rule this file exists to enforce is unchanged either
// way: the LLM never generates this text itself, only config does.
$contactName   = env('CRISIS_CONTACT_NAME', 'me');
$contactNumber = env('CRISIS_CONTACT_NUMBER');

return [
    /*
    |--------------------------------------------------------------------
    | Crisis resource message
    |--------------------------------------------------------------------
    | Appended after the coach's brief acknowledgment whenever crisis mode
    | fires. This is the ONLY place a crisis resource should ever come from
    | — never let the LLM generate a phone number, URL, or org name itself;
    | models hallucinate these, and a wrong number here is a real-world harm.
    |
    | REQUIRED BEFORE ANY REAL USER SEES THIS APP: replace the mockup
    | contact below with a verified Tunisian national crisis line plus an
    | international fallback. Do not ship a guessed number, and do not
    | ship the developer's personal number either — that's a demo-only
    | stand-in, not a crisis resource. See docs/coach-decision-spec.md §8.
    |
    | 2026-08-17: 'message' is shown DIRECTLY to a real person mid-crisis —
    | it must never contain developer-facing text under any configuration
    | state. It used to embed a "[PLACEHOLDER — set CRISIS_CONTACT_NUMBER
    | in your .env...]" reminder INSIDE the string itself when unconfigured,
    | which meant a real user could see raw setup instructions in place of
    | an emergency resource — worse than showing nothing. The developer
    | reminder now lives only here (a comment) and as a runtime log warning
    | (CoachService::crisisResourceMessage()) — never in the string itself.
    | Unconfigured now just quietly falls back to the one thing that's
    | always true regardless of setup: contact local emergency services.
    */
    'contact_name'   => $contactName,
    'contact_number' => $contactNumber,
    'configured'     => (bool) $contactNumber,

    'message' => env('CRISIS_RESOURCE_MESSAGE', $contactNumber
        ? "If you are in immediate danger, please contact local emergency services now. For this demo, you can also reach {$contactName} at {$contactNumber}."
        : 'If you are in immediate danger, please contact local emergency services now.'
    ),
];
