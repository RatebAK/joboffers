<?php

namespace App\Notifications;

use App\Models\Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApplicationStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Application $application) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Your Application Status Has Been Updated')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your application status has been updated to: **{$this->application->status}**.")
            ->line('Log in to the platform to view more details.')
            ->action('View Application', url('/'))
            ->line('Thank you for using our platform.');
    }
}
