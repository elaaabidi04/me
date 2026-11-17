<?php
namespace Tests\Feature;

use App\Models\CanonEvent;
use App\Models\Entity;
use App\Models\Entry;
use App\Models\Strategy;
use App\Support\DemoSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-11-17: one visitor's demo copy must be unreachable from another's,
 * through every API route that takes an ID. See docs/privacy-by-design.md.
 * Runs on the throwaway in-memory database from phpunit.xml.
 */
class SandboxIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_visitor_cannot_reach_another_visitors_copy(): void
    {
        $a = 'isoa'.substr(md5(uniqid()), 0, 8);
        $b = 'isob'.substr(md5(uniqid()), 0, 8);
        DemoSandbox::create($a);
        DemoSandbox::create($b);
        $aClient = DemoSandbox::find($a, 'client');
        $bClient = DemoSandbox::find($b, 'client');
        $bTher   = DemoSandbox::find($b, 'therapist');

        $entry    = Entry::where('user_id', $aClient->id)->latest()->first();
        $entity   = Entity::where('user_id', $aClient->id)->first();
        $canon    = CanonEvent::where('user_id', $aClient->id)->first();
        $strategy = Strategy::where('user_id', $aClient->id)->first();

        $results = [];
        $try = function (string $who, $user, string $method, string $uri, array $body = []) use (&$results) {
            $res = $this->actingAs($user, 'sanctum')->json($method, $uri, $body);
            $results[] = [$who, "$method $uri", $res->status(), $res->getContent()];
        };

        // B's client poking at A's things by id
        $try('B client', $bClient, 'GET', "/api/entries/{$entry->id}");
        if ($entity)   $try('B client', $bClient, 'GET',   "/api/entities/{$entity->id}");
        if ($entity)   $try('B client', $bClient, 'PATCH', "/api/entities/{$entity->id}", ['category' => 'people']);
        if ($canon)    $try('B client', $bClient, 'PATCH', "/api/canon-events/{$canon->id}", ['status' => 'dismissed']);
        if ($strategy) $try('B client', $bClient, 'PATCH', "/api/strategies/{$strategy->id}", ['status' => 'archived']);

        // B's therapist asking for A's client
        foreach (['/api/monthly/trends/client/', '/api/session-briefs/client/', '/api/sessions/client/', '/api/shared-insights/client/', '/api/tasks/client/'] as $p) {
            $try('B therapist', $bTher, 'GET', $p.$aClient->id);
        }
        $try('B therapist', $bTher, 'POST', '/api/shared-insights/chat/'.$aClient->id, ['message' => 'what did she write?']);

        $aEntryBody = $entry->body;
        foreach ($results as [$who, $call, $status, $content]) {
            $leaked = $status < 300 && (str_contains($content, mb_substr($aEntryBody, 0, 40)) || str_contains($content, '"user_id":'.$aClient->id) || str_contains($content, '"client_id":'.$aClient->id));
            fwrite(STDERR, sprintf("%-11s %-48s %d %s\n", $who, $call, $status, $leaked ? 'LEAK' : 'blocked'));
            $this->assertFalse($leaked, "$who $call leaked A's data");
        }

    }
}
