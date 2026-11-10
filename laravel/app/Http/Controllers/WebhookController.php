<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function receive(Request $request, string $provider): JsonResponse
    {
        $request->validate([
            'event_id' => 'required|string',
            'type'     => 'required|string',
            'payload'  => 'required|array',
        ]);

        // INSERT first; if the (provider, event_id) unique index rejects it, return the
        // existing row. The DB resolves concurrent deliveries, so there is no check-then-insert race.
        $event = WebhookEvent::createOrFirst(
            [
                'provider' => $provider,
                'event_id' => $request->input('event_id'),
            ],
            [
                'type'    => $request->input('type'),
                'payload' => $request->input('payload'),
                'status'  => 'pending',
            ],
        );

        if (! $event->wasRecentlyCreated) {
            // Duplicate delivery: acknowledge with 2xx so the provider stops retrying.
            return response()->json([
                'id'        => $event->id,
                'status'    => $event->status,
                'duplicate' => true,
            ], 200);
        }

        ProcessWebhookJob::dispatch($event);

        return response()->json([
            'id'     => $event->id,
            'status' => $event->status,
        ], 202);
    }

    public function show(int $id): JsonResponse
    {
        $event = WebhookEvent::findOrFail($id);

        return response()->json([
            'id'            => $event->id,
            'event_id'      => $event->event_id,
            'provider'      => $event->provider,
            'type'          => $event->type,
            'status'        => $event->status,
            'error_message' => $event->error_message,
            'created_at'    => $event->created_at,
        ]);
    }
}
