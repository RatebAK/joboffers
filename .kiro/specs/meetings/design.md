# Design Document: Meetings

## Overview

The Meetings feature adds scheduling capabilities between employers and job seekers, with optional Google Meet integration for video calls. The architecture follows the existing project conventions with a focus on small, focused files and clear separation of concerns.

## Architecture

### Component Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                         Routes (api.php)                         │
└─────────┬──────────┬──────────┬──────────┬──────────┬───────────┘
          │          │          │          │          │
          ▼          ▼          ▼          ▼          ▼
┌──────────┐ ┌────────────┐ ┌────────┐ ┌──────────┐ ┌───────────┐
│ Meeting  │ │  Meeting   │ │Meeting │ │ Google   │ │   Admin   │
│Controller│ │  Action    │ │ Note   │ │  OAuth   │ │  Meeting  │
│          │ │ Controller │ │Control.│ │Controller│ │ Controller│
└────┬─────┘ └─────┬──────┘ └───┬────┘ └────┬─────┘ └─────┬─────┘
     │              │            │           │              │
     └──────┬───────┴────────────┴───────────┘              │
            ▼                                               │
┌───────────────────┐  ┌─────────────────────┐              │
│  MeetingService   │◄─┤MeetingConflictService│              │
└────────┬──────────┘  └─────────────────────┘              │
         │                                                  │
    ┌────┴──────────────────┐                               │
    ▼                       ▼                               │
┌────────────────┐  ┌──────────────────────┐                │
│MeetingNotific. │  │  GoogleMeetService   │                │
│   Service      │  │                      │                │
└───────┬────────┘  └──────────┬───────────┘                │
        │                      │                            │
        ▼                      ▼                            │
┌──────────────┐    ┌──────────────────┐            ┌───────┴──────┐
│ Notification │    │ GoogleOAuthToken  │            │   Meeting    │
│   (Model)    │    │    (Model)       │            │   (Model)    │
└──────────────┘    └──────────────────┘            └──────────────┘
```

### File Structure

```
app/
  Http/
    Controllers/API/
      MeetingController.php          # create, show, index, upcoming
      MeetingActionController.php    # accept, decline, cancel, reschedule, complete
      MeetingNoteController.php      # addNote
      GoogleOAuthController.php      # connect, callback, status, disconnect
      AdminMeetingController.php     # index, show (admin)
    Requests/
      CreateMeetingRequest.php       # Validation for meeting creation
      RescheduleMeetingRequest.php   # Validation for rescheduling
  Models/
    Meeting.php                      # Meeting document
    GoogleOAuthToken.php             # Stores user's Google tokens
  Services/
    MeetingService.php               # Core meeting business logic
    MeetingConflictService.php       # Conflict detection logic
    MeetingNotificationService.php   # Notification dispatch
    GoogleMeetService.php            # Google Calendar API + Meet links

tests/
  Feature/
    MeetingCreateTest.php
    MeetingActionTest.php
    MeetingNoteTest.php
    MeetingListTest.php
    GoogleOAuthTest.php
    AdminMeetingTest.php
