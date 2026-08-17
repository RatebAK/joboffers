# Requirements Document

## Introduction

The Meetings feature enables employers and job seekers to schedule, manage, and track meetings within the CV AI Job Platform. This feature bridges the gap between initial interest (job applications, direct offers) and actual interviews/conversations by providing a structured scheduling system. Both parties can propose meeting times, accept or decline invitations, reschedule, and cancel meetings. The system supports different meeting types (in-person, phone, video call) and provides a clear calendar-like view of upcoming and past meetings for both roles. For video call meetings, the platform integrates with the Google Calendar API to automatically generate Google Meet links when a meeting is accepted, providing a seamless video conferencing experience. Users can connect their Google account via OAuth 2.0 to enable this integration, with a manual fallback for users who have not configured Google connectivity.

## Glossary

- **Meeting**: A scheduled event between an Employer and a Job_Seeker with a defined date, time, duration, type, and location or link
- **Meeting_Service**: The backend service responsible for meeting business logic, scheduling validation, and conflict detection
- **Meeting_Controller**: The API controller handling HTTP requests related to meeting operations
- **Google_Meet_Service**: The backend service responsible for Google Calendar API integration, Google Meet link generation, and OAuth token management
- **Organizer**: The authenticated user (Employer or Job_Seeker) who creates the meeting invitation
- **Invitee**: The user (Employer or Job_Seeker) who receives and responds to a meeting invitation
- **Meeting_Status**: The current state of a meeting — one of: pending, accepted, declined, cancelled, rescheduled, completed
- **Meeting_Type**: The format of the meeting — one of: in_person, phone_call, video_call
- **Time_Slot**: A specific date, start time, and duration proposed for a meeting
- **Meeting_Note**: An optional text message attached to a meeting by either participant
- **Conflict**: A scheduling overlap where a user already has an accepted meeting during the proposed time slot
- **Google_OAuth_Token**: A stored OAuth 2.0 access token and refresh token pair linking a user's account to their Google account for calendar integration
- **Meet_Link**: A Google Meet video conference URL automatically generated via the Google Calendar API when a video_call meeting is accepted
- **Google_Calendar_Event**: A calendar event created in Google Calendar with conferenceData containing a Google Meet link

## Requirements

### Requirement 1: Create a Meeting Invitation

**User Story:** As an employer or job seeker, I want to create a meeting invitation and send it to the other party, so that I can schedule an interview or discussion.

#### Acceptance Criteria

1. WHEN an authenticated Employer sends a meeting creation request with a valid Job_Seeker user ID, THE Meeting_Controller SHALL create a new Meeting with status "pending" and return the created Meeting resource
2. WHEN an authenticated Job_Seeker sends a meeting creation request with a valid Employer user ID, THE Meeting_Controller SHALL create a new Meeting with status "pending" and return the created Meeting resource
3. THE Meeting_Controller SHALL require the following fields for meeting creation: invitee_id, title (1 to 255 characters), meeting_type (one of: in_person, phone_call, video_call), proposed_date (a valid date not in the past relative to server time), proposed_start_time, and proposed_duration_minutes (an integer between 15 and 480)
4. THE Meeting_Controller SHALL accept an optional location_or_link text field (maximum 500 characters) for meetings with meeting_type "in_person" or "phone_call", storing the physical address or phone number for the meeting
5. WHEN a meeting creation request has meeting_type "video_call", THE Meeting_Service SHALL ignore any provided location_or_link value, because a Google Meet link will be auto-generated upon acceptance (or the Organizer may manually provide a link if Google integration is not configured)
6. WHEN a meeting creation request contains an invitee_id that does not correspond to an existing user with the opposite role, or the invitee_id matches the authenticated user's own ID, THE Meeting_Controller SHALL return a 422 validation error with a descriptive message
7. WHEN a meeting creation request is missing required fields or contains a meeting_type value not in the allowed set, THE Meeting_Controller SHALL return a 422 validation error listing all invalid or missing fields
8. THE Meeting_Service SHALL store the organizer_id from the authenticated user's JWT token on the created Meeting
9. IF a meeting creation request specifies a proposed_date that is in the past relative to the current server date, THEN THE Meeting_Controller SHALL return a 422 validation error indicating the proposed date must be in the future

