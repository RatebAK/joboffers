# Implementation Plan: Admin Business Intelligence

## Overview

All tasks are additive. Existing controllers, models, and routes are not modified except where noted (observer registration in `AppServiceProvider`, adding `partner_type` to `Employer.$fillable`, and hooking into existing controllers by dispatching notifications from a separate observer — not inline code changes).

## Tasks

- [x] 1. Scaffold foundations: models, migrations, and AuditLogService
  - [x] 1.1 Create `AuditLog` Eloquent model
    - Extends `MongoDB\Laravel\Eloquent\Model`, collection `audit_logs`
    - Fields: `action`, `actor_id`, `actor_name`, `target_id`, `target_type`, `metadata`, `created_at` (no `updated_at`)
    - Add `$fillable` and disable `updated_at` timestamps via `const UPDATED_AT = null`
    - _Requirements: 9.1, 9.6_
  - [x] 1.2 Create `Notification` Eloquent model
    - Extends `MongoDB\Laravel\Eloquent\Model`, collection `notifications`
    - Fields: `user_id`, `type`, `message`, `related_entity_id`, `related_entity_type`, `read_at`, `created_at`
    - Cast `read_at` as `datetime`
    - _Requirements: 10.6_
  - [x] 1.3 Create MongoDB migrations for `audit_logs` and `notifications` collections
    - Use `Schema::create()` with index on `audit_logs.actor_id` and `audit_logs.created_at`
    - Use index on `notifications.user_id` and `notifications.read_at`
    - _Requirements: 9.1, 10.6_
  - [x] 1.4 Create `AuditLogService` with static `log()` method
    - Signature: `public static function log(string $action, string $actorId, string $actorName, ?string $targetId, ?string $targetType, array $metadata = []): void`
    - Creates `AuditLog` document synchronously
    - _Requirements: 9.6_
  - [x]* 1.5 Write unit test for `AuditLogService`
    - Assert document is written with correct fields
    - Assert `updated_at` is never set on created documents
    - **Property 10: Audit log document count is monotonically non-decreasing**
    - **Validates: Requirements 9.5, 9.6**

- [x] 2. Notification system — model, observer, and API
  - [x] 2.1 Create `NotificationObserver` and register in `AppServiceProvider`
    - Observes `Application` (on `updated` when `status` changes), `DirectOffer` (on `created`), `Employer` (on `updated` when `status` changes to `approved` or `rejected`)
    - Each handler creates a `Notification` record for the affected user
    - Register in `AppServiceProvider::boot()` via `Application::observe(NotificationObserver::class)` etc.
    - _Requirements: 10.1, 10.2, 10.3, 10.4_
  - [ ]* 2.2 Write property test for notification observer
    - **Property 9: Event notifications are created for all affected users**
    - For any application status update, assert a Notification record exists for the application's user_id
    - Use `Notification::fake()` pattern or direct DB assertion after model save
    - **Validates: Requirements 10.1, 10.2, 10.3, 10.4**
  - [x] 2.3 Create `NotificationController` with four endpoints
    - `GET /notifications` — paginated, newest first, scoped to `auth()->id()`
    - `POST /notifications/{id}/read` — sets `read_at`, returns updated notification, 404 if not found or not owned
    - `POST /notifications/read-all` — bulk update all unread for current user
    - `GET /notifications/unread-count` — returns integer count
    - Each endpoint has a rich Scribe doc block marked **[NEW API]** explaining request/response contract for frontend
    - _Requirements: 10.6, 10.7, 10.8, 10.9_
  - [x]* 2.4 Write property tests for notification endpoints
    - **Property 11: Notification unread count matches database state** — assert GET /notifications/unread-count equals DB count where read_at IS NULL
    - **Property 12: Mark-all-read leaves zero unread notifications** — seed N unread notifications, call read-all, assert unread count is 0
    - **Validates: Requirements 10.8, 10.9**
  - [x] 2.5 Create email notification classes: `ApplicationStatusChanged`, `DirectOfferReceived`, `EmployerApprovalDecision`
    - Each extends `Illuminate\Notifications\Notification` with `Queueable`
    - Returns `MailMessage` with subject and body describing the event
    - Dispatched from `NotificationObserver` via `$user->notify(new ApplicationStatusChanged($application))`
    - _Requirements: 10.1, 10.3, 10.4_
  - [ ]* 2.6 Write feature test for email notification dispatch
    - Use `Notification::fake()`, trigger model state changes, assert correct notification class was sent to correct user
    - Test that email failure (mocked) does not throw — in-app notification still exists
    - _Requirements: 10.10_
  - [x] 2.7 Register notification routes in `routes/api.php` under `jwt.auth` middleware (no role restriction — available to all authenticated users)
    - _Requirements: 10.6_

