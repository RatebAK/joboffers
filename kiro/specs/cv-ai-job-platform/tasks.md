# Implementation Plan: CV AI Job Platform

## Overview

Incremental implementation of the job platform features on top of the existing Laravel/MongoDB codebase. Each task builds on the previous, ending with all components wired together. The implementation language is PHP (Laravel).

## Tasks

- [x] 1. Extend JobSeekerProfile model and create CvAnalysisService
  - [x] 1.1 Add AI analysis fields to JobSeekerProfile model
    - Add `cv_file_path`, `ai_full_name`, `ai_email`, `ai_phone`, `ai_location`, `ai_summary`, `ai_skills`, `ai_work_history`, `ai_projects`, `ai_overall_evaluation`, `ats_score`, `ai_detected_language`, `ai_analyzed_at` to `$fillable` and `$casts`
    - _Requirements: 1.3, 2.4_

  - [x] 1.2 Create CvAnalysisService
    - Create `app/Services/CvAnalysisService.php` with a method `analyze(string $filePath): array`
    - Use Laravel's `Http` facade to POST the CV file to the external AI API (URL from config)
    - Parse and return the `analysis` object from the JSON response
    - Throw a `CvAnalysisException` on HTTP error or non-success status
    - Create `app/Exceptions/CvAnalysisException.php`
    - Bind the service in `AppServiceProvider`
    - _Requirements: 1.2, 1.5, 1.6_

  - [ ]* 1.3 Write unit tests for CvAnalysisService
    - Test successful response parsing
    - Test HTTP error returns 502-mapped exception
    - Test non-success status returns 422-mapped exception
    - _Requirements: 1.5, 1.6_

- [x] 2. Implement CV upload and AI analysis endpoint
  - [x] 2.1 Add `uploadAndAnalyze` method to JobSeekerController
    - Validate file (PDF/DOC/DOCX, max 10 MB)
    - Delete previous CV file from storage if `cv_file_path` exists on profile
    - Store new file to `resumes/` disk
    - Call `CvAnalysisService::analyze()`
    - On success: update JobSeekerProfile with all AI fields and `cv_file_path`, return 200 with updated profile
    - On `CvAnalysisException`: return appropriate 502 or 422 response without overwriting profile
    - Register route: `POST /api/job-seeker/resume/upload-and-analyze`
    - _Requirements: 1.1, 1.3, 1.4, 1.5, 1.6, 1.7, 2.5_

  - [ ]* 2.2 Write property test for CV upload and AI field persistence (Property 1)
    - **Property 1: CV upload triggers analysis and stores all AI fields**
    - **Validates: Requirements 1.1, 1.2, 1.3, 1.7, 2.4**
    - Generate random valid analysis payloads; verify all fields are persisted to profile

  - [ ]* 2.3 Write property test for failed analysis preserving profile data (Property 2)
    - **Property 2: Failed AI analysis does not overwrite existing profile data**
    - **Validates: Requirements 1.6**

  - [ ]* 2.4 Write property test for CV re-upload replacing file path (Property 3)
    - **Property 3: Re-uploading CV replaces file path**
    - **Validates: Requirements 2.5**

- [x] 3. Implement profile partial update and retrieval
  - [x] 3.1 Update `show` and `update` methods in JobSeekerController
    - `show`: return full profile including all AI fields
    - `update`: validate and apply only provided fields; do not touch AI fields unless explicitly sent
    - _Requirements: 2.1, 2.2, 2.3_

  - [ ]* 3.2 Write property test for profile partial update (Property 4)
    - **Property 4: Profile partial update only changes provided fields**
    - **Validates: Requirements 2.2**