```

---

## Data Models

### Meeting

**Collection:** `meetings`

| Field | Type | Description |
|-------|------|-------------|
| _id | ObjectId | Primary key |
| organizer_id | string | User ID of the meeting creator |
| invitee_id | string | User ID of the invited participant |
| title | string | Meeting title (1-255 chars) |
| meeting_type | string | in_person, phone_call, or video_call |
| proposed_date | string | ISO date (YYYY-MM-DD) |
| proposed_start_time | string | Time (HH:MM) in 24h format |
| proposed_duration_minutes | integer | 15–480 |
| status | string | pending, accepted, declined, cancelled, rescheduled, completed |
| location_or_link | string|null | Address/phone for in_person/phone_call |
| meet_link | string|null | Google Meet URL (auto-generated or manual) |
| google_calendar_event_id | string|null | Google Calendar event ID for lifecycle sync |
| decline_reason | string|null | Reason for declining |
| cancellation_reason | string|null | Reason for cancellation |
| cancelled_by | string|null | User ID of who cancelled |
| notes | array | [{author_id, content, created_at}] |
| previous_schedules | array | [{proposed_date, proposed_start_time, proposed_duration_minutes}] |
| created_at | datetime | |
| updated_at | datetime | |

### GoogleOAuthToken

**Collection:** `google_oauth_tokens`

| Field | Type | Description |
|-------|------|-------------|
| _id | ObjectId | Primary key |
| user_id | string | Owning user ID (unique index) |
| access_token | string | Google access token (encrypted) |
| refresh_token | string | Google refresh token (encrypted) |
| token_expires_at | datetime | When access token expires |
| scopes | array | Granted OAuth scopes |
| is_valid | boolean | Whether token is still usable |
| created_at | datetime | |
| updated_at | datetime | |

---

## API Endpoints

### Meeting CRUD (both roles)

| Method | Path | Controller | Action |
|--------|------|------------|--------|
| POST | /api/meetings | MeetingController@store | Create meeting invitation |
| GET | /api/meetings | MeetingController@index | List user's meetings (paginated, filterable) |
| GET | /api/meetings/upcoming | MeetingController@upcoming | Next 5 accepted meetings |
| GET | /api/meetings/{id} | MeetingController@show | Meeting detail |

### Meeting Actions (both roles)

| Method | Path | Controller | Action |
|--------|------|------------|--------|
| POST | /api/meetings/{id}/accept | MeetingActionController@accept | Accept invitation |
| POST | /api/meetings/{id}/decline | MeetingActionController@decline | Decline invitation |
| POST | /api/meetings/{id}/cancel | MeetingActionController@cancel | Cancel meeting |
| POST | /api/meetings/{id}/reschedule | MeetingActionController@reschedule | Reschedule meeting |
| POST | /api/meetings/{id}/complete | MeetingActionController@complete | Mark as completed |

### Meeting Notes (both roles)

| Method | Path | Controller | Action |
|--------|------|------------|--------|
| POST | /api/meetings/{id}/notes | MeetingNoteController@store | Add a note |

### Google OAuth

| Method | Path | Controller | Action |
|--------|------|------------|--------|
| GET | /api/google/connect | GoogleOAuthController@connect | Initiate OAuth flow |
| GET | /api/google/callback | GoogleOAuthController@callback | Handle OAuth redirect |
| GET | /api/google/status | GoogleOAuthController@status | Check connection status |
| DELETE | /api/google/disconnect | GoogleOAuthController@disconnect | Remove stored tokens |

### Admin Meetings

| Method | Path | Controller | Action |
|--------|------|------------|--------|
| GET | /api/admin/meetings | AdminMeetingController@index | All meetings (paginated) |
| GET | /api/admin/meetings/{id} | AdminMeetingController@show | Any meeting detail |

---

## Components and Interfaces

## Service Layer Design

### MeetingService

Handles core business logic. Called by controllers for state transitions.

```php
class MeetingService
{
    public function __construct(
        private MeetingConflictService $conflictService,
        private MeetingNotificationService $notificationService,
        private GoogleMeetService $googleMeetService,
    ) {}

