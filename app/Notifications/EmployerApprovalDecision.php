<?php

namespace App\Notifications;

use App\Models\Employer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployerApprovalDecision extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Employer $employer) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->employer->status === 'approved';

        $subject = $approved
            ? 'Your Employer Account Has Been Approved'
            : 'Your Employer Application Has Been Rejected';

        $body = $approved
            ? 'Congratulations! Your employer account has been approved. You can now post jobs and search for candidates.'
            : 'Unfortunately, your employer application has been rejected.';

        $mail = (new MailMessage())
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($body);

        if (! $approved && $this->employer->review_notes) {
            $mail->line("Reason: {$this->employer->review_notes}");
        }

        return $mail
            ->action('Visit Platform', url('/'))
            ->line('Thank you for using our platform.');
    }
}
