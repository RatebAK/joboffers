<?php

namespace App\Notifications;

use App\Models\DirectOffer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DirectOfferReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly DirectOffer $offer) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('You Have Received a New Job Offer')
            ->greeting("Hello {$notifiable->name},")
            ->line('An employer has sent you a direct job offer.')
            ->line($this->offer->message ?? 'Please log in to review the offer details.')
            ->action('View Offer', url('/'))
            ->line('Thank you for using our platform.');
    }
}