    public function createMeeting(array $data, User $organizer): array;
    public function acceptMeeting(Meeting $meeting, User $invitee): array;
    public function declineMeeting(Meeting $meeting, User $invitee, ?string $reason): Meeting;
    public function cancelMeeting(Meeting $meeting, User $user, ?string $reason): Meeting;
    public function rescheduleMeeting(Meeting $meeting, array $newSchedule): array;
    public function completeMeeting(Meeting $meeting): Meeting;
}
```

### MeetingConflictService

Detects time overlaps for a given user. Returns conflict arrays.

```php
class MeetingConflictService
{
    public function detectConflicts(
        string $userId,
        string $date,
        string $startTime,
        int $durationMinutes,
        ?string $excludeMeetingId = null
    ): array;
}
```

### MeetingNotificationService

Creates in-app notifications using the existing Notification model.

```php
class MeetingNotificationService
{
    public function notifyInvitation(Meeting $meeting): void;
    public function notifyAccepted(Meeting $meeting): void;
    public function notifyDeclined(Meeting $meeting): void;
    public function notifyCancelled(Meeting $meeting, string $cancelledByUserId): void;
    public function notifyRescheduled(Meeting $meeting): void;
}
```

### GoogleMeetService

Manages Google OAuth tokens and creates Calendar events with Meet links.

```php
class GoogleMeetService
{
    public function getAuthUrl(User $user): string;
    public function handleCallback(string $code, User $user): GoogleOAuthToken;
    public function isConnected(User $user): bool;
    public function disconnect(User $user): void;
    public function createCalendarEventWithMeet(Meeting $meeting): ?string;
    public function updateCalendarEvent(Meeting $meeting): void;
    public function deleteCalendarEvent(Meeting $meeting): void;
}
```

---

## Key Flows

### Meeting Acceptance with Google Meet (video_call)

```
Invitee → POST /meetings/{id}/accept
    │
    ▼
MeetingActionController::accept()
    │
    ▼
MeetingService::acceptMeeting()
    ├── Update status to "accepted"
    ├── If meeting_type == "video_call":
    │     └── GoogleMeetService::createCalendarEventWithMeet()
    │           ├── Get organizer's GoogleOAuthToken
    │           ├── Refresh token if expired
    │           ├── Create Google Calendar event with conferenceData
    │           ├── Extract meet_link from response
    │           └── Store meet_link + google_calendar_event_id on Meeting
    ├── MeetingConflictService::detectConflicts() (for warnings)
    ├── MeetingNotificationService::notifyAccepted()
    └── Return meeting + conflicts + meet_link
```

### Google OAuth Connection Flow

```
User → GET /google/connect
    │
    ▼
GoogleOAuthController::connect()
    ├── Build Google OAuth URL with Calendar scope
    └── Return { auth_url: "https://accounts.google.com/o/oauth2/..." }

User → (browser redirect to Google, consent screen)

Google → GET /google/callback?code=xxx
    │
    ▼
GoogleOAuthController::callback()
    ├── GoogleMeetService::handleCallback(code, user)
    │     ├── Exchange code for access_token + refresh_token
    │     └── Store in google_oauth_tokens collection
    └── Return { message: "Google account connected" }
```

### Meeting Cancellation with Calendar Sync

```
Participant → POST /meetings/{id}/cancel
    │
    ▼
MeetingActionController::cancel()
    │
    ▼
MeetingService::cancelMeeting()
    ├── Update status to "cancelled"
    ├── If google_calendar_event_id exists:
    │     └── GoogleMeetService::deleteCalendarEvent()
    ├── MeetingNotificationService::notifyCancelled()
    └── Return updated meeting
```

---

## Configuration

### Environment Variables

```
GOOGLE_CLIENT_ID=           # Google Cloud Console OAuth client ID
GOOGLE_CLIENT_SECRET=       # OAuth client secret
GOOGLE_REDIRECT_URI=        # e.g. https://yourapp.com/api/google/callback
```

### Config File

Add to `config/services.php`:

```php
'google' => [
    'client_id' => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
],
```

### Composer Dependency

```
composer require google/apiclient:^2.16
```

---

## Route Registration

```php
// Meetings — any authenticated user (employer or employee)
Route::middleware('jwt.auth')->prefix('meetings')->group(function () {
    Route::get('/',          [MeetingController::class, 'index']);
    Route::post('/',         [MeetingController::class, 'store']);
    Route::get('/upcoming',  [MeetingController::class, 'upcoming']);
    Route::get('/{id}',      [MeetingController::class, 'show']);

    Route::post('/{id}/accept',     [MeetingActionController::class, 'accept']);
    Route::post('/{id}/decline',    [MeetingActionController::class, 'decline']);
    Route::post('/{id}/cancel',     [MeetingActionController::class, 'cancel']);
    Route::post('/{id}/reschedule', [MeetingActionController::class, 'reschedule']);
    Route::post('/{id}/complete',   [MeetingActionController::class, 'complete']);

    Route::post('/{id}/notes', [MeetingNoteController::class, 'store']);
});