### Requirement 2: Respond to a Meeting Invitation

**User Story:** As a meeting invitee, I want to accept or decline a meeting invitation, so that I can confirm my availability or inform the organizer I cannot attend.

#### Acceptance Criteria

1. WHEN an authenticated Invitee sends an accept request for a pending Meeting, THE Meeting_Controller SHALL update the Meeting status to "accepted" and return the updated Meeting resource
2. WHEN an authenticated Invitee sends a decline request for a pending Meeting, THE Meeting_Controller SHALL update the Meeting status to "declined" and return the updated Meeting resource
3. IF a user attempts to respond to a Meeting where the user is not the Invitee, THEN THE Meeting_Controller SHALL return a 403 forbidden error
4. IF a user attempts to accept or decline a Meeting that is not in "pending" status, THEN THE Meeting_Controller SHALL return a 422 error indicating the Meeting cannot be responded to in its current state
5. WHEN an Invitee declines a Meeting, THE Meeting_Controller SHALL allow the Invitee to include an optional decline_reason text field with a maximum length of 500 characters
6. IF a user attempts to respond to a Meeting with an ID that does not correspond to an existing Meeting, THEN THE Meeting_Controller SHALL return a 404 not found error
7. IF the decline_reason field exceeds 500 characters, THEN THE Meeting_Controller SHALL return a 422 validation error indicating the field exceeds the maximum allowed length

### Requirement 3: Cancel a Meeting

**User Story:** As a meeting participant (organizer or invitee), I want to cancel a meeting, so that I can inform the other party when I can no longer attend.

#### Acceptance Criteria

1. WHEN an authenticated Organizer sends a cancel request for a Meeting in "pending", "accepted", or "rescheduled" status, THE Meeting_Controller SHALL update the Meeting status to "cancelled" and return the updated Meeting resource
2. WHEN an authenticated Invitee sends a cancel request for a Meeting in "accepted" or "rescheduled" status, THE Meeting_Controller SHALL update the Meeting status to "cancelled" and return the updated Meeting resource
3. WHEN a user attempts to cancel a Meeting that is in "cancelled", "declined", or "completed" status, THE Meeting_Controller SHALL return a 422 error indicating the Meeting cannot be cancelled in its current state
4. WHEN an authenticated Invitee sends a cancel request for a Meeting in "pending" status, THE Meeting_Controller SHALL return a 422 error indicating that a pending meeting must be declined rather than cancelled
5. WHEN a participant cancels a Meeting, THE Meeting_Controller SHALL accept an optional cancellation_reason text field with a maximum length of 500 characters
6. WHEN a user attempts to cancel a Meeting where the user is neither the Organizer nor the Invitee, THE Meeting_Controller SHALL return a 403 forbidden error
7. WHEN a user sends a cancel request with a Meeting ID that does not exist, THE Meeting_Controller SHALL return a 404 not found error

### Requirement 4: Reschedule a Meeting

**User Story:** As a meeting organizer, I want to reschedule a meeting to a new time, so that I can adjust when the original time no longer works.

#### Acceptance Criteria