- [x] 3. Checkpoint — Ensure all tests pass, ask the user if questions arise.

- [x] 4. Admin reporting — churn, funnel, pipeline, categories
  - [x] 4.1 Create `ChurnReportService`
    - `getChurnedEmployers(int $windowDays): Collection` — users with employer role whose most recent JobPost is older than windowDays, or have no posts
    - `getChurnedSeekers(): Collection` — JobSeekerProfile where cv_file_path != null, with zero Application records
    - `toCsv(Collection $employers, Collection $seekers): string` — returns CSV string with two sections
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_
  - [ ]* 4.2 Write property tests for `ChurnReportService`
    - **Property 1: Churn report excludes active users** — seed employers with recent posts, assert they are not in results
    - **Property 2: Churn report entries contain all required fields** — assert each entry has user_id, name, email, registration date, type-specific date
    - **Validates: Requirements 1.1, 1.2, 1.4, 1.5**
  - [x] 4.3 Create `ConversionFunnelService`
    - `compute(): array` — returns four-stage array with counts and drop-off percentages
    - Handle zero-count stages without division-by-zero
    - _Requirements: 2.1, 2.2, 2.3, 2.4_
  - [ ]* 4.4 Write property test for `ConversionFunnelService`
    - **Property 3: Funnel counts are monotonically non-increasing with correct drop-off**
    - Seed varying counts, assert each stage count ≤ previous, assert drop-off formula is correct
    - Assert zero-stage edge case returns drop_off=0 for prev=0
    - **Validates: Requirements 2.1, 2.2, 2.4**
  - [x] 4.5 Create `AdminReportingController` with four methods
    - `GET /api/admin/reports/churn?window_days=30&format=csv` — calls ChurnReportService, streams CSV or returns JSON
    - `GET /api/admin/reports/funnel` — calls ConversionFunnelService, returns JSON
    - `GET /api/admin/reports/pipeline?daily_revenue_per_employer=10` — computes pending employer stats inline
    - `GET /api/admin/reports/categories?sort_by=applications&limit=10` — aggregates JobPost + Application counts by category
    - Each method has a rich Scribe doc block marked **[NEW API]** with full request/response schema and field descriptions
    - _Requirements: 1.1–1.6, 2.1–2.4, 3.1–3.4, 4.1–4.4_
  - [x]* 4.6 Write feature tests for `AdminReportingController`
    - **Property 4: Estimated lost revenue calculation is exact** — seed known pending employers, assert revenue = P × W × R
    - **Property 5: Top categories sort order matches sort_by parameter** — assert descending order for both sort modes
    - Test CSV Content-Type and Content-Disposition headers for churn report
    - Test default window_days=30 when param is missing
    - **Validates: Requirements 1.3, 1.6, 3.2, 3.3, 4.2, 4.3**
  - [x] 4.7 Register admin reporting routes in `routes/api.php` under `jwt.auth` + `role:admin` middleware

- [x] 5. Checkpoint — Ensure all tests pass, ask the user if questions arise.

- [x] 6. Talent market report
  - [x] 6.1 Create `TalentReportService`
    - `compute(int $limit, ?string $industry): array` — aggregates ai_skills counts, computes ATS avg/median/min/max
    - Scopes to job_roles filter when industry is provided
    - Throws `\InvalidArgumentException` (caught in controller → 422) when profile count < 5
    - Never includes name/email/phone/user_id/ai_contact_fields in returned data
    - `toCsv(array $data): string` — serializes to CSV
    - _Requirements: 6.1–6.6_
  - [x]* 6.2 Write property tests for `TalentReportService`
    - **Property 7: Talent report contains no PII** — iterate all keys in the response, assert none are in the PII field list
    - Assert ATS median is correct for even and odd array sizes (edge cases)
    - Assert industry filter scopes correctly
    - **Validates: Requirements 6.5**
  - [x] 6.3 Create `TalentReportController`
    - `GET /api/admin/reports/talent?limit=20&industry=tech&format=csv`
    - Returns JSON or CSV; delegates to `TalentReportService`
    - Catches `\InvalidArgumentException` and returns 422
    - Rich Scribe doc block marked **[NEW API]**
    - _Requirements: 6.1–6.6_
  - [x] 6.4 Register talent report route in `routes/api.php` under admin middleware

