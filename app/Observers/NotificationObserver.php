<?php

namespace App\Observers;

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\ApplicationStatusChanged;
use App\Notifications\DirectOfferReceived;
use App\Notifications\EmployerApprovalDecision;
use Illuminate\Support\Facades\Log;

class NotificationObserver
{
    /**
     * Handle Application updated event.
     * Creates in-app notification + dispatches email when status changes.
     */
    public function updated(mixed $model): void
    {
        if ($model instanceof Application) {
            $this->handleApplicationUpdated($model);
        } elseif ($model instanceof DirectOffer) {
            $this->handleDirectOfferUpdated($model);
        } elseif ($model instanceof Employer) {
            $this->handleEmployerUpdated($model);
        }
    }

    /**
     * Handle DirectOffer created event — notify the job seeker.
     */
    public function created(mixed $model): void
    {
        if ($model instanceof DirectOffer) {
            $this->handleDirectOfferCreated($model);
        } elseif ($model instanceof Application) {
            $this->handleApplicationCreated($model);
        }
    }

    // ── Private handlers ─────────────────────────────────────────────

    private function handleApplicationUpdated(Application $application): void
    {
        // Only fire when status actually changed
        if (! $application->isDirty('status') && ! $application->wasChanged('status')) {
            return;
        }

        $userId = (string) $application->user_id;
        $status = $application->status;

        Notification::create([
            'user_id'             => $userId,
            'type'                => 'application_status_changed',
            'message'             => "Your application status has been updated to: {$status}.",
            'related_entity_id'   => (string) $application->_id,
            'related_entity_type' => 'Application',
            'read_at'             => null,
        ]);

        // Dispatch email notification
        try {
            $user = User::find($userId);
            if ($user) {
                $user->notify(new ApplicationStatusChanged($application));
            }
        } catch (\Throwable $e) {
            Log::error('ApplicationStatusChanged email failed', [
                'application_id' => (string) $application->_id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    private function handleDirectOfferCreated(DirectOffer $offer): void
    {
        $userId = (string) $offer->job_seeker_id;

        Notification::create([
            'user_id'             => $userId,
            'type'                => 'direct_offer_received',
            'message'             => 'You have received a new direct job offer.',
            'related_entity_id'   => (string) $offer->_id,
            'related_entity_type' => 'DirectOffer',
            'read_at'             => null,
        ]);

        try {
            $user = User::find($userId);
            if ($user) {
                $user->notify(new DirectOfferReceived($offer));
            }
        } catch (\Throwable $e) {
            Log::error('DirectOfferReceived email failed', [
                'offer_id' => (string) $offer->_id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function handleDirectOfferUpdated(DirectOffer $offer): void
    {
        // No additional in-app notifications needed for offer status changes in current spec
    }

    private function handleApplicationCreated(Application $application): void
    {
        // Notify the employer that a new application was submitted on their job post
        $jobPost = JobPost::find($application->job_post_id);
        if (! $jobPost) {
            return;
        }

        $employerId = (string) $jobPost->employer_id;

        Notification::create([
            'user_id'             => $employerId,
            'type'                => 'new_application',
            'message'             => "A new application has been submitted for your job post.",
            'related_entity_id'   => (string) $application->_id,
            'related_entity_type' => 'Application',
            'read_at'             => null,
        ]);
    }

    private function handleEmployerUpdated(Employer $employer): void
    {
        if (! $employer->wasChanged('status')) {
            return;
        }

        $status = $employer->status;
        if (! in_array($status, ['approved', 'rejected'])) {
            return;
        }

        $userId = (string) $employer->user_id;

        $message = $status === 'approved'
            ? 'Your employer account has been approved. You can now post jobs.'
            : 'Your employer application has been rejected.';

        Notification::create([
            'user_id'             => $userId,
            'type'                => 'employer_decision',
            'message'             => $message,
            'related_entity_id'   => (string) $employer->_id,
            'related_entity_type' => 'Employer',
            'read_at'             => null,
        ]);

        try {
            $user = User::find($userId);
            if ($user) {
                $user->notify(new EmployerApprovalDecision($employer));
            }
        } catch (\Throwable $e) {
            Log::error('EmployerApprovalDecision email failed', [
                'employer_id' => (string) $employer->_id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