- [x] 4. Checkpoint — Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Create JobPostController with full CRUD
  - [x] 5.1 Create `app/Http/Controllers/API/JobPostController.php`
    - `index` (public): paginated list of active posts, filters: keyword, location, job_type, category, min_salary
    - `show` (public): single post by ID, return 404 if not found
    - `store` (employer): validate required fields, create post with `is_active = true`, return 201
    - `update` (employer): validate ownership (403 if not owner), apply changes
    - `destroy` (employer): validate ownership (403 if not owner), delete post
    - `myPosts` (employer): list own posts with application counts via aggregation
    - `deactivate` (employer): set `is_active = false`
    - Register all routes in `api.php`
    - _Requirements: 3.1–3.9, 5.1–5.9_

  - [ ]* 5.2 Write property test for job search active-only filter (Property 5)
    - **Property 5: Job search returns only active posts**
    - **Validates: Requirements 3.1**

  - [ ]* 5.3 Write property test for conjunctive job search filters (Property 6)
    - **Property 6: Job search filters are applied conjunctively**
    - **Validates: Requirements 3.2–3.7**

  - [ ]* 5.4 Write property test for job post fetch by ID (Property 7)
    - **Property 7: Job post fetch by ID returns correct data**
    - **Validates: Requirements 3.8**

  - [ ]* 5.5 Write property test for new job post active by default (Property 12)
    - **Property 12: New job post is active by default**
    - **Validates: Requirements 5.1, 5.3**

  - [ ]* 5.6 Write property test for job post update persistence (Property 13)
    - **Property 13: Job post update persists changes**
    - **Validates: Requirements 5.4**

  - [ ]* 5.7 Write property test for deactivated post excluded from search (Property 14)
    - **Property 14: Deactivated job post is excluded from job seeker search**
    - **Validates: Requirements 5.6**

  - [ ]* 5.8 Write property test for employer job post list with counts (Property 15)
    - **Property 15: Employer job post list includes application counts**
    - **Validates: Requirements 5.7**

  - [ ]* 5.9 Write property test for job post deletion (Property 16)
    - **Property 16: Job post deletion removes the record**
    - **Validates: Requirements 5.8**

  - [ ]* 5.10 Write property test for cross-employer authorization (Property 17)
    - **Property 17: Employer cannot modify another employer's posts**
    - **Validates: Requirements 5.5, 5.9**

- [x] 6. Checkpoint — Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Extend ApplicationController for employer-side management
  - [x] 7.1 Create `app/Http/Controllers/API/ApplicationController.php`
    - Move existing apply/withdraw/list logic from `JobSeekerController` into this dedicated controller
    - Add `indexForEmployer`: list applications for a given job post (verify ownership), include applicant name and `ats_score`
    - Add `updateStatus`: validate status enum (`pending`, `reviewed`, `accepted`, `rejected`), store status and optional feedback
    - Register new employer routes: `GET /api/employer/jobs/{jobId}/applications`, `PUT /api/employer/applications/{id}/status`
    - _Requirements: 4.1–4.8, 8.1–8.5_

  - [ ]* 7.2 Write property test for application creation (Property 8)
    - **Property 8: Application creation produces a pending record**
    - **Validates: Requirements 4.1**

  - [ ]* 7.3 Write property test for duplicate application rejection (Property 9)
    - **Property 9: Duplicate application is rejected**
    - **Validates: Requirements 4.2**

  - [ ]* 7.4 Write property test for resume source selection (Property 10)
    - **Property 10: Resume source selection**
    - **Validates: Requirements 4.4, 4.5**

  - [ ]* 7.5 Write property test for application withdrawal (Property 11)
    - **Property 11: Application withdrawal removes the record**
    - **Validates: Requirements 4.7**

  - [ ]* 7.6 Write property test for employer application list with enriched data (Property 26)
    - **Property 26: Employer application list includes enriched data**
    - **Validates: Requirements 8.1**

  - [ ]* 7.7 Write property test for application status and feedback update (Property 27)
    - **Property 27: Application status and feedback update is persisted**
    - **Validates: Requirements 8.2, 8.4**

- [x] 8. Create EmployerSearchController
  - [x] 8.1 Create `app/Http/Controllers/API/EmployerSearchController.php`
    - `index`: paginated list of `is_actively_seeking = true` profiles, filters: skills (all-match), min_ats_score, max_ats_score, location, keyword
    - `show`: return public profile for a specific job seeker, exclude `password`, `email`, `phone`; return 404 if not found or not a job seeker
    - Register routes: `GET /api/employer/seekers`, `GET /api/employer/seekers/{userId}`
    - _Requirements: 6.1–6.9_

  - [ ]* 8.2 Write property test for seeker search active-seeking filter (Property 18)
    - **Property 18: Seeker search returns only actively seeking profiles**
    - **Validates: Requirements 6.1**

  - [ ]* 8.3 Write property test for conjunctive seeker search filters (Property 19)
    - **Property 19: Seeker search filters are applied conjunctively**
    - **Validates: Requirements 6.2–6.7**

  - [ ]* 8.4 Write property test for public profile sensitive field exclusion (Property 20)
    - **Property 20: Public seeker profile excludes sensitive fields**
    - **Validates: Requirements 6.8**