1. WHEN an authenticated Organizer sends a reschedule request with a new proposed_date, proposed_start_time, and proposed_duration_minutes for a Meeting in "pending", "accepted", or "rescheduled" status, THE Meeting_Controller SHALL update the Meeting with the new time details, set the status to "rescheduled", and return the updated Meeting resource
2. WHEN a Meeting status is set to "rescheduled", THE Meeting_Service SHALL allow the Invitee to accept or decline the Meeting as if it were a new pending invitation
3. IF a user attempts to reschedule a Meeting that is in "cancelled", "declined", or "completed" status, THEN THE Meeting_Controller SHALL return a 422 error indicating the Meeting cannot be rescheduled in its current state
4. IF a user attempts to reschedule a Meeting where the user is not the Organizer, THEN THE Meeting_Controller SHALL return a 403 forbidden error
5. WHEN a Meeting is rescheduled, THE Meeting_Service SHALL append the previous proposed_date, proposed_start_time, and proposed_duration_minutes to a previous_schedules array field on the Meeting, preserving the full reschedule history
6. IF a reschedule request contains a proposed_date that is not a future date relative to the current server time, THEN THE Meeting_Controller SHALL return a 422 validation error indicating the proposed date must be in the future
7. IF a reschedule request contains a proposed_duration_minutes value less than 15 or greater than 480, THEN THE Meeting_Controller SHALL return a 422 validation error indicating the duration must be between 15 and 480 minutes

### Requirement 5: List Meetings

**User Story:** As an employer or job seeker, I want to view a list of my meetings filtered by status and date range, so that I can manage my schedule effectively.

#### Acceptance Criteria

1. WHEN an authenticated user requests their meetings list, THE Meeting_Controller SHALL return a paginated list of all Meetings where the user is either the Organizer or the Invitee, with a default of 15 items per page and a maximum of 100 items per page
2. THE Meeting_Controller SHALL support filtering meetings by status query parameter accepting one or more comma-separated Meeting_Status values
3. THE Meeting_Controller SHALL support filtering meetings by date range using from_date and to_date query parameters in ISO 8601 date format (YYYY-MM-DD), where from_date and to_date may be provided independently
4. THE Meeting_Controller SHALL sort meetings by proposed_date in ascending order by default
5. THE Meeting_Controller SHALL support a sort_direction query parameter accepting "asc" or "desc" values
6. THE Meeting_Controller SHALL include the other participant's basic profile information (name, email, company name for employers) in each Meeting list item
7. THE Meeting_Controller SHALL follow the platform pagination format including: data, current_page, per_page, total, total_pages, next_page, prev_page
8. IF the status query parameter contains a value that is not a valid Meeting_Status, THEN THE Meeting_Controller SHALL return a 422 validation error indicating the invalid status value
9. IF from_date or to_date query parameters contain a value that is not a valid ISO 8601 date, THEN THE Meeting_Controller SHALL return a 422 validation error indicating the invalid date format
10. WHEN no meetings match the applied filters, THE Meeting_Controller SHALL return a successful response with an empty data array and a total of 0

### Requirement 6: View Meeting Details

**User Story:** As a meeting participant, I want to view the full details of a specific meeting, so that I can see all information including notes and scheduling history.

#### Acceptance Criteria

1. WHEN an authenticated user requests a specific Meeting by ID where the user is the Organizer or the Invitee, THE Meeting_Controller SHALL return the Meeting resource including: title, meeting_type, proposed_date, proposed_start_time, proposed_duration_minutes, status, organizer_id, invitee_id, location_or_link (for in_person and phone_call types), meet_link (for video_call type, may be null), google_calendar_event_id (if present), notes (each with author_id, content, created_at), previous_schedules (if rescheduled), decline_reason, cancellation_reason, created_at, and updated_at
2. IF a user requests a Meeting where the user is neither the Organizer nor the Invitee, THEN THE Meeting_Controller SHALL return a 403 forbidden error
3. IF a user requests a Meeting with an ID that does not correspond to any existing Meeting, THEN THE Meeting_Controller SHALL return a 404 not found error
4. THE Meeting_Controller SHALL include the other participant's profile information in the response containing: name, email, and company name (for employer participants)

### Requirement 7: Add Notes to a Meeting

**User Story:** As a meeting participant, I want to add notes to a meeting, so that I can share agenda items, preparation materials, or follow-up notes with the other party.

