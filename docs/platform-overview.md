# CV AI Job Platform — Technical Overview

> A presentation-ready summary of the database architecture, API surface, and key design decisions.

---

## Platform Summary

A REST API connecting **job seekers** and **employers**, powered by external AI for CV analysis and candidate matching. Employers post jobs, search candidates, and send direct offers. Job seekers upload CVs that are automatically analyzed to generate skill profiles, ATS scores, and career summaries. Admins approve employer accounts and monitor platform health through a business intelligence layer.

**Tech stack:** PHP 8.2 / Laravel 12 · MongoDB · JWT Auth · Cloudinary (file storage) · External AI service (CV analysis & matching)

---

## Database

**Engine:** MongoDB (all collections use the `mongodb/laravel-mongodb` Eloquent driver)

### Collections at a Glance

| Collection | Documents (purpose) |
|---|---|
| `users` | All platform accounts (seekers, employers, admins) |
| `job_seeker_profiles` | Extended seeker profile + AI-derived fields |
| `employers` | Employer application records (approval workflow) |
| `job_posts` | Job listings created by employers |
| `applications` | Job seeker applications to job posts |
| `direct_offers` | Employer-to-seeker direct job offers |
| `company_profiles` | Public and private company information |
| `coach_sessions` | AI resume coach conversation sessions |
| `coach_messages` | Individual messages within coach sessions |
| `notifications` | In-app notification inbox per user |
| `audit_logs` | Immutable admin action log |

---

### `users`

Central identity document. Every platform user — seeker, employer, or admin — is a single `User` document.

| Field | Type | Notes |
|---|---|---|
| `_id` | ObjectId | Primary key |
| `name` | string | Full display name |
| `email` | string | Unique, indexed |
| `password` | string | Bcrypt hashed |
| `roles` | string[] | `employee`, `employer`, `admin` — multi-role support |
| `is_employer` | boolean | `true` after admin approval |
| `email_verified_at` | datetime | null until verified |
| `created_at` | datetime | |
| `updated_at` | datetime | |

---

### `job_seeker_profiles`

One-to-one with `users` (for `employee` role). Stores personal info, career preferences, structured CV data, and all AI-derived fields.

**Personal block:** `first_name`, `last_name`, `full_name`, `image`, `gender`, `nationality`, `city`, `location`, `address`, `phone`, `date_of_birth`, `marital_status`

**Career block:** `current_job_title`, `current_job_status`, `job_level`, `job_types[]`, `job_roles[]`, `work_cities[]`, `years_of_experience`, `education_level`, `expected_salary`, `salary_range_from`, `salary_range_to`, `is_actively_seeking`, `experience_summary`, `social_links{}`

**Structured data:** `skills[]`, `education_history[]`, `work_experience[]`

**CV / Resume files:** `cv_file_path` (Cloudinary URL), `cv_public_id`, `resume`, `resume_public_id`, `resume_file_type`, `default_cover_letter`

**AI-derived fields** (prefixed `ai_` or `ats_`):

| Field | Type | Description |
|---|---|---|
| `ai_full_name` | string | Name extracted from CV |
| `ai_email` | string | Email extracted from CV |
| `ai_phone` | string | Phone extracted from CV |
| `ai_location` | string | Location extracted from CV |
| `ai_summary` | string | Professional summary |
| `ai_skills` | string[] | Skills list extracted by AI |
| `ai_work_history` | object[] | Past jobs |
| `ai_education_history` | object[] | Education entries |
| `ai_languages` | string[] | Spoken languages |
| `ai_projects` | object[] | Projects |
| `ai_social_links` | object | LinkedIn, GitHub, etc. |
| `ai_overall_evaluation` | string | AI assessment text |
| `ats_score` | integer | 0–100 ATS score |
| `ai_detected_language` | string | CV language |
| `ai_analyzed_at` | datetime | When AI ran |

**Analysis status:** `analysis_status` (`pending` → `processing` → `completed` / `error`), `analysis_error`, `analysis_started_at`, `analysis_completed_at`

---

### `employers`

Tracks employer onboarding applications. Separate from `users` — a user applies to become an employer; admin approves or rejects.

