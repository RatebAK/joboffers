# Design Document: Admin Business Intelligence

## Overview

This document describes the technical design for ten additive capabilities on the CV AI Job Platform. All new code lives in new files — no existing controllers, models, or routes are modified. The notification system hooks into existing controller actions via Laravel observers, keeping the existing controllers untouched.

The feature is split into three concerns:
1. **Reporting** — read-only aggregate endpoints (churn, funnel, categories, pipeline, talent report)
2. **Operations** — admin actions (bulk onboarding, broadcast, re-analysis, audit log)
3. **Notifications** — in-app + email event system wired to existing model changes

---

## Architecture

```
New Controllers (app/Http/Controllers/API/)
├── AdminReportingController     — churn, funnel, pipeline, top categories
├── TalentReportController       — anonymized market report
├── BulkOnboardingController     — CSV employer upload
├── BroadcastController          — platform announcements
├── AdminReanalysisController    — manual CV re-analysis trigger
├── AuditLogController           — read-only audit log viewer
└── NotificationController       — user notification inbox

New Models (app/Models/)
├── AuditLog                     — immutable action log
└── Notification                 — in-app notification per user

New Services (app/Services/)
├── ChurnReportService           — queries for churned users, produces array/CSV
├── ConversionFunnelService      — computes funnel counts and drop-off rates
├── TalentReportService          — aggregates skills + ATS stats, produces array/CSV
├── BulkOnboardingService        — parses CSV, creates users, dispatches invite jobs
├── BroadcastService             — resolves audience, dispatches broadcast jobs
└── AuditLogService              — single write method used by all loggable actions

New Jobs (app/Jobs/)
├── SendBroadcastJob             — sends one email + creates one in-app notification per recipient
└── SendBulkInviteJob            — sends one invite email per onboarded employer

New Notifications (app/Notifications/)
├── ApplicationStatusChanged     — email to employee when application status updates
├── DirectOfferReceived          — email to employee when offer is sent
├── EmployerApprovalDecision     — email to employer when approved/rejected
└── BroadcastNotification        — email for admin broadcasts

New Observer (app/Observers/)
└── NotificationObserver         — watches Application, DirectOffer, Employer for state changes

New Requests (app/Http/Requests/)
├── BulkOnboardingRequest
├── BroadcastRequest
└── TalentReportRequest

New Migrations (database/migrations/)
├── create_audit_logs_collection
└── create_notifications_collection
```

The flow for admin approval notifications:
```
POST /api/admin/employers/{id}/approve
  → EmployerController::approve() [unchanged]
  → Employer model saved with status=approved
  → NotificationObserver::updated(Employer) fires
  → Creates Notification record for employer user
  → Dispatches EmployerApprovalDecision mail (queued)
```

---

## Components and Interfaces

### Controllers

**AdminReportingController** — `GET /api/admin/reports/churn`, `GET /api/admin/reports/funnel`, `GET /api/admin/reports/pipeline`, `GET /api/admin/reports/categories`

Each method calls the relevant service, then either returns JSON or streams a CSV response.

**TalentReportController** — `GET /api/admin/reports/talent`

Calls `TalentReportService`, returns JSON or CSV. Enforces minimum 5 profile threshold.

**BulkOnboardingController** — `POST /api/admin/onboarding/bulk`

Validates the file upload via `BulkOnboardingRequest`, delegates to `BulkOnboardingService`, returns the summary JSON.

**BroadcastController** — `POST /api/admin/broadcast`

Validates via `BroadcastRequest`, calls `BroadcastService`, writes audit log entry, returns queued status + recipient count.

**AdminReanalysisController** — `POST /api/admin/users/{userId}/reanalyze`

Finds the user's `JobSeekerProfile`, validates CV exists, sets `analysis_status = processing`, dispatches the existing `AnalyzeCvJob` (or equivalent), writes audit log.

**AuditLogController** — `GET /api/admin/audit-log`

Queries `AuditLog` with optional filters, returns paginated JSON.

**NotificationController** — `GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all`, `GET /notifications/unread-count`

Available to all authenticated users (not just admin). Scoped to `auth()->id()`.

---

### Services

**ChurnReportService**

```php
public function getChurnedEmployers(int $windowDays): Collection
public function getChurnedSeekers(): Collection
public function toCsv(Collection $employers, Collection $seekers): string
```

Employer churn query: users with `employer` role whose most recent `JobPost.created_at` is older than `$windowDays` days, OR who have no job posts at all.

Seeker churn query: `JobSeekerProfile` records where `cv_file_path` is not null, joined against `Application` — returning users with zero application records.

**ConversionFunnelService**

```php
public function compute(): array
```

Returns:
```json
{
  "stages": [
    { "stage": "registered",   "count": 500, "drop_off_pct": null },
    { "stage": "cv_uploaded",  "count": 320, "drop_off_pct": 36.0 },
    { "stage": "applied",      "count": 180, "drop_off_pct": 43.75 },
    { "stage": "hired",        "count": 42,  "drop_off_pct": 76.67 }
  ]
}
```

