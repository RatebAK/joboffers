# Requirements Document

## Introduction

This feature adds admin-facing business intelligence and operational tooling to the CV AI Job Platform. It is entirely additive — no existing controllers, models, or routes are modified. The scope covers: churn reporting, conversion funnel metrics, approval pipeline visibility, top job category analysis, bulk B2B employer onboarding (universities use the same employer flow, tagged via `partner_type`), anonymized talent market exports, platform-wide email broadcasts, manual CV re-analysis, an audit log, and a user notification system.

Reports produce data that can be exported as CSV. The broadcast endpoint is a separate action that accepts explicit user IDs. There is no automatic coupling between reports and actions — the admin exports, analyses externally if needed, then acts.

## Glossary

- **Admin**: Platform user with role `admin`
- **Employer**: Platform user with role `employer`, approved via the `Employer` model
- **Employee**: Platform user with role `employee` (job seeker)
- **B2B_Partner**: An employer account tagged with a `partner_type` field (`agency`, `university`, `enterprise`) on the `Employer` record; no separate role is required
- **ATS_Score**: AI-derived applicant tracking score on `JobSeekerProfile`
- **Churn**: A user who registered but has become inactive by a defined threshold
- **Conversion_Funnel**: The ordered stages a job seeker passes through: registered → CV uploaded → first application → hired
- **Audit_Log**: Immutable, persisted record of sensitive admin actions
- **Notification**: An in-app record of a platform event for a specific user, optionally paired with an email

---

## Requirements

### Requirement 1: Churn Report

**User Story:** As an admin, I want to identify inactive employers and job seekers and export that data, so that I can run re-engagement campaigns using an external email tool or the platform broadcast.

#### Acceptance Criteria

1. WHEN an admin requests the churn report, THE Admin_API SHALL return a list of employers who have created no job posts within the last `window_days` days (valid values: 30, 60, 90; default: 30)
2. WHEN an admin requests the churn report, THE Admin_API SHALL return a list of employees who have a CV on file but zero submitted applications
3. WHEN `window_days` is absent or invalid, THE Admin_API SHALL default to `30` and include a `window_days` field in the response confirming the value used
4. THE Churn_Report employer entries SHALL each contain: user ID, name, email, registration date, last job post date (or null), and total post count
5. THE Churn_Report employee entries SHALL each contain: user ID, name, email, registration date, CV upload date, and ATS score (or null)
6. WHEN the `format=csv` query parameter is provided, THE Admin_API SHALL return the data as a downloadable CSV with `Content-Type: text/csv` and `Content-Disposition: attachment` headers

---

### Requirement 2: Conversion Funnel Metrics

**User Story:** As an admin, I want to see where job seekers drop off between registration and being hired, so that I can identify weak stages and act on them.

#### Acceptance Criteria

1. WHEN an admin requests the conversion funnel, THE Admin_API SHALL return a count for each stage: `registered`, `cv_uploaded`, `applied`, `hired`
2. THE Admin_API SHALL return the drop-off percentage between each consecutive stage pair alongside each stage count
3. THE Conversion_Funnel response SHALL be ordered: `registered` → `cv_uploaded` → `applied` → `hired`
4. WHEN a stage count is zero, THE Admin_API SHALL return `0` for that count and `100` for the drop-off into that stage

---

### Requirement 3: Employer Approval Pipeline Report

**User Story:** As an admin, I want to see how many employer accounts are pending approval and how long they have been waiting, so that I can estimate revenue impact and prioritize the queue.

#### Acceptance Criteria

1. WHEN an admin requests the approval pipeline report, THE Admin_API SHALL return the count of `pending` employers and the average number of days each has been waiting
2. THE Admin_API SHALL return an `estimated_lost_revenue` value calculated as `pending_count × avg_wait_days × daily_revenue_per_employer`
3. WHEN the `daily_revenue_per_employer` query parameter is provided, THE Admin_API SHALL use that value; otherwise it SHALL default to `10`
4. THE Approval_Pipeline_Report SHALL list each pending employer with: user ID, name, email, submission date, and days waiting