#### Acceptance Criteria

1. WHEN an authenticated participant sends a note creation request for a Meeting, THE Meeting_Controller SHALL append the note to the Meeting's notes array with the author_id, content, and created_at timestamp
2. WHEN a user attempts to add a note to a Meeting where the user is not a participant, THE Meeting_Controller SHALL return a 403 forbidden error
3. THE Meeting_Controller SHALL require a non-empty content field (not blank or whitespace-only) with a maximum length of 2000 characters for note creation
4. WHEN a note creation request has an empty, whitespace-only, or missing content field, THE Meeting_Controller SHALL return a 422 validation error
5. IF a user attempts to add a note to a Meeting with a non-existent ID, THEN THE Meeting_Controller SHALL return a 404 not found error

### Requirement 8: Scheduling Conflict Detection

**User Story:** As a meeting organizer, I want the system to warn me about scheduling conflicts, so that I can avoid double-booking myself or the invitee.

#### Acceptance Criteria

1. WHEN a meeting creation or reschedule request overlaps with an existing accepted Meeting for the Organizer, THE Meeting_Service SHALL include an organizer_conflicts array in the response where each entry contains the conflicting Meeting's ID, proposed_date, proposed_start_time, and proposed_duration_minutes
2. WHEN a meeting creation or reschedule request overlaps with an existing accepted Meeting for the Invitee, THE Meeting_Service SHALL include an invitee_conflicts array in the response where each entry contains the conflicting Meeting's ID, proposed_date, proposed_start_time, and proposed_duration_minutes
3. IF conflicts are detected for either the Organizer or the Invitee, THEN THE Meeting_Service SHALL still create or reschedule the Meeting successfully, treating conflicts as warnings included in the response rather than blocking errors
4. THE Meeting_Service SHALL detect overlap by comparing the proposed time range (proposed_start_time to proposed_start_time + proposed_duration_minutes) against existing accepted Meetings for both participants, where two meetings overlap if one starts strictly before the other ends and ends strictly after the other starts (adjacent meetings sharing only an endpoint do not conflict)

### Requirement 9: Meeting Notifications

**User Story:** As a meeting participant, I want to receive notifications about meeting activity, so that I stay informed about new invitations, responses, and changes.

#### Acceptance Criteria

1. WHEN a new Meeting is created, THE Meeting_Service SHALL create an in-app notification for the Invitee containing the notification type "meeting_invitation", the Meeting ID, the meeting title, the Organizer's name, and the proposed date
2. WHEN a Meeting is accepted, THE Meeting_Service SHALL create an in-app notification for the Organizer containing the notification type "meeting_accepted", the Meeting ID, the meeting title, and the Invitee's name
3. WHEN a Meeting is declined, THE Meeting_Service SHALL create an in-app notification for the Organizer containing the notification type "meeting_declined", the Meeting ID, the meeting title, and the Invitee's name
4. WHEN a Meeting is cancelled, THE Meeting_Service SHALL create an in-app notification for the non-cancelling participant containing the notification type "meeting_cancelled", the Meeting ID, the meeting title, and the cancelling participant's name
5. WHEN a Meeting is rescheduled, THE Meeting_Service SHALL create an in-app notification for the Invitee containing the notification type "meeting_rescheduled", the Meeting ID, the meeting title, the new proposed_date, and the new proposed_start_time
6. IF notification creation fails, THEN THE Meeting_Service SHALL log the failure but SHALL NOT prevent the triggering meeting operation from completing successfully
7. THE Meeting_Service SHALL store each notification with a read status defaulting to false, a created_at timestamp, and a reference to the recipient user ID

### Requirement 10: Upcoming Meetings Summary

**User Story:** As an employer or job seeker, I want to see a quick summary of my upcoming meetings, so that I can stay on top of my interview schedule without navigating to the full meetings list.

#### Acceptance Criteria