Each stage derived from:
- `registered`: `User` count with `employee` role
- `cv_uploaded`: `JobSeekerProfile` where `cv_file_path != null`
- `applied`: distinct `user_id` count in `Application`
- `hired`: distinct `user_id` in `Application` where `status = hired`

**TalentReportService**

```php
public function compute(int $limit, ?string $industry): array
public function toCsv(array $data): string
```

Skills aggregation: iterates `JobSeekerProfile.ai_skills`, counts occurrences via PHP (consistent with existing `adminAnalytics` pattern to avoid MongoDB aggregation segfault risk).

ATS median: sorts the collected `ats_score` values, picks midpoint (or average of two midpoints for even count).

**BulkOnboardingService**

```php
public function process(UploadedFile $file): array
```

Reads CSV with `League\Csv\Reader` (already available via `league/csv` — standard Laravel ecosystem). Creates `User` with random temp password, creates `Employer` with `status=pending` and `partner_type` if supplied. Dispatches `SendBulkInviteJob` per created user. Returns summary array.

**BroadcastService**

```php
public function resolveRecipients(string $audience, ?array $userIds): Collection
public function dispatch(Collection $recipients, string $subject, string $body): int
```

Resolves user IDs from audience string or explicit array, then dispatches `SendBroadcastJob` per recipient. Returns recipient count.

**AuditLogService**

```php
public static function log(string $action, string $actorId, ?string $targetId, ?string $targetType, array $metadata = []): void
```

Static method for ease of calling from anywhere without injection overhead. Creates an `AuditLog` document synchronously.

---

## Data Models

### AuditLog

```php
// collection: audit_logs
[
  '_id'          => ObjectId,
  'action'       => string,  // employer_approved | employer_rejected | broadcast_sent | cv_reanalysis_triggered | bulk_employer_onboarded
  'actor_id'     => string,  // user _id of admin who performed action
  'actor_name'   => string,  // denormalised for display without join
  'target_id'    => string|null,
  'target_type'  => string|null,  // "User" | "Employer" | null
  'metadata'     => array,   // arbitrary extra context
  'created_at'   => datetime,
]
```

No `updated_at` — this collection is append-only.

### Notification

```php
// collection: notifications
[
  '_id'                => ObjectId,
  'user_id'            => string,   // recipient
  'type'               => string,   // application_status_changed | direct_offer_received | employer_decision | broadcast
  'message'            => string,   // human-readable text
  'related_entity_id'  => string|null,
  'related_entity_type'=> string|null,  // "Application" | "DirectOffer" | "Employer"
  'read_at'            => datetime|null,
  'created_at'         => datetime,
]
```

### Employer model additions (no migration needed — MongoDB is schemaless)

The `partner_type` field (`agency`, `university`, `enterprise`, or null) is stored on existing `Employer` documents during bulk onboarding. No migration is needed for MongoDB. The `Employer` model's `$fillable` array will be extended to include `partner_type`.

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Churn report excludes active users

*For any* employer who has at least one job post created within the selected `window_days`, that employer SHALL NOT appear in the churned employers list. Symmetrically, *for any* employee who has at least one `Application` record, that employee SHALL NOT appear in the churned seekers list.

**Validates: Requirements 1.1, 1.2**

---

### Property 2: Churn report entries contain all required fields

*For any* entry in the churn report — whether an employer or an employee record — every required field (user ID, name, email, registration date, and the type-specific date field) SHALL be present and non-null in the response.

**Validates: Requirements 1.4, 1.5**

---

### Property 3: Funnel counts are monotonically non-increasing with correct drop-off

*For any* dataset, the count at each successive funnel stage SHALL be ≤ the count of the previous stage (hired ≤ applied ≤ cv_uploaded ≤ registered), and the drop-off percentage between two stages with counts `prev` and `curr` SHALL equal `((prev - curr) / prev) * 100`, rounded to two decimal places. When `prev` is zero, drop-off SHALL be `0`.

**Validates: Requirements 2.1, 2.2, 2.4**

---

### Property 4: Estimated lost revenue calculation is exact

*For any* set of pending employer records with count `P`, average wait days `W`, and rate `R`, the returned `estimated_lost_revenue` value SHALL equal exactly `P × W × R`.

**Validates: Requirements 3.1, 3.2**

---

### Property 5: Top categories sort order matches sort_by parameter

*For any* response from the top categories endpoint, when `sort_by=applications` (or default), the entries SHALL be ordered by `application_count` descending; when `sort_by=posts`, the entries SHALL be ordered by `post_count` descending. No subsequent entry SHALL have a higher count than the entry preceding it.

**Validates: Requirements 4.2, 4.3**

---

### Property 6: Bulk onboarding row accounting invariant

*For any* CSV upload, the sum of `created` and `skipped` in the response SHALL exactly equal the number of data rows in the CSV (excluding the header row). Additionally, no `User` document SHALL have a duplicate `email` after the operation completes.

**Validates: Requirements 5.2, 5.3, 5.5**

---

### Property 7: Talent report contains no PII

*For any* talent report response or CSV export, the set of returned field keys SHALL NOT include `name`, `email`, `phone`, `user_id`, `ai_email`, `ai_phone`, `ai_full_name`, or `ai_location`.