---

### Requirement 4: Top Performing Job Categories

**User Story:** As an admin, I want to know which job categories have the most posts and applications, so that I can guide sales outreach toward high-demand sectors.

#### Acceptance Criteria

1. WHEN an admin requests the top categories report, THE Admin_API SHALL return each distinct job category with its total post count and total application count
2. THE Top_Categories_Report SHALL be sorted by application count descending by default
3. WHEN `sort_by=posts` is provided, THE Admin_API SHALL sort by post count descending instead
4. WHEN a `limit` parameter is provided (integer 1–50), THE Admin_API SHALL return only that many categories; the default SHALL be `10`

---

### Requirement 5: Bulk B2B Employer Onboarding

**User Story:** As an admin, I want to upload a CSV of employer accounts and send them invite emails, so that I can onboard staffing agencies and universities in bulk.

#### Acceptance Criteria

1. WHEN an admin uploads a valid CSV, THE Bulk_Onboarding_API SHALL create a pending `User` and `Employer` record for each valid row and dispatch an invitation email to each address
2. THE CSV SHALL require columns `name`, `email`, and `company_name`; an optional `partner_type` column (values: `agency`, `university`, `enterprise`) SHALL be stored on the `Employer` record; rows missing required columns SHALL be skipped
3. WHEN a row's email already exists in the system, THE Bulk_Onboarding_API SHALL skip that row and include it in the response with reason `email_exists`
4. WHEN the file is not a valid CSV, THE Bulk_Onboarding_API SHALL return a 422 error with a descriptive message
5. THE response SHALL include: `total_rows`, `created`, `skipped` count, and a `skipped_rows` array with each skipped entry's email and reason
6. WHEN a university is onboarded (i.e. `partner_type=university`), THE System SHALL use the standard employer approval flow; no separate role or model is created
7. WHEN onboarding completes, THE System SHALL record the action in the Audit_Log with the admin's user ID and created count

---

### Requirement 6: Anonymized Talent Market Report

**User Story:** As an admin, I want to generate anonymized reports on skills demand and ATS score distributions, so that I can export aggregate market intelligence for partners or internal use.

#### Acceptance Criteria

1. WHEN an admin requests the talent report, THE Talent_Report_API SHALL return the top `N` skills from `ai_skills` across all job seeker profiles with occurrence counts; `N` is controlled by a `limit` parameter defaulting to `20`
2. WHEN an `industry` filter is provided, THE Talent_Report_API SHALL scope results to job seekers whose `job_roles` array contains that value
3. WHEN an admin requests the talent report, THE Talent_Report_API SHALL return ATS score statistics: average, median, minimum, and maximum for the filtered population
4. WHEN `format=csv` is provided, THE Talent_Report_API SHALL return a downloadable CSV file
5. THE Talent_Report_API SHALL never include name, email, phone, user ID, or any `ai_`-prefixed contact field in any response or export
6. WHEN fewer than 5 profiles match the filter, THE Talent_Report_API SHALL return a 422 error: "Insufficient data for anonymized report"

---

### Requirement 7: Platform Announcement Broadcast

**User Story:** As an admin, I want to send an email and in-app notification to a defined audience, so that I can communicate platform news or campaigns.

#### Acceptance Criteria

1. WHEN an admin broadcasts with `audience=employees`, THE Broadcast_API SHALL target all users with the `employee` role
2. WHEN an admin broadcasts with `audience=employers`, THE Broadcast_API SHALL target all users with the `employer` role
3. WHEN an admin broadcasts with `audience=all`, THE Broadcast_API SHALL target all non-admin users
4. WHEN a `user_ids` array is provided in the request, THE Broadcast_API SHALL target only those specific users and ignore the `audience` field
5. THE request SHALL require `subject` and `body` fields; IF either is missing, THEN THE Broadcast_API SHALL return a 422 error
6. WHEN a broadcast is dispatched, THE Broadcast_API SHALL return immediately with a `queued` status and a `recipient_count`; email delivery and in-app notification creation SHALL be processed asynchronously via Laravel queues
7. WHEN a broadcast is dispatched, THE System SHALL record the action in the Audit_Log with the admin's user ID, audience value, subject, and recipient count