// Google OAuth — any authenticated user
Route::middleware('jwt.auth')->prefix('google')->group(function () {
    Route::get('/connect',    [GoogleOAuthController::class, 'connect']);
    Route::get('/callback',   [GoogleOAuthController::class, 'callback']);
    Route::get('/status',     [GoogleOAuthController::class, 'status']);
    Route::delete('/disconnect', [GoogleOAuthController::class, 'disconnect']);
});

// Admin meetings
Route::middleware(['jwt.auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/meetings',      [AdminMeetingController::class, 'index']);
    Route::get('/meetings/{id}', [AdminMeetingController::class, 'show']);
});
```

---

## Error Handling

All errors follow the platform convention:
- Validation errors: `{"errors": {...}}` with 422 status
- Not found: `{"message": "Meeting not found"}` with 404 status
- Forbidden: `{"message": "Forbidden"}` with 403 status
- Business logic errors: `{"message": "...descriptive..."}` with 422 status

Google API failures are logged but never block meeting operations. The `meet_link` field is simply set to null with a `google_meet_warning` field in the response.

---

## Security Considerations

- Google OAuth tokens (access_token, refresh_token) are stored encrypted using Laravel's `Crypt` facade
- Token values are never exposed in API responses — only `connected: true/false`
- The Google callback endpoint validates the `state` parameter to prevent CSRF
- Meeting participants can only see/modify their own meetings (ownership checked in controller)
- Admin endpoints require the admin role middleware

---

## Testing Strategy

Tests use Pest v4 with the Laravel plugin, running against the `laravel_test` MongoDB database.

**Unit Tests:**
- `tests/Unit/MeetingConflictServiceTest.php` — overlap detection logic with edge cases (adjacent, partial, same time)

**Feature Tests:**
- `tests/Feature/MeetingCreateTest.php` — create meeting, validation, conflict warnings
- `tests/Feature/MeetingListTest.php` — pagination, filters, sort, upcoming summary
- `tests/Feature/MeetingActionTest.php` — accept, decline, cancel, reschedule, complete + all error cases
- `tests/Feature/MeetingNoteTest.php` — add note, validation, authorization
- `tests/Feature/GoogleOAuthTest.php` — connect URL, callback, status, disconnect (mocked Google client)
- `tests/Feature/AdminMeetingTest.php` — admin list/show, non-admin rejected

Google API calls are mocked in tests using Laravel's `Http::fake()` to avoid real external calls.

---

## Correctness Properties

### Property 1: Valid Status Transitions
A meeting can only transition through valid paths: pending→accepted, pending→declined, pending→cancelled, pending→rescheduled, accepted→cancelled, accepted→completed, rescheduled→accepted, rescheduled→declined, rescheduled→cancelled.
**Validates: Requirements 2.1, 2.4, 3.1, 3.3, 4.1, 4.3, 11.1, 11.2**

### Property 2: Ownership Enforcement
Only the invitee can accept or decline; only the organizer can reschedule or complete.
**Validates: Requirements 2.3, 4.4, 11.4**

### Property 3: Non-blocking Conflicts
Conflict detection is non-blocking — meetings are always created regardless of overlaps.
**Validates: Requirements 8.3**

### Property 4: Google Failure Resilience
Google Calendar API failures never prevent meeting state transitions.
**Validates: Requirements 14.8, 15.3**

### Property 5: Notification Failure Resilience
Notification failures never prevent meeting state transitions.
**Validates: Requirements 9.6**

### Property 6: Monotonic Reschedule History
A meeting's previous_schedules array grows monotonically with each reschedule.
**Validates: Requirements 4.5**

### Property 7: Completion Date Constraint
A completed meeting must have a proposed_date strictly in the past.
**Validates: Requirements 11.1, 11.3**