1. WHEN an authenticated user requests their upcoming meetings summary, THE Meeting_Controller SHALL return up to 5 accepted Meetings where the user is either the Organizer or the Invitee, with proposed_date in the future relative to the current server time, sorted by proposed_date ascending
2. IF the authenticated user has fewer than 5 upcoming accepted Meetings, THEN THE Meeting_Controller SHALL return only the available Meetings as an array (which may be empty)
3. THE Meeting_Controller SHALL include in each summary item: the other participant's name, meeting title, proposed_date, proposed_start_time, proposed_duration_minutes, and meeting_type
4. IF an unauthenticated request is made to the upcoming meetings summary endpoint, THEN THE Meeting_Controller SHALL return a 401 unauthorized error

### Requirement 11: Mark Meeting as Completed

**User Story:** As a meeting organizer, I want to mark a meeting as completed after it takes place, so that I can keep my meeting history accurate.

#### Acceptance Criteria

1. WHEN an authenticated Organizer sends a complete request for a Meeting in "accepted" status where the Meeting's proposed_date is before the current server date, THE Meeting_Controller SHALL update the Meeting status to "completed" and return the updated Meeting resource
2. IF a user attempts to mark a Meeting as completed that is not in "accepted" status, THEN THE Meeting_Controller SHALL return a 422 error indicating only accepted meetings can be marked as completed
3. IF a user attempts to mark a Meeting as completed where the proposed_date is on or after the current server date, THEN THE Meeting_Controller SHALL return a 422 error indicating the meeting has not yet occurred
4. IF a user attempts to mark a Meeting as completed where the user is not the Organizer, THEN THE Meeting_Controller SHALL return a 403 forbidden error
5. IF a user attempts to mark a Meeting as completed with a non-existent Meeting ID, THEN THE Meeting_Controller SHALL return a 404 not found error

### Requirement 12: Admin Meeting Oversight

**User Story:** As an admin, I want to view all meetings on the platform, so that I can monitor platform activity and resolve disputes if needed.

#### Acceptance Criteria

1. WHEN an authenticated Admin requests the meetings list, THE Meeting_Controller SHALL return a paginated list of all Meetings on the platform following the platform pagination format including: data, current_page, per_page, total, total_pages, next_page, prev_page
2. THE Meeting_Controller SHALL support the same filtering options (status, date range) and sort_direction parameter for admin meeting listing as defined in the participant listing
3. WHEN an authenticated Admin requests a specific Meeting by ID, THE Meeting_Controller SHALL return the full Meeting resource including both participants' basic profile information (name, email, company name for employers) regardless of participant ownership
4. IF a non-Admin authenticated user attempts to access the admin meetings list or admin meeting detail endpoint, THEN THE Meeting_Controller SHALL return a 403 forbidden error
5. WHEN an authenticated Admin requests a Meeting with a non-existent ID, THE Meeting_Controller SHALL return a 404 not found error


### Requirement 13: Google OAuth Integration for Google Meet

**User Story:** As an employer or job seeker, I want to connect my Google account to the platform, so that the system can create Google Calendar events with Google Meet links on my behalf.

#### Acceptance Criteria

1. THE Meeting_Controller SHALL expose an endpoint to initiate the Google OAuth 2.0 authorization flow, redirecting the authenticated user to Google's consent screen with scopes for Google Calendar event management
2. WHEN Google redirects back with an authorization code, THE Google_Meet_Service SHALL exchange the code for an access token and refresh token, and store the Google_OAuth_Token associated with the authenticated user's account
3. IF the OAuth callback receives an error parameter from Google (e.g., access_denied), THEN THE Google_Meet_Service SHALL return a descriptive error message to the user without storing any token
4. THE Google_Meet_Service SHALL require the following environment variables to be configured: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REDIRECT_URI
5. WHERE the optional GOOGLE_SERVICE_ACCOUNT_CREDENTIALS environment variable is set, THE Google_Meet_Service SHALL support service account authentication with domain-wide delegation as an alternative to per-user OAuth
6. WHEN an authenticated user requests their Google integration status, THE Meeting_Controller SHALL return whether the user has a valid Google_OAuth_Token stored (connected: true/false) without exposing the token values
7. WHEN an authenticated user requests to disconnect their Google account, THE Google_Meet_Service SHALL delete the stored Google_OAuth_Token for that user and return a success confirmation
8. IF a user attempts to initiate Google OAuth or check integration status without authentication, THEN THE Meeting_Controller SHALL return a 401 unauthorized error