---

### Requirement 8: Manual CV Re-analysis Trigger

**User Story:** As an admin, I want to force a fresh CV re-analysis for a specific job seeker, so that I can fix stale AI data or offer this as a paid support action.

#### Acceptance Criteria

1. WHEN an admin triggers re-analysis for a user ID, THE Reanalysis_API SHALL set `analysis_status` to `processing` on the user's `JobSeekerProfile` and dispatch the existing CV analysis job
2. WHEN the target user has no CV (`cv_file_path` is null), THE Reanalysis_API SHALL return a 422 error: "No CV file found for this user"
3. WHEN the target user does not exist or lacks the `employee` role, THE Reanalysis_API SHALL return a 404 error
4. WHEN re-analysis is triggered, THE System SHALL record the action in the Audit_Log with the admin's user ID and the target employee's user ID
5. WHEN re-analysis completes, THE System SHALL update `ai_skills`, `ai_summary`, `ats_score`, `ai_analyzed_at`, and `analysis_status` via the existing CV analysis flow without any modification to that flow

---

### Requirement 9: Audit Log

**User Story:** As an admin, I want a read-only log of all sensitive platform actions, so that I can maintain compliance and respond to enterprise audit requests.

#### Acceptance Criteria

1. THE Audit_Log SHALL record entries for the action types: `employer_approved`, `employer_rejected`, `broadcast_sent`, `cv_reanalysis_triggered`, `bulk_employer_onboarded`
2. WHEN an admin views the audit log, THE Audit_Log_API SHALL return paginated entries each containing: action type, actor user ID, actor name, target entity ID, target entity type, timestamp, and an optional metadata object
3. WHEN an admin filters by `action_type`, THE Audit_Log_API SHALL return only matching entries
4. WHEN an admin filters by `date_from` and/or `date_to`, THE Audit_Log_API SHALL return only entries within that range
5. THE Audit_Log_API SHALL be read-only; no endpoint SHALL permit deletion or modification of any entry
6. WHEN a loggable action occurs, THE System SHALL write the Audit_Log entry synchronously within the same request lifecycle

---

### Requirement 10: User Notification System

**User Story:** As a platform user, I want to receive in-app and email notifications for key events, so that I know what is happening on the platform without having to check manually.

#### Acceptance Criteria

1. WHEN an employee's application status is updated, THE Notification_System SHALL create an in-app notification for that employee and dispatch an email notification
2. WHEN an employer receives a new application on one of their job posts, THE Notification_System SHALL create an in-app notification for that employer
3. WHEN an employee receives a direct job offer, THE Notification_System SHALL create an in-app notification for that employee and dispatch an email notification
4. WHEN an employer's account application is approved or rejected, THE Notification_System SHALL create an in-app notification for that employer and dispatch an email notification
5. WHEN a platform broadcast is dispatched, THE Notification_System SHALL create an in-app notification for each recipient in addition to the email
6. WHEN an authenticated user requests their notifications, THE Notification_API SHALL return a paginated list ordered newest first, each entry containing: notification ID, type, message, read status, related entity ID, related entity type, and created timestamp
7. WHEN a user marks a notification as read, THE Notification_API SHALL set its `read_at` timestamp and return the updated notification
8. WHEN a user marks all their notifications as read, THE Notification_API SHALL update all unread notifications for that user in a single operation
9. WHEN a user requests their unread count, THE Notification_API SHALL return the integer count of unread notifications
10. IF a notification email fails to deliver, THEN THE Notification_System SHALL retain the in-app notification, log the failure, and SHALL NOT prevent the original action from completing