| Field | Type | Notes |
|---|---|---|
| `user_id` | string | References `users._id` |
| `document_path` | string | Cloudinary path of supporting doc |
| `document_name` | string | Original filename |
| `status` | string | `pending` · `approved` · `rejected` |
| `reviewed_by` | string | Admin user ID |
| `review_notes` | string | Rejection reason |
| `reviewed_at` | datetime | |
| `partner_type` | string | `agency` · `university` · `enterprise` · null |

---

### `job_posts`

Job listings. Each post is owned by an employer user.

**Identity:** `job_id` (display ID), `employer_id`, `company_profile_id`, `company_name`, `company_logo`

**Candidate requirements:** `title`, `roles[]`, `gender`, `age_from`, `age_to`, `education_level`, `job_level`, `experience_years`, `languages[]`, `portfolio_required`, `cover_letter_required`

**Work details:** `job_type` (`full_time` · `part_time` · `contract` · `freelance`), `work_mode` (`remote` · `hybrid` · `on_site`), `city`, `address`, `salary_from`, `salary_to`, `currency`, `display_salary`, `incentives`

**Communication:** `communication_method` (`by_phone` · `by_forsa` · `by_website`), `communication_value`

**Content:** `description`, `requirements`, `questions[]`

**Meta:** `category`, `tags[]`, `is_active`, `expires_at`

---

### `applications`

A job seeker applies to a job post.

| Field | Type | Notes |
|---|---|---|
| `user_id` | string | Seeker |
| `job_post_id` | string | Target job post |
| `status` | string | `pending` · `reviewed` · `shortlisted` · `hired` · `rejected` · `withdrawn` |
| `cover_letter` | string | |
| `resume` | string | CV URL |
| `feedback` | string | Employer feedback |
| `applied_at` | datetime | |
| `education` | string | Snapshot at time of application |
| `last_work` | string | Last job title/company |
| `years_of_experience` | integer | |
| `why_join` | string | Motivation statement |
| `what_to_add` | string | Value proposition |
| `positions_suited_for` | string[] | |
| `notice_period` | string | |
| `expected_salary` | mixed | |

---

### `direct_offers`

Employer-initiated offer sent directly to a job seeker.

| Field | Type | Notes |
|---|---|---|
| `employer_id` | string | |
| `job_seeker_id` | string | |
| `job_post_id` | string | |
| `message` | string | Personalised note |
| `status` | string | `pending` · `accepted` · `declined` |

Accepting an offer auto-creates an `Application`.

---

### `company_profiles`

Public and private company information. One-to-one with employer `users`.

**Public fields:** `name`, `slug`, `logo`, `cover_image`, `description`, `industry`, `company_size`, `city`, `country`, `phone_main`, `phone_visible`, `email`

**Ratings (system-managed):** `rating`, `review_count`, `would_recommend`, `ceo_performance`, `category_ratings{}`, `reviews[]`

**Private info (sub-document):** `address`, `founded_year`, `website`, `social_media{}`, `industry_tags[]`, `expose_to_applicants`

---

### `coach_sessions` + `coach_messages`

AI-powered resume coaching. A session is a named conversation; messages alternate between `user` and `assistant` roles.

| Collection | Key fields |
|---|---|
| `coach_sessions` | `user_id`, `title`, timestamps |
| `coach_messages` | `session_id`, `role` (`user`/`assistant`), `content` |

---

### `notifications`

In-app notification inbox. One document per event per recipient.

| Field | Type | Notes |
|---|---|---|
| `user_id` | string | Recipient |
| `type` | string | See types below |
| `message` | string | Human-readable text |
| `related_entity_id` | string | ID of the triggering entity |
| `related_entity_type` | string | `Application` · `DirectOffer` · `Employer` |
| `read_at` | datetime | null = unread |

**Notification types:**

| Type | Recipient | Trigger |
|---|---|---|
| `application_status_changed` | Employee | Employer updates application status |
| `direct_offer_received` | Employee | Employer sends a direct offer |
| `employer_decision` | Employer | Admin approves or rejects account |
| `new_application` | Employer | Seeker applies to their job post |
| `broadcast` | Any non-admin | Admin sends platform broadcast |

---

### `audit_logs`

Append-only compliance log. No `updated_at`. Never deleted.