### Requirement 14: Automatic Google Meet Link Generation

**User Story:** As a meeting participant, I want a Google Meet link to be automatically generated when a video call meeting is accepted, so that both parties have a ready-to-use video conferencing link without manual setup.

#### Acceptance Criteria

1. WHEN a Meeting with meeting_type "video_call" is accepted and the Organizer has a valid Google_OAuth_Token, THE Google_Meet_Service SHALL create a Google_Calendar_Event using the Google Calendar API with conferenceData containing a createRequest of conferenceDataVersion 1 and conferenceSolutionKey type "hangoutsMeet"
2. WHEN the Google_Calendar_Event is successfully created, THE Google_Meet_Service SHALL extract the Google Meet URL from the conferenceData.entryPoints response and store it in the meet_link field on the Meeting document
3. WHEN a Meeting with meeting_type "video_call" is accepted and the Organizer does not have a valid Google_OAuth_Token, THE Meeting_Service SHALL set the meet_link field to null and include a warning in the response indicating that the Organizer has not connected Google integration
4. WHILE the Organizer's Google_OAuth_Token access token is expired, THE Google_Meet_Service SHALL use the stored refresh token to obtain a new access token before making the Calendar API request
5. IF the refresh token exchange fails (e.g., token revoked by user), THEN THE Google_Meet_Service SHALL mark the Google_OAuth_Token as invalid, set meet_link to null, and include a warning in the meeting acceptance response indicating that re-authorization is required
6. THE Google_Calendar_Event SHALL include the meeting title as the event summary, the proposed_date and proposed_start_time as the event start, the calculated end time based on proposed_duration_minutes, and both participants' email addresses as attendees
7. WHEN a Meeting with meeting_type "video_call" is accepted and the Organizer does not have Google integration configured, THE Meeting_Controller SHALL accept an optional meet_link field in the accept request body allowing the Organizer to manually provide a video conference URL (maximum 500 characters)
8. IF the Google Calendar API returns an error during event creation, THEN THE Google_Meet_Service SHALL log the error, set meet_link to null, and allow the meeting acceptance to complete successfully without blocking

### Requirement 15: Google Calendar Event Lifecycle Management

**User Story:** As a meeting participant, I want Google Calendar events to stay synchronized with meeting status changes, so that my Google Calendar accurately reflects my current schedule.

#### Acceptance Criteria

1. WHEN an accepted Meeting with an associated Google_Calendar_Event is cancelled, THE Google_Meet_Service SHALL delete the corresponding Google_Calendar_Event from both participants' calendars
2. WHEN an accepted Meeting with an associated Google_Calendar_Event is rescheduled, THE Google_Meet_Service SHALL update the Google_Calendar_Event with the new proposed_date, proposed_start_time, and calculated end time
3. IF the Google Calendar API call to delete or update an event fails, THEN THE Google_Meet_Service SHALL log the failure but SHALL NOT prevent the meeting status change from completing successfully
4. WHEN a Meeting is rescheduled and then re-accepted, THE Google_Meet_Service SHALL update the existing Google_Calendar_Event rather than creating a duplicate event
5. THE Meeting_Service SHALL store the google_calendar_event_id on the Meeting document when a Google_Calendar_Event is successfully created, enabling future updates and deletions
