# Implementation Plan: Meetings Feature

## Overview

Implements the meetings scheduling system with Google Meet integration. The architecture splits logic across focused files: 5 controllers, 4 services, 2 models, and 2 form request classes. All new code — no existing files modified except `routes/api.php` and `config/services.php`.

## Tasks

- [x] 1. Create Meeting and GoogleOAuthToken models
  - [ ] 1.1 Create `app/Models/Meeting.php` extending `MongoDB\Laravel\Eloquent\Model`
    - Collection: `meetings`
    - Fillable: organizer_id, invitee_id, title, meeting_type, proposed_date, proposed_start_time, proposed_duration_minutes, status, location_or_link, meet_link, google_calendar_event_id, decline_reason, cancellation_reason, cancelled_by, notes, previous_schedules
    - Casts: created_at/updated_at as datetime, notes as array, previous_schedules as array
    - Relationships: organizer() belongsTo User, invitee() belongsTo User
    - _Requirements: R1, R6_
  - [ ] 1.2 Create `app/Models/GoogleOAuthToken.php` extending `MongoDB\Laravel\Eloquent\Model`
    - Collection: `google_oauth_tokens`
    - Fillable: user_id, access_token, refresh_token, token_expires_at, scopes, is_valid
    - Casts: token_expires_at as datetime, is_valid as boolean, scopes as array
    - Relationship: user() belongsTo User
    - _Requirements: R13, R14_

- [x] 2. Create Form Request validation classes
  - [ ] 2.1 Create `app/Http/Requests/CreateMeetingRequest.php`
    - Rules: invitee_id (required|string), title (required|string|min:1|max:255), meeting_type (required|in:in_person,phone_call,video_call), proposed_date (required|date|after_or_equal:today), proposed_start_time (required|string), proposed_duration_minutes (required|integer|min:15|max:480), location_or_link (nullable|string|max:500)
    - _Requirements: R1_
  - [ ] 2.2 Create `app/Http/Requests/RescheduleMeetingRequest.php`
    - Rules: proposed_date (required|date|after:today), proposed_start_time (required|string), proposed_duration_minutes (required|integer|min:15|max:480)
    - _Requirements: R4_

- [x] 3. Create MeetingConflictService
  - [ ] 3.1 Create `app/Services/MeetingConflictService.php`
    - Method: `detectConflicts(string $userId, string $date, string $startTime, int $durationMinutes, ?string $excludeMeetingId = null): array`
    - Query accepted meetings for user on date, compare time ranges for overlap (strict start-before-end / end-after-start)
    - Return array of conflict entries with meeting id, proposed_date, proposed_start_time, proposed_duration_minutes
    - _Requirements: R8_
  - [ ] 3.2 Write `tests/Unit/MeetingConflictServiceTest.php`
    - Test: overlapping meetings detected, adjacent meetings NOT conflicting, same-time meetings detected, partial overlap detected
    - _Requirements: R8_

- [x] 4. Create MeetingNotificationService
  - [ ] 4.1 Create `app/Services/MeetingNotificationService.php`
    - Methods: notifyInvitation, notifyAccepted, notifyDeclined, notifyCancelled, notifyRescheduled
    - Each creates a Notification record (type, message, related_entity_id, related_entity_type=Meeting, user_id)
    - All wrapped in try/catch — log failures, never throw
    - _Requirements: R9_

- [x] 5. Create GoogleMeetService
  - [ ] 5.1 Install `google/apiclient` dependency
    - Run: `composer require google/apiclient:^2.16`
    - _Requirements: R13, R14_
  - [ ] 5.2 Add Google config to `config/services.php`
    - Add 'google' key with client_id, client_secret, redirect_uri from env
    - _Requirements: R13_
  - [ ] 5.3 Create `app/Services/GoogleMeetService.php`
    - `getAuthUrl(User $user): string` — builds OAuth URL with calendar.events scope and state param
    - `handleCallback(string $code, User $user): GoogleOAuthToken` — exchanges code for tokens, stores encrypted
    - `isConnected(User $user): bool` — checks for valid token
    - `disconnect(User $user): void` — deletes token record
    - `createCalendarEventWithMeet(Meeting $meeting): ?string` — creates Calendar event with conferenceData (hangoutsMeet), returns meet_link or null
    - `updateCalendarEvent(Meeting $meeting): void` — updates event time on reschedule
    - `deleteCalendarEvent(Meeting $meeting): void` — deletes event on cancel
    - Auto-refreshes expired tokens, marks invalid on failure, never throws on Google API errors
    - _Requirements: R13, R14, R15_

- [x] 6. Create MeetingService (core business logic)
  - [ ] 6.1 Create `app/Services/MeetingService.php`
    - Constructor injects: MeetingConflictService, MeetingNotificationService, GoogleMeetService
    - `createMeeting(array $data, User $organizer): array` — creates meeting (pending), checks conflicts for both users, notifies invitee, returns meeting + conflicts
    - `acceptMeeting(Meeting $meeting, User $invitee, ?string $meetLink = null): array` — sets accepted, triggers Google Meet for video_call, notifies, returns meeting + meet_link + conflicts
    - `declineMeeting(Meeting $meeting, User $invitee, ?string $reason): Meeting` — sets declined, stores reason, notifies
    - `cancelMeeting(Meeting $meeting, User $user, ?string $reason): Meeting` — sets cancelled, stores reason + cancelled_by, deletes calendar event, notifies
    - `rescheduleMeeting(Meeting $meeting, array $newSchedule): array` — appends to previous_schedules, updates fields, sets rescheduled, updates calendar event, checks conflicts, notifies
    - `completeMeeting(Meeting $meeting): Meeting` — sets completed
    - _Requirements: R1, R2, R3, R4, R11, R14, R15_