| Field | Type | Notes |
|---|---|---|
| `action` | string | See action types below |
| `actor_id` | string | Admin who performed the action |
| `actor_name` | string | Denormalised for display |
| `target_id` | string | Affected entity ID |
| `target_type` | string | `User` · `Employer` |
| `metadata` | object | Action-specific context |
| `created_at` | datetime | |

**Action types:** `employer_approved` · `employer_rejected` · `broadcast_sent` · `cv_reanalysis_triggered` · `bulk_employer_onboarded`

---

## API Surface

All routes are prefixed `/api/`. JWT bearer token required except where noted.

### Authentication

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/auth/register` | Public | Register new account |
| POST | `/api/auth/login` | Public | Login, returns JWT |
| GET | `/api/auth/profile` | Any | Current user profile |
| POST | `/api/auth/logout` | Any | Invalidate token |
| POST | `/api/auth/refresh` | Any | Refresh JWT |

---

### Public (No Auth)

| Method | Path | Description |
|---|---|---|
| GET | `/api/jobs` | List active job posts (filterable) |
| GET | `/api/jobs/{id}` | Single job post |
| GET | `/api/companies` | List company profiles |
| GET | `/api/companies/{id}` | Single company profile |
| GET | `/api/search/users` | Public talent search |
| GET | `/api/users/{userId}` | Any user's public profile |

---

### Job Seeker Routes (`role:employee`)

**Prefix:** `/api/job-seeker/`

| Method | Path | Description |
|---|---|---|
| GET | `profile` | Own profile |
| PUT | `profile/personal-info` | Update personal info |
| PUT | `profile/career-info` | Update career preferences |
| PUT | `profile/social-links` | Update social links |
| PUT | `profile/skills` | Replace skills |
| DELETE | `profile/skills` | Clear skills |
| PUT | `profile/education` | Replace education history |
| DELETE | `profile/education` | Clear education |
| PUT | `profile/work-experience` | Replace work experience |
| DELETE | `profile/work-experience` | Clear work experience |
| POST | `resume/upload` | Upload plain resume |
| POST | `resume/upload-and-analyze` | Upload CV + trigger AI analysis |
| GET | `resume` | Get resume + AI results |
| DELETE | `resume` | Delete resume |
| GET | `resume/analysis-status` | Poll CV analysis status |
| POST | `resume/retry-analysis` | Retry failed analysis |
| PUT | `cover-letter` | Save default cover letter |
| DELETE | `cover-letter` | Delete cover letter |
| GET | `applications` | My applications |
| POST | `apply` | Apply to a job |
| DELETE | `applications/{id}/withdraw` | Withdraw application |
| GET | `matched-jobs` | AI-matched job list |
| GET | `match-resume-to-jobs` | AI resume-to-jobs match |
| GET | `analytics` | Personal analytics |
| GET | `offers` | Received direct offers |
| POST | `offers/{id}/accept` | Accept offer (creates application) |
| POST | `offers/{id}/decline` | Decline offer |
| GET | `coach/sessions` | List AI coach sessions |
| GET | `coach/sessions/{id}` | Session with messages |
| DELETE | `coach/sessions/{id}` | Delete session |
| POST | `coach/chat` | Send message to AI coach |

---

### Employer Routes (`role:employer`)

**Prefix:** `/api/employer/`

| Method | Path | Description |
|---|---|---|
| GET | `jobs` | My job posts |
| POST | `jobs` | Create job post |
| PUT | `jobs/{id}` | Update job post |
| DELETE | `jobs/{id}` | Delete job post |
| POST | `jobs/{id}/activate` | Activate post |
| POST | `jobs/{id}/deactivate` | Deactivate post |
| GET | `company` | My company profile |
| POST/PUT | `company` | Update public company info |
| PUT | `company/private` | Update private company info |
| POST | `company/logo` | Upload company logo |
| POST | `company/cover` | Upload cover image |
| GET | `jobs/{jobId}/applications` | Applications for a post |
| PUT | `applications/{id}/status` | Update application status |
| GET | `seekers` | Search job seekers |
| GET | `seekers/{userId}` | View seeker profile |
| POST | `offers` | Send direct offer |
| GET | `offers` | My sent offers |
| POST | `match-candidates` | AI candidate matching |
| POST | `jobs/{jobPostId}/match-candidates` | AI match for specific post |
| GET | `analytics` | Employer analytics |
| POST | `apply` | Apply to become employer |
| GET | `status` | Own employer application status |

---

### Admin Routes (`role:admin`)

**Prefix:** `/api/admin/`

| Method | Path | Description |
|---|---|---|
| GET | `employers` | List pending employer applications |
| POST | `employers/{id}/approve` | Approve employer |
| POST | `employers/{id}/reject` | Reject employer |
| GET | `analytics` | Platform-wide analytics |
| GET | `users` | All users |
| GET | `users/seekers` | All job seekers |
| GET | `users/employers` | All employers |
| GET | `reports/churn` | Churn & re-engagement report |
| GET | `reports/funnel` | Conversion funnel metrics |
| GET | `reports/pipeline` | Employer approval pipeline |
| GET | `reports/categories` | Top job categories |
| GET | `reports/talent` | Anonymized talent market report |
| POST | `onboarding/bulk` | Bulk employer CSV onboarding |
| POST | `broadcast` | Platform-wide announcement |
| POST | `users/{userId}/reanalyze` | Force CV re-analysis |
| GET | `audit-log` | Compliance audit log |

---

### Notifications (All Authenticated Users)

**Prefix:** `/api/notifications/`

| Method | Path | Description |
|---|---|---|
| GET | `/` | Paginated notification inbox |
| GET | `/unread-count` | Integer count of unread |
| POST | `/read-all` | Mark all as read |
| POST | `/{id}/read` | Mark one as read |

---

## Key Design Decisions

**MongoDB (schemaless)** — Chosen for flexible document shapes. Job seeker profiles have many optional fields; job posts have configurable question arrays; AI output varies by CV content. Adding new fields requires no migration.

**AI fields are prefixed `ai_`** — Clear separation between user-entered data and AI-derived data. `ats_score` is the only exception — it is unprefixed because it is a filterable search criterion.

**JWT stateless auth** — No session storage. Each request carries a self-contained token. The `jwt.auth` middleware resolves the user without a DB read for user sessions.

**Denormalisation over joins** — `company_name` and `company_logo` are stored directly on `job_posts` at creation time so the job listing is self-contained even if the company profile changes later. `actor_name` is stored on `audit_logs` for the same reason.

**Observer pattern for notifications** — `NotificationObserver` and `EmployerObserver` are registered in `AppServiceProvider` and watch `Application`, `DirectOffer`, and `Employer` model events. This keeps notification logic out of controllers entirely.

**Audit log is append-only** — `AuditLog` has `const UPDATED_AT = null`. No update or delete routes exist. This satisfies enterprise compliance requirements.

**Bulk operations are queued** — `SendBulkInviteJob` and `SendBroadcastJob` implement `ShouldQueue`. The API returns immediately with a `queued` status and recipient count, so large broadcasts don't time out.

**CSV exports stream from the service layer** — `ChurnReportService::toCsv()` and `TalentReportService::toCsv()` produce the CSV string; the controller sets the `Content-Type` and `Content-Disposition` headers. No temp files are written.

---

## API Counts

| Category | Count |
|---|---|
| Public endpoints | 6 |
| Authentication endpoints | 5 |
| Job seeker endpoints | 26 |
| Employer endpoints | 19 |
| Admin endpoints | 15 |
| Notification endpoints | 4 |
| **Total** | **75** |

---

## Test Coverage

| Test file | Tests | Focus |
|---|---|---|
| `AuditLogServiceTest` | 5 | Audit log write correctness |
| `NotificationTest` | 13 | Inbox, read/unread, mark all read |
| `AdminReportingTest` | 14 | Churn, funnel, pipeline, categories |
| `TalentReportTest` | 9 | Anonymization, PII exclusion, ATS stats |
| `BulkOnboardingTest` | 10 | CSV parsing, row accounting, invite dispatch |
| `BroadcastTest` | 10 | Audience resolution, audit log, recipient set |
| `AdminReanalysisAndAuditLogTest` | 12 | Re-analysis trigger, audit log filtering |
| `EmployerApprovalAuditTest` | 2 | Observer-triggered audit entries |
| `AdminBIEndToEndTest` | 5 | Full flow across all BI features |
| **Total** | **80** | 268 assertions |