- [x] 9. Create DirectOffer model and DirectOfferController
  - [x] 9.1 Create `app/Models/DirectOffer.php`
    - Collection: `direct_offers`
    - Fillable: `employer_id`, `job_seeker_id`, `job_post_id`, `message`, `status`
    - Casts: `created_at`, `updated_at` as datetime
    - Relationships: `belongsTo` User (employer), User (job seeker), JobPost
    - _Requirements: 7.1_

  - [x] 9.2 Create `app/Http/Controllers/API/DirectOfferController.php`
    - `store` (employer): validate fields, verify job post ownership (403), verify target is job seeker (422), check for duplicate (409), create with `status = "pending"`, return 201
    - `indexSent` (employer): paginated list of sent offers with job seeker name and status
    - `indexReceived` (job seeker): paginated list of received offers with job post title and employer company name
    - `accept` (job seeker): verify offer is addressed to requester (403), set status to `accepted`, create Application with `status = "pending"`
    - `decline` (job seeker): verify offer is addressed to requester (403), set status to `declined`
    - Register routes in `api.php`
    - _Requirements: 7.1–7.10_

  - [ ]* 9.3 Write property test for direct offer creation (Property 21)
    - **Property 21: Direct offer creation produces a pending record**
    - **Validates: Requirements 7.1**

  - [ ]* 9.4 Write property test for duplicate offer rejection (Property 22)
    - **Property 22: Duplicate direct offer is rejected**
    - **Validates: Requirements 7.5**

  - [ ]* 9.5 Write property test for offer status transitions (Property 23)
    - **Property 23: Offer status transitions are correct**
    - **Validates: Requirements 7.7, 7.8**

  - [ ]* 9.6 Write property test for accepting offer creates application (Property 24)
    - **Property 24: Accepting a direct offer creates an application**
    - **Validates: Requirements 7.7**

  - [ ]* 9.7 Write property test for scoped offer lists (Property 25)
    - **Property 25: Offer list endpoints return correct scoped data**
    - **Validates: Requirements 7.6, 7.10**

- [x] 10. Final checkpoint — Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- Property tests use the **Eris** library (`giorgiosironi/eris`) integrated with PHPUnit
- Each property test must run a minimum of 100 iterations
- Tag format for each property test: `// Feature: cv-ai-job-platform, Property N: <property title>`
- The AI CV Analysis API base URL should be stored in `.env` as `CV_ANALYSIS_API_URL`
- All new routes follow the existing pattern: job seeker routes under `middleware(['jwt.auth', 'role:employee'])`, employer routes under `middleware(['jwt.auth', 'role:employer'])`

## New Features

