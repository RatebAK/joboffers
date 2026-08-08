<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\User;
use App\Notifications\BroadcastNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $subject,
        public readonly string $body
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        // Create in-app notification
        Notification::create([
            'user_id' => $this->userId,
            'type'    => 'broadcast',
            'message' => $this->subject . ': ' . $this->body,
            'read_at' => null,
        ]);

        // Send email
        try {
            $user->notify(new BroadcastNotification($this->subject, $this->body));
        } catch (\Throwable $e) {
            Log::error('BroadcastNotification email failed', [
                'user_id' => $this->userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