- [x] 7. Bulk B2B employer onboarding
  - [x] 7.1 Add `partner_type` to `Employer.$fillable`
    - Only change to an existing file in this entire spec — a single array entry addition
    - _Requirements: 5.2, 5.6_
  - [x] 7.2 Create `SendBulkInviteJob`
    - Accepts a `User` instance and `company_name` string
    - Sends an invitation email via Laravel's `Mail` facade with a temporary password reset link
    - Implements `ShouldQueue`
    - _Requirements: 5.1_
  - [x] 7.3 Create `BulkOnboardingService`
    - `process(UploadedFile $file): array` — reads CSV with `League\Csv\Reader`, validates required columns, creates User + Employer records, dispatches `SendBulkInviteJob`, writes audit log entry via `AuditLogService::log()`
    - Returns `['total_rows' => N, 'created' => M, 'skipped' => K, 'skipped_rows' => [...]]`
    - _Requirements: 5.1–5.7_
  - [x] 7.4 Create `BulkOnboardingRequest` form request
    - Validates `file` is required, `mimes:csv,txt`, `max:2048` (2 MB)
    - _Requirements: 5.4_
  - [x] 7.5 Create `BulkOnboardingController`
    - `POST /api/admin/onboarding/bulk` — validates via `BulkOnboardingRequest`, calls `BulkOnboardingService`, returns summary JSON
    - Rich Scribe doc block marked **[NEW API]** including CSV format spec and example response
    - _Requirements: 5.1–5.7_
  - [x]* 7.6 Write property tests for `BulkOnboardingService`
    - **Property 6: Bulk onboarding row accounting invariant** — assert created + skipped = total data rows; assert no duplicate User emails after processing
    - Use Queue::fake() to assert SendBulkInviteJob is dispatched once per created account
    - **Validates: Requirements 5.2, 5.3, 5.5**
  - [x] 7.7 Register bulk onboarding route in `routes/api.php` under admin middleware

- [x] 8. Platform broadcast
  - [x] 8.1 Create `BroadcastNotification` email notification class
    - Accepts `subject` and `body`, returns `MailMessage`
    - Implements `ShouldQueue`
    - _Requirements: 7.6_
  - [x] 8.2 Create `SendBroadcastJob`
    - Accepts a `User` instance, `subject`, and `body`
    - Sends `BroadcastNotification` email and creates a `Notification` in-app record
    - Implements `ShouldQueue`
    - _Requirements: 7.6, 10.5_
  - [x] 8.3 Create `BroadcastService`
    - `resolveRecipients(string $audience, ?array $userIds): Collection` — resolves by role or explicit IDs
    - `dispatch(Collection $recipients, string $subject, string $body): int` — dispatches `SendBroadcastJob` per recipient, returns count
    - _Requirements: 7.1–7.4_
  - [x] 8.4 Create `BroadcastRequest` form request — validates `subject` (required), `body` (required), `audience` (required_without:user_ids, in:employees,employers,all), `user_ids` (array)
    - _Requirements: 7.5_
  - [x] 8.5 Create `BroadcastController`
    - `POST /api/admin/broadcast` — validates, resolves recipients, dispatches, writes audit log, returns `{status: queued, recipient_count: N}`
    - Rich Scribe doc block marked **[NEW API]**
    - _Requirements: 7.1–7.7_
  - [x]* 8.6 Write property test for `BroadcastService`
    - **Property 8: Broadcast recipient set matches audience definition** — for each audience value, assert resolved user IDs exactly match the expected set; assert no admin is included in `audience=all`
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4**
  - [x] 8.7 Register broadcast route in `routes/api.php` under admin middleware

- [x] 9. Checkpoint — Ensure all tests pass, ask the user if questions arise.

- [x] 10. Manual CV re-analysis and audit log viewer
  - [x] 10.1 Create `AdminReanalysisController`
    - `POST /api/admin/users/{userId}/reanalyze`
    - Finds user, asserts `employee` role (404 if not found/wrong role), asserts `cv_file_path != null` (422 if missing), sets `analysis_status = processing`, dispatches existing CV analysis job, writes audit log
    - Rich Scribe doc block marked **[NEW API]**
    - _Requirements: 8.1–8.5_
  - [x]* 10.2 Write feature tests for `AdminReanalysisController`
    - Test 422 when cv_file_path is null
    - Test 404 when user not found or not employee
    - Assert `analysis_status` is set to `processing` after successful trigger
    - Assert audit log entry is written
    - _Requirements: 8.1, 8.2, 8.3, 8.4_
  - [x] 10.3 Create `AuditLogController`
    - `GET /api/admin/audit-log?action_type=broadcast_sent&date_from=2024-01-01&date_to=2024-12-31&per_page=20`
    - Paginated, filterable by `action_type` and date range
    - Returns standard pagination envelope
    - Rich Scribe doc block marked **[NEW API]** listing all valid `action_type` values
    - _Requirements: 9.1–9.5_
  - [ ]* 10.4 Write feature tests for `AuditLogController`
    - Test action_type filter returns only matching entries
    - Test date range filter returns only entries within range
    - Test that no DELETE or PUT routes exist for audit log (read-only)
    - _Requirements: 9.3, 9.4, 9.5_
  - [x] 10.5 Register re-analysis and audit log routes in `routes/api.php` under admin middleware

