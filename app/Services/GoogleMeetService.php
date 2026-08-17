<?php

namespace App\Services;

use App\Models\GoogleOAuthToken;
use App\Models\Meeting;
use App\Models\User;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleCalendarEvent;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleMeetService
{
    private function getClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect_uri'));
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope(GoogleCalendar::CALENDAR_EVENTS);

        return $client;
    }

    public function getAuthUrl(User $user): string
    {
        $client = $this->getClient();
        $client->setState((string) $user->_id);

        return $client->createAuthUrl();
    }

    public function handleCallback(string $code, User $user): GoogleOAuthToken
    {
        $client = $this->getClient();
        $tokenData = $client->fetchAccessTokenWithAuthCode($code);

        $token = GoogleOAuthToken::updateOrCreate(
            ['user_id' => (string) $user->_id],
            [
                'access_token' => Crypt::encryptString($tokenData['access_token']),
                'refresh_token' => Crypt::encryptString($tokenData['refresh_token'] ?? ''),
                'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
                'scopes' => [$tokenData['scope'] ?? GoogleCalendar::CALENDAR_EVENTS],
                'is_valid' => true,
            ]
        );

        return $token;
    }

    public function isConnected(User $user): bool
    {
        return GoogleOAuthToken::where('user_id', (string) $user->_id)
            ->where('is_valid', true)
            ->exists();
    }

    public function disconnect(User $user): void
    {
        GoogleOAuthToken::where('user_id', (string) $user->_id)->delete();
    }

    public function createCalendarEventWithMeet(Meeting $meeting): ?string
    {
        try {
            $client = $this->getAuthenticatedClient($meeting->organizer_id);
            if (!$client) {
                return null;
            }

            $calendar = new GoogleCalendar($client);

            $startDateTime = $this->buildDateTime($meeting->proposed_date, $meeting->proposed_start_time);
            $endDateTime = $this->buildEndDateTime($meeting->proposed_date, $meeting->proposed_start_time, $meeting->proposed_duration_minutes);

            $event = new GoogleCalendarEvent([
                'summary' => $meeting->title,
                'start' => ['dateTime' => $startDateTime, 'timeZone' => 'UTC'],
                'end' => ['dateTime' => $endDateTime, 'timeZone' => 'UTC'],
                'attendees' => [
                    ['email' => User::find($meeting->organizer_id)?->email],
                    ['email' => User::find($meeting->invitee_id)?->email],
                ],
                'conferenceData' => [
                    'createRequest' => [
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                        'requestId' => Str::uuid()->toString(),
                    ],
                ],
            ]);

            $createdEvent = $calendar->events->insert('primary', $event, ['conferenceDataVersion' => 1]);

            $meetLink = $createdEvent->getHangoutLink();

            $meeting->update([
                'meet_link' => $meetLink,
                'google_calendar_event_id' => $createdEvent->getId(),
            ]);

            return $meetLink;
        } catch (\Throwable $e) {
            Log::error('Failed to create Google Calendar event with Meet link', [
                'meeting_id' => $meeting->_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function updateCalendarEvent(Meeting $meeting): void
    {
        try {
            if (!$meeting->google_calendar_event_id) {
                return;
            }

            $client = $this->getAuthenticatedClient($meeting->organizer_id);
            if (!$client) {
                return;
            }

            $calendar = new GoogleCalendar($client);
            $event = $calendar->events->get('primary', $meeting->google_calendar_event_id);

            $startDateTime = $this->buildDateTime($meeting->proposed_date, $meeting->proposed_start_time);
            $endDateTime = $this->buildEndDateTime($meeting->proposed_date, $meeting->proposed_start_time, $meeting->proposed_duration_minutes);

            $event->setStart(new EventDateTime(['dateTime' => $startDateTime, 'timeZone' => 'UTC']));
            $event->setEnd(new EventDateTime(['dateTime' => $endDateTime, 'timeZone' => 'UTC']));

            $calendar->events->update('primary', $meeting->google_calendar_event_id, $event);
        } catch (\Throwable $e) {
            Log::error('Failed to update Google Calendar event', [
                'meeting_id' => $meeting->_id,
                'event_id' => $meeting->google_calendar_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function deleteCalendarEvent(Meeting $meeting): void
    {
        try {
            if (!$meeting->google_calendar_event_id) {
                return;
            }

            $client = $this->getAuthenticatedClient($meeting->organizer_id);
            if (!$client) {
                return;
            }

            $calendar = new GoogleCalendar($client);
            $calendar->events->delete('primary', $meeting->google_calendar_event_id);
        } catch (\Throwable $e) {
            Log::error('Failed to delete Google Calendar event', [
                'meeting_id' => $meeting->_id,
                'event_id' => $meeting->google_calendar_event_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getAuthenticatedClient(string $userId): ?GoogleClient
    {
        $token = GoogleOAuthToken::where('user_id', $userId)
            ->where('is_valid', true)
            ->first();

        if (!$token) {
            return null;
        }

        $client = $this->getClient();

        $accessToken = Crypt::decryptString($token->access_token);
        $refreshToken = Crypt::decryptString($token->refresh_token);

        $client->setAccessToken([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => now()->diffInSeconds($token->token_expires_at),
        ]);

        if ($client->isAccessTokenExpired()) {
            try {
                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

                if (isset($newToken['error'])) {
                    $token->update(['is_valid' => false]);
                    return null;
                }

                $token->update([
                    'access_token' => Crypt::encryptString($newToken['access_token']),
                    'token_expires_at' => now()->addSeconds($newToken['expires_in'] ?? 3600),
                ]);
            } catch (\Throwable $e) {
                Log::error('Failed to refresh Google OAuth token', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $token->update(['is_valid' => false]);
                return null;
            }
        }

        return $client;
    }

    private function buildDateTime(string $date, string $time): string
    {
        return "{$date}T{$time}:00Z";
    }

    private function buildEndDateTime(string $date, string $time, int $durationMinutes): string
    {
        $start = \Carbon\Carbon::parse("{$date} {$time}", 'UTC');
        $end = $start->addMinutes($durationMinutes);

        return $end->format('Y-m-d\TH:i:s\Z');
    }
}
