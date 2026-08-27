<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class MeetingNotificationService
{
    /**
     * Notify the invitee about a new meeting invitation.
     */
    public function notifyInvitation(Meeting $meeting): void
    {
        try {
            Notification::create([
                'user_id'             => (string) $meeting->invitee_id,
                'type'                => 'meeting_invitation',
                'message'             => "You have been invited to a meeting: {$meeting->title}",
                'related_entity_id'   => $meeting->_id,
                'related_entity_type' => 'Meeting',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send meeting invitation notification', [
                'meeting_id' => $meeting->_id,
                'invitee_id' => $meeting->invitee_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the organizer that the meeting was accepted.
     */
    public function notifyAccepted(Meeting $meeting): void
    {
        try {
            Notification::create([
                'user_id'             => (string) $meeting->organizer_id,
                'type'                => 'meeting_accepted',
                'message'             => "Your meeting \"{$meeting->title}\" has been accepted.",
                'related_entity_id'   => $meeting->_id,
                'related_entity_type' => 'Meeting',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send meeting accepted notification', [
                'meeting_id'   => $meeting->_id,
                'organizer_id' => $meeting->organizer_id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the organizer that the meeting was declined.
     */
    public function notifyDeclined(Meeting $meeting): void
    {
        try {
            Notification::create([
                'user_id'             => (string) $meeting->organizer_id,
                'type'                => 'meeting_declined',
                'message'             => "Your meeting \"{$meeting->title}\" has been declined.",
                'related_entity_id'   => $meeting->_id,
                'related_entity_type' => 'Meeting',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send meeting declined notification', [
                'meeting_id'   => $meeting->_id,
                'organizer_id' => $meeting->organizer_id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the OTHER participant that the meeting was cancelled.
     */
    public function notifyCancelled(Meeting $meeting, string $cancelledByUserId): void
    {
        try {
            $recipientId = $meeting->organizer_id === $cancelledByUserId
                ? $meeting->invitee_id
                : $meeting->organizer_id;

            Notification::create([
                'user_id'             => (string) $recipientId,
                'type'                => 'meeting_cancelled',
                'message'             => "The meeting \"{$meeting->title}\" has been cancelled.",
                'related_entity_id'   => $meeting->_id,
                'related_entity_type' => 'Meeting',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send meeting cancelled notification', [
                'meeting_id'        => $meeting->_id,
                'cancelled_by'      => $cancelledByUserId,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify the invitee that the meeting was rescheduled.
     */
    public function notifyRescheduled(Meeting $meeting): void
    {
        try {
            Notification::create([
                'user_id'             => (string) $meeting->invitee_id,
                'type'                => 'meeting_rescheduled',
                'message'             => "The meeting \"{$meeting->title}\" has been rescheduled.",
                'related_entity_id'   => $meeting->_id,
                'related_entity_type' => 'Meeting',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send meeting rescheduled notification', [
                'meeting_id' => $meeting->_id,
                'invitee_id' => $meeting->invitee_id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