**Validates: Requirements 6.5**

---

### Property 8: Broadcast recipient set matches audience definition

*For any* broadcast request, the set of users who receive the notification SHALL be exactly the set of users satisfying the audience rule: `employees` → role contains `employee`; `employers` → role contains `employer`; `all` → role does not include `admin`; `user_ids` array → exactly those IDs. No user outside the defined set SHALL receive the broadcast.

**Validates: Requirements 7.1, 7.2, 7.3, 7.4**

---

### Property 9: Event notifications are created for all affected users

*For any* application status update, the `Notification` collection SHALL contain a new record for the application's `user_id` after the update. *For any* direct offer creation, a `Notification` record SHALL exist for the `job_seeker_id`. *For any* employer approval or rejection, a `Notification` record SHALL exist for the employer's `user_id`.

**Validates: Requirements 10.1, 10.2, 10.3, 10.4**

---

### Property 10: Audit log document count is monotonically non-decreasing

*For any* sequence of loggable actions, the total count of documents in the `audit_logs` collection SHALL increase by exactly 1 for each action and SHALL never decrease. No existing document's fields SHALL change after creation.

**Validates: Requirements 9.5, 9.6**

---

### Property 11: Notification unread count matches database state

*For any* user, the integer returned by `GET /notifications/unread-count` SHALL equal the count of `Notification` documents for that user where `read_at IS NULL`.

**Validates: Requirements 10.9**

---

### Property 12: Mark-all-read leaves zero unread notifications

*For any* user with N ≥ 0 unread notifications, after a successful `POST /notifications/read-all`, the count of `Notification` documents for that user where `read_at IS NULL` SHALL be exactly zero.

**Validates: Requirements 10.8**

---

## Error Handling

| Scenario | HTTP Status | Response shape |
|---|---|---|
| Missing required CSV columns | 422 | `{"message": "..."}` |
| CSV file > 2 MB | 422 | `{"message": "File exceeds 2 MB limit"}` |
| Non-CSV file uploaded | 422 | `{"message": "File must be a valid CSV"}` |
| Talent report with < 5 profiles | 422 | `{"message": "Insufficient data for anonymized report"}` |
| Re-analysis target has no CV | 422 | `{"message": "No CV file found for this user"}` |
| Re-analysis target not found / not employee | 404 | `{"message": "User not found"}` |
| Broadcast missing subject or body | 422 | `{"errors": {...}}` |
| Notification email delivery failure | — | Logged via `Log::error()`, in-app notification retained, action completes normally |
| Any unexpected exception in a service | 500 | `{"message": "An error occurred"}` + `Log::error()` |

---

## Testing Strategy

### Dual testing approach

**Unit tests** (`tests/Unit/`) cover service logic in isolation:
- `ChurnReportServiceTest` — mock user/job/application data, assert correct inclusions/exclusions
- `ConversionFunnelServiceTest` — verify monotone counts, drop-off math, zero-stage edge cases
- `TalentReportServiceTest` — skill aggregation correctness, ATS median computation, PII field exclusion
- `AuditLogServiceTest` — assert document written synchronously with correct fields

**Feature tests** (`tests/Feature/`) cover HTTP contracts:
- `AdminReportingTest` — response shapes, CSV Content-Type headers, query parameter defaults
- `BulkOnboardingTest` — file validation, duplicate skipping, response summary counts
- `BroadcastTest` — audience resolution, missing field validation, queued job dispatched
- `AdminReanalysisTest` — 422 on missing CV, 404 on missing user, status set to processing
- `AuditLogTest` — pagination, action_type filter, date range filter
- `NotificationTest` — mark read, mark all read, unread count accuracy

### Property-based testing

Using **Pest + `eris/eris`** (PHP property-based testing library) with minimum 100 iterations per property:

Each correctness property above maps to one property-based test, tagged with:

```
// Feature: admin-business-intelligence, Property N: <property title>
```

**Property test examples:**

```php
// Property 3: Funnel monotone
it('funnel counts are monotonically non-increasing', function () {
    // Feature: admin-business-intelligence, Property 3: Funnel counts are monotonically non-increasing
    forAll(
        Generator\choose(0, 1000),  // registered
        Generator\choose(0, 1000),  // cv_uploaded
        Generator\choose(0, 1000),  // applied
        Generator\choose(0, 1000)   // hired
    )->then(function ($r, $c, $a, $h) {
        $service = new ConversionFunnelService(...); // seeded with these counts
        $result  = $service->compute();
        expect($result['stages'][1]['count'])->toBeLessThanOrEqual($result['stages'][0]['count']);
        expect($result['stages'][2]['count'])->toBeLessThanOrEqual($result['stages'][1]['count']);
        expect($result['stages'][3]['count'])->toBeLessThanOrEqual($result['stages'][2]['count']);
    });
})->repeat(100);
```

### Unit test balance

- Unit tests focus on: specific CSV parsing examples, ATS median edge cases (even/odd array sizes), exact error messages
- Property tests focus on: universal invariants across generated inputs
- Integration points (observer → notification creation, broadcast → queue dispatch) tested via feature tests with `Queue::fake()` and `Notification::fake()`
