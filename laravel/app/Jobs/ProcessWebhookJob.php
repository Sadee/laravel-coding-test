<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWebhookJob implements ShouldQueue
{
    use Queueable;

    // Read by the queue at dispatch time and stored in the job payload.
    public int $tries = 3;

    public array $backoff = [5, 10];

    // Must stay below the connection's retry_after (90s) so a running job isn't picked up twice.
    public int $timeout = 30;

    public function __construct(public WebhookEvent $event) {}

    public function handle(): void
    {
        // Atomically claim the event. Only one worker can move it pending -> processing;
        // anyone else (duplicate dispatch, concurrent worker) sees 0 rows and stops.
        $claimed = WebhookEvent::whereKey($this->event->id)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            return;
        }

        try {
            // Simulate processing work. Real side effects should be idempotent on event_id.
            sleep(1);

            WebhookEvent::whereKey($this->event->id)->update([
                'status'        => 'completed',
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            // Release the claim so the next retry can pick it up, keeping the last error visible.
            WebhookEvent::whereKey($this->event->id)->update([
                'status'        => 'pending',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Called only after the final attempt has failed.
     */
    public function failed(\Throwable $e): void
    {
        $this->event->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
