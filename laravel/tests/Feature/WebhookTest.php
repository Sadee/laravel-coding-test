<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_is_received_and_stored(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_001',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000, 'currency' => 'usd'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonStructure(['id', 'status']);
        $this->assertEquals('pending', $response->json('status'));
        $this->assertDatabaseHas('webhook_events', ['event_id' => 'evt_001']);
    }

    public function test_webhook_requires_event_id(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'type'    => 'payment.succeeded',
            'payload' => ['amount' => 1000],
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_requires_payload(): void
    {
        $response = $this->postJson('/api/webhooks/stripe', [
            'event_id' => 'evt_002',
            'type'     => 'payment.succeeded',
        ]);

        $response->assertStatus(422);
    }

    public function test_webhook_status_can_be_retrieved(): void
    {
        $event = WebhookEvent::create([
            'event_id' => 'evt_003',
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 500],
            'status'   => 'completed',
        ]);

        $response = $this->getJson("/api/webhooks/{$event->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'event_id' => 'evt_003',
            'status'   => 'completed',
        ]);
    }

    public function test_webhook_returns_404_for_unknown_id(): void
    {
        $response = $this->getJson('/api/webhooks/99999');

        $response->assertStatus(404);
    }

    public function test_duplicate_webhook_is_stored_and_dispatched_once(): void
    {
        Queue::fake();

        $body = [
            'event_id' => 'evt_dup',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000],
        ];

        $first = $this->postJson('/api/webhooks/stripe', $body)->assertStatus(202);
        $second = $this->postJson('/api/webhooks/stripe', $body)->assertStatus(200);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertTrue($second->json('duplicate'));
        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_dup')->count());
        Queue::assertPushed(ProcessWebhookJob::class, 1);
    }

    public function test_same_event_id_from_different_providers_is_not_a_duplicate(): void
    {
        Queue::fake();

        $body = [
            'event_id' => 'evt_shared',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 1000],
        ];

        $this->postJson('/api/webhooks/stripe', $body)->assertStatus(202);
        $this->postJson('/api/webhooks/paypal', $body)->assertStatus(202);

        $this->assertSame(2, WebhookEvent::where('event_id', 'evt_shared')->count());
        Queue::assertPushed(ProcessWebhookJob::class, 2);
    }

    public function test_job_processes_pending_event(): void
    {
        $event = $this->makeEvent('evt_job_pending', 'pending');

        (new ProcessWebhookJob($event))->handle();

        $this->assertSame('completed', $event->fresh()->status);
    }

    public function test_job_skips_event_that_is_not_pending(): void
    {
        foreach (['processing', 'completed', 'failed'] as $status) {
            $event = $this->makeEvent("evt_job_{$status}", $status);

            (new ProcessWebhookJob($event))->handle();

            $this->assertSame($status, $event->fresh()->status);
        }
    }

    public function test_job_claim_stamps_updated_at(): void
    {
        $this->travelTo(now()->startOfSecond());
        $event = $this->makeEvent('evt_job_claim_ts', 'pending');

        $this->travel(10)->minutes();
        $claimedAt = now()->toDateTimeString();

        // Capture the claim UPDATE (pending -> processing); completion overwrites
        // updated_at afterwards, so the final row alone can't prove the claim stamped it.
        $claimBindings = null;
        DB::listen(function ($query) use (&$claimBindings) {
            if (str_starts_with(strtolower($query->sql), 'update') && in_array('processing', $query->bindings, true)) {
                $claimBindings = $query->bindings;
            }
        });

        (new ProcessWebhookJob($event))->handle();

        $this->assertNotNull($claimBindings, 'Claim update was not executed.');
        $this->assertContains($claimedAt, array_map(
            fn ($b) => $b instanceof \DateTimeInterface ? $b->format('Y-m-d H:i:s') : $b,
            $claimBindings,
        ));
        $this->assertSame($claimedAt, $event->fresh()->updated_at->toDateTimeString());
    }

    public function test_job_skip_does_not_touch_updated_at(): void
    {
        $this->travelTo(now()->startOfSecond());
        $event = $this->makeEvent('evt_job_skip_ts', 'processing');
        $original = $event->updated_at->toDateTimeString();

        $this->travel(10)->minutes();
        (new ProcessWebhookJob($event))->handle();

        $this->assertSame($original, $event->fresh()->updated_at->toDateTimeString());
    }

    private function makeEvent(string $eventId, string $status): WebhookEvent
    {
        return WebhookEvent::create([
            'event_id' => $eventId,
            'provider' => 'stripe',
            'type'     => 'payment.succeeded',
            'payload'  => ['amount' => 500],
            'status'   => $status,
        ]);
    }
}