- [x] 7. Create MeetingController (CRUD)
  - [ ] 7.1 Create `app/Http/Controllers/API/MeetingController.php`
    - `store(CreateMeetingRequest)` — validates invitee role/not-self, calls MeetingService::createMeeting, returns 201
    - `index(Request)` — paginated list, filters (status, from_date, to_date, sort_direction), includes participant info, default 15/page max 100
    - `show(Request, $id)` — full detail, participant check (403 if not), 404 if missing
    - `upcoming(Request)` — next 5 accepted future meetings with participant name
    - _Requirements: R1, R5, R6, R10_

- [x] 8. Create MeetingActionController
  - [ ] 8.1 Create `app/Http/Controllers/API/MeetingActionController.php`
    - `accept(Request, $id)` — invitee only, pending/rescheduled status, optional meet_link body param, calls acceptMeeting
    - `decline(Request, $id)` — invitee only, pending/rescheduled status, optional decline_reason (max 500)
    - `cancel(Request, $id)` — participant check, valid status per role (organizer: pending/accepted/rescheduled; invitee: accepted/rescheduled), optional cancellation_reason (max 500)
    - `reschedule(RescheduleMeetingRequest, $id)` — organizer only, not terminal status
    - `complete(Request, $id)` — organizer only, accepted status, past date
    - _Requirements: R2, R3, R4, R11_

- [x] 9. Create MeetingNoteController
  - [ ] 9.1 Create `app/Http/Controllers/API/MeetingNoteController.php`
    - `store(Request, $id)` — participant check, validate content (required, not whitespace-only, max 2000), append to notes array
    - _Requirements: R7_

- [x] 10. Create GoogleOAuthController
  - [ ] 10.1 Create `app/Http/Controllers/API/GoogleOAuthController.php`
    - `connect(Request)` — returns auth_url from GoogleMeetService
    - `callback(Request)` — validates code/state, calls handleCallback, returns success
    - `status(Request)` — returns { connected: bool }
    - `disconnect(Request)` — calls disconnect, returns success
    - _Requirements: R13_

- [x] 11. Create AdminMeetingController
  - [ ] 11.1 Create `app/Http/Controllers/API/AdminMeetingController.php`
    - `index(Request)` — paginated list of ALL meetings, same filters, both participants' info
    - `show(Request, $id)` — any meeting detail, no ownership check, 404 if not found
    - _Requirements: R12_

- [x] 12. Register routes in api.php
  - [ ] 12.1 Add meeting routes under `jwt.auth` middleware
    - Prefix `meetings`: GET /, POST /, GET /upcoming, GET /{id}, POST /{id}/accept, POST /{id}/decline, POST /{id}/cancel, POST /{id}/reschedule, POST /{id}/complete, POST /{id}/notes
    - _Requirements: R1-R11_
  - [ ] 12.2 Add Google OAuth routes under `jwt.auth` middleware
    - Prefix `google`: GET /connect, GET /callback, GET /status, DELETE /disconnect
    - _Requirements: R13_
  - [ ] 12.3 Add admin meeting routes under `jwt.auth` + `role:admin`
    - Inside existing admin group: GET /meetings, GET /meetings/{id}
    - _Requirements: R12_

- [x] 13. Write feature tests for meeting CRUD and actions
  - [ ] 13.1 Create `tests/Feature/MeetingCreateTest.php`
    - Tests: employer creates meeting with seeker, seeker creates with employer, validation errors (missing fields, bad type, past date, self-invite, wrong role), conflict warnings in response
    - _Requirements: R1, R8_
  - [ ] 13.2 Create `tests/Feature/MeetingListTest.php`
    - Tests: paginated listing, status filter, date range, sort direction, empty results, participant info included, upcoming endpoint
    - _Requirements: R5, R10_
  - [ ] 13.3 Create `tests/Feature/MeetingActionTest.php`
    - Tests: accept (pending→accepted), decline with reason, cancel by organizer/invitee, invitee cannot cancel pending, reschedule preserves history, complete requires past date, all 403/404/422 errors
    - _Requirements: R2, R3, R4, R11_
  - [ ] 13.4 Create `tests/Feature/MeetingNoteTest.php`
    - Tests: add note, empty/whitespace validation, max length, non-participant 403, not-found 404
    - _Requirements: R7_
  - [ ] 13.5 Create `tests/Feature/GoogleOAuthTest.php`
    - Tests: connect returns auth_url, callback exchanges code (mocked), status check, disconnect removes token, non-auth 401
    - _Requirements: R13_
  - [ ] 13.6 Create `tests/Feature/AdminMeetingTest.php`
    - Tests: admin lists all, admin views any meeting, non-admin 403
    - _Requirements: R12_

## Task Dependency Graph

```json
{
  "waves": [
    {"id": "wave1", "tasks": ["1"]},
    {"id": "wave2", "tasks": ["2", "3", "4", "5"]},
    {"id": "wave3", "tasks": ["6"]},
    {"id": "wave4", "tasks": ["7", "8", "9", "10", "11"]},
    {"id": "wave5", "tasks": ["12"]},
    {"id": "wave6", "tasks": ["13"]}
  ]
}
```

## Notes

- All controllers are intentionally short (~50-100 lines each) for readability
- Business logic lives in services, controllers only handle HTTP concerns (validate, authorize, delegate, respond)
- Google API failures are gracefully handled — the meeting system works with or without Google connected
- The `google/apiclient` package is a well-maintained first-party Google library