- [x] 11. Wire existing employer approval into audit log
  - [x] 11.1 Create a thin `EmployerObserver` that fires on `Employer` status change to `approved` or `rejected`
    - Calls `AuditLogService::log()` with action `employer_approved` or `employer_rejected`
    - Register in `AppServiceProvider` alongside `NotificationObserver`
    - This replaces needing to touch `EmployerController` — the observer handles it automatically
    - _Requirements: 9.1_
  - [x]* 11.2 Write feature test for employer approval audit log
    - Call existing POST /api/admin/employers/{id}/approve, assert AuditLog record created
    - Call existing POST /api/admin/employers/{id}/reject, assert AuditLog record created
    - _Requirements: 9.1, 9.6_

- [x] 12. End-to-end integration test suite
  - [x] 12.1 Create `tests/Feature/AdminBIEndToEndTest.php` covering the full platform flow
    - **Scenario A — Employer lifecycle + audit trail:**
      1. Seed a pending employer; assert they appear in `GET /api/admin/reports/pipeline` with correct `days_waiting` and `estimated_lost_revenue`
      2. Approve the employer via the existing `POST /api/admin/employers/{id}/approve`; assert an `employer_approved` AuditLog entry is written and an `employer_decision` Notification record exists for the employer's user
      3. Assert the approved employer no longer appears in the pipeline report
    - **Scenario B — Churn detection + re-engagement broadcast:**
      1. Seed an employer with no job posts and a job seeker with a CV but no applications; assert both appear in `GET /api/admin/reports/churn`
      2. Fire `POST /api/admin/broadcast` with `audience=all`; assert `SendBroadcastJob` is queued (via `Queue::fake()`) with the correct recipient count; assert a `broadcast_sent` AuditLog entry exists
      3. Assert `GET /api/admin/audit-log?action_type=broadcast_sent` returns the entry
    - **Scenario C — Bulk B2B onboarding:**
      1. Upload a CSV with 3 valid rows and 1 duplicate email; assert response `created=3, skipped=1`
      2. Assert `SendBulkInviteJob` is dispatched exactly 3 times (via `Queue::fake()`)
      3. Assert a `bulk_employer_onboarded` AuditLog entry exists with `metadata.created_count=3`
      4. Assert `GET /api/admin/reports/pipeline` reflects the 3 new pending employers
    - **Scenario D — CV re-analysis + notification chain:**
      1. Trigger `POST /api/admin/users/{id}/reanalyze` for a seeded employee with a CV; assert `analysis_status=processing` on the profile
      2. Assert a `cv_reanalysis_triggered` AuditLog entry is written
      3. Simulate an application status change on the employee's application; assert a `Notification` record is created for the employee
      4. Assert `GET /notifications/unread-count` for that employee returns ≥ 1
      5. Call `POST /notifications/read-all`; assert `GET /notifications/unread-count` returns `0`
    - **Scenario E — Talent report anonymity gate:**
      1. Seed 4 job seeker profiles; assert `GET /api/admin/reports/talent` returns 422 "Insufficient data"
      2. Seed a 5th profile; assert the endpoint now returns 200 with no PII fields in the response keys
    - All scenarios use `Queue::fake()` and `Mail::fake()` — no real queues or mail are exercised
    - _Requirements: 1.1, 1.2, 3.1, 3.2, 5.1, 5.5, 6.6, 7.6, 7.7, 8.1, 8.4, 9.1, 9.6, 10.1, 10.8, 10.9_

- [x] 13. Final checkpoint — Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- The only change to an existing file outside of `routes/api.php` and `AppServiceProvider` is adding `partner_type` to `Employer.$fillable`
- All new endpoints have **[NEW API]** in their Scribe doc block `@description` so the frontend developer can immediately identify them in the generated docs
- Property tests require `eris/eris` or equivalent PHP PBT library — if unavailable, implement as parameterised Pest datasets covering representative value ranges
- Queue driver must be configured (database or Redis) for broadcasts and bulk invite emails to work asynchronously
