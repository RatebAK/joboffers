<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendBulkInviteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $userId,
        public readonly string $companyName,
        public readonly string $tempPassword
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        Mail::send([], [], function ($message) use ($user) {
            $message
                ->to($user->email, $user->name)
                ->subject("You've been invited to join " . config('app.name'))
                ->html(
                    "<p>Hello {$user->name},</p>" .
                    "<p>You have been onboarded as an employer on " . config('app.name') . " for <strong>{$this->companyName}</strong>.</p>" .
                    "<p>Your temporary password is: <strong>{$this->tempPassword}</strong></p>" .
                    "<p>Please log in and change your password as soon as possible.</p>" .
                    "<p>Your account is pending admin approval before you can post jobs.</p>"
                );
        });
    }
}