- [x] 11. Implement UserProfileController (view any user's full profile)
  - [x] 11.1 Create `app/Http/Controllers/API/UserProfileController.php`
    - `show($userId)` — authenticated (any role): resolve user, load JobSeekerProfile or CompanyProfile based on role, strip `password` from User, return full data; 404 if not found
    - `adminListAll(Request $request)` — admin only: paginated list of all users with basic info
    - `adminListSeekers(Request $request)` — admin only: paginated list of all employees with full JobSeekerProfile
    - `adminListEmployers(Request $request)` — admin only: paginated list of all employers with full CompanyProfile
    - Register routes: `GET /api/users/{userId}` (jwt.auth), `GET /api/admin/users`, `GET /api/admin/users/seekers`, `GET /api/admin/users/employers` (role:admin)
    - _Requirements: 9.1–9.9_

  - [x] 11.2 Write feature test: `tests/Feature/UserProfileViewTest.php`
    - Full flow: register job seeker → register employer → admin approves employer → each role views the other's profile
    - Assert job seeker viewing employer gets full CompanyProfile
    - Assert employer viewing job seeker gets full JobSeekerProfile including ai_ fields
    - Assert admin viewing any user gets all fields
    - Assert 404 for non-existent userId
    - Assert admin list endpoints return correct counts and pagination
    - _Requirements: 9.1–9.9_

- [x] 12. Implement AnalyticsController
  - [x] 12.1 Create `app/Http/Controllers/API/AnalyticsController.php`
    - `adminAnalytics(Request $request)` — role:admin:
      - Count users by role using MongoDB queries on `users` collection
      - Count active/total job posts from `job_posts`
      - Count applications by status from `applications`
      - Count direct offers from `direct_offers`
      - Count companies from `company_profiles`
      - Employer approval stats from `employers` collection
      - Top 10 skills: aggregate `roles` + `tags` arrays across all job posts
      - Registrations by month: group `users.created_at` by month for last 12 months
      - Top 10 employers by job post count
      - Average `ats_score` across all `job_seeker_profiles` where `ats_score` is not null
    - `employerAnalytics(Request $request)` — role:employer:
      - Scoped to `employer_id = auth()->id()`
      - Job post counts (total, active, inactive)
      - Application counts by status across all employer's job posts
      - Per-job application counts
      - Top 10 skills from applicants' `ai_skills`
      - Average applicant `ats_score`
      - Direct offer stats (sent, accepted, declined, acceptance_rate)
      - Last 5 applications across all posts
    - `seekerAnalytics(Request $request)` — role:employee:
      - Scoped to `user_id = auth()->id()`
      - Application counts by status
      - Direct offer counts (received, accepted, declined)
      - Current ATS score and `ai_analyzed_at` from JobSeekerProfile
      - Matched jobs count (reuse matching logic)
      - Top 5 applied job categories
      - Last 5 applications with job title and status
    - Register routes: `GET /api/admin/analytics`, `GET /api/employer/analytics`, `GET /api/job-seeker/analytics`
    - _Requirements: 10.1–10.20_

  - [x] 12.2 Write feature test: `tests/Feature/AnalyticsTest.php`
    - Full flow from registration to analytics for each role:
    - **Admin flow**: register admin → create job seekers + employers + job posts + applications → call `/api/admin/analytics` → assert all counts match known state, assert `by_role` breakdown, assert `by_status` breakdown, assert `top_skills` is an array, assert `registrations_by_month` has entries
    - **Employer flow**: register + approve employer → create job posts → job seeker applies → send direct offer → call `/api/employer/analytics` → assert job counts, application counts, offer stats are scoped to this employer only
    - **Job seeker flow**: register job seeker → upload CV (mock AI) → apply to jobs → receive offers → call `/api/job-seeker/analytics` → assert application counts, offer counts, ats_score present, matched_jobs_count >= 0
    - Assert cross-role isolation: employer A's analytics never include employer B's data
    - _Requirements: 10.1–10.20_

- [x] 13. Implement MatchedJobsController
  - [x] 13.1 Create `app/Http/Controllers/API/MatchedJobsController.php`
    - `index(Request $request)` — role:employee:
      - Load seeker's `JobSeekerProfile`; if none exists, fall back to active jobs ordered by `created_at` desc with `match_score: 0`
      - Collect seeker skills: merge `ai_skills` (array of strings) + `skills[].name` (from structured skills array), lowercase, deduplicate
      - Fetch all active job posts not in the seeker's applied `job_post_id` list
      - For each post compute `match_score`:
        - Collect post skills: merge `roles` + `tags`, lowercase, deduplicate
        - +2 per intersecting skill
        - +3 if `location` partial-matches seeker `ai_location` or `location` (case-insensitive)
        - +2 if `job_type` is in seeker `job_types`
        - +2 if `experience_level` matches seeker `job_level` (case-insensitive)
      - Sort by `match_score` desc, then `created_at` desc
      - Apply `?min_score=` filter if provided
      - Paginate 10 per page; include `match_score` on each item
    - Register route: `GET /api/job-seeker/matched-jobs`
    - _Requirements: 11.1–11.10_

  - [x] 13.2 Write feature test: `tests/Feature/MatchedJobsTest.php`
    - Full flow: register job seeker → register + approve employer → employer creates job posts with various skills/locations/types → job seeker uploads CV (mock AI response with known skills) → call `/api/job-seeker/matched-jobs`
    - Assert posts with matching skills have higher `match_score` than posts with no overlap
    - Assert location bonus is applied when location matches
    - Assert job_type bonus is applied when type matches
    - Assert experience_level bonus is applied when level matches
    - Assert a post the seeker has already applied to does NOT appear in results
    - Assert `?min_score=5` filters out low-scoring posts
    - Assert response is paginated with correct envelope
    - Assert seeker with no profile gets active jobs with `match_score: 0`
    - Assert seeker with no skills still gets location/type/level bonuses
    - _Requirements: 11.1–11.10_
