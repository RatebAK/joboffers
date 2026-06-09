# Requirements Document

## Introduction

This document defines the requirements for a Laravel-based job platform API that connects job seekers and employers. Job seekers can upload CVs, have them analyzed by an external AI service, browse job posts, and apply to positions. Employers can post job offers, search job seekers by skills and AI-derived scores, and send direct job offers. An AI CV Analysis integration parses uploaded CVs and stores structured analysis results (skills, work history, ATS score, etc.) on the job seeker's profile.

The platform builds on an existing Laravel/MongoDB codebase with JWT authentication, User, Employer, JobPost, JobSeekerProfile, and Application models already in place.

---

## Glossary

- **Job_Seeker**: A user with the `employee` role who uploads a CV and applies to job posts.
- **Employer**: A user with the `employer` role whose account has been approved by an admin.
- **CV**: A PDF/DOC/DOCX file uploaded by a Job_Seeker representing their curriculum vitae.
- **AI_Analysis**: The structured JSON result returned by the external AI CV analysis service after processing a CV.
- **ATS_Score**: The numeric score (0–100) returned by the AI_Analysis representing how well a CV matches automated tracking system criteria.
- **Job_Post**: A job listing created by an Employer containing title, description, requirements, and metadata.
- **Application**: A record linking a Job_Seeker to a Job_Post, representing the seeker's intent to apply.
- **Direct_Offer**: A record created by an Employer targeting a specific Job_Seeker with a job opportunity, without requiring the seeker to apply first.
- **CV_Analysis_Service**: The external HTTP API that accepts a CV file and returns an AI_Analysis JSON payload.
- **JobSeekerProfile**: The MongoDB document storing a Job_Seeker's profile data, including AI_Analysis results.
- **CompanyProfile**: The MongoDB document storing an Employer's company information.
- **Admin**: A user with the `admin` role who manages employer approvals.
- **Match_Score**: A numeric value representing how well a Job_Post matches a Job_Seeker's profile based on skills, location, job type, and experience level.
- **Analytics_Dashboard**: A set of aggregated statistics returned by the API for a specific role (admin, employer, or job seeker).

---

## Requirements

### Requirement 1: CV Upload and AI Analysis

**User Story:** As a job seeker, I want to upload my CV and have it automatically analyzed by AI, so that my profile is enriched with structured skills, work history, and an ATS score.

#### Acceptance Criteria

1. WHEN a Job_Seeker submits a CV file (PDF, DOC, or DOCX, max 10 MB) to the upload endpoint, THE CV_Upload_Handler SHALL store the file and trigger the AI_Analysis pipeline.
2. WHEN the CV file is stored, THE CV_Analysis_Service_Client SHALL send the file to the external AI CV analysis API and await the response.
3. WHEN the CV_Analysis_Service returns a successful response, THE Profile_Updater SHALL parse the `analysis` object and persist the following fields to the Job_Seeker's JobSeekerProfile: `full_name`, `email`, `phone`, `location`, `summary`, `skills` (array), `work_history` (array), `projects` (array), `ai_overall_evaluation`, `ats_score`, and `detected_language`.
4. IF the CV file exceeds 10 MB or is not one of the accepted MIME types, THEN THE CV_Upload_Handler SHALL return a 422 response with a descriptive validation error.
5. IF the CV_Analysis_Service returns a non-success status or an HTTP error, THEN THE CV_Analysis_Service_Client SHALL log the error and return a 502 response indicating the analysis service is unavailable.
6. IF the CV_Analysis_Service returns a response where `status` is not `"success"`, THEN THE Profile_Updater SHALL not overwrite existing profile data and SHALL return a 422 response with the analysis failure reason.
7. WHEN the AI analysis completes successfully, THE CV_Upload_Handler SHALL return a 200 response containing the updated JobSeekerProfile including all persisted AI_Analysis fields.

---

### Requirement 2: Job Seeker Profile Management

**User Story:** As a job seeker, I want to view and manually update my profile, so that I can keep my information current alongside AI-populated fields.

#### Acceptance Criteria

1. WHEN a Job_Seeker requests their profile, THE JobSeeker_Profile_Controller SHALL return the full JobSeekerProfile document including any AI_Analysis f

---

### Requirement 9: View Other Users and Profiles (Full Data)

**User Story:** As any authenticated user, I want to view other users' full profile data, so that I can make informed decisions about hiring, applying, or connecting.

#### Acceptance Criteria

1. WHEN an Admin requests any user's profile by user ID, THE Profile_View_Controller SHALL return the full User record plus their associated profile (JobSeekerProfile or CompanyProfile) with all fields including sensitive ones (email, phone, ai_email, ai_phone).
2. WHEN an Employer requests a Job_Seeker's profile by user ID, THE Profile_View_Controller SHALL return the full JobSeekerProfile including all AI-derived fields, skills, work history, education, and contact details.
3. WHEN a Job_Seeker requests an Employer's profile by user ID, THE Profile_View_Controller SHALL return the full CompanyProfile including all company details, ratings, reviews, and the employer's active job posts.
4. WHEN any authenticated user requests a profile for a user ID that does not exist, THE Profile_View_Controller SHALL return a 404 response.
5. WHEN an Employer requests another Employer's profile, THE Profile_View_Controller SHALL return the CompanyProfile with all public fields.
6. WHEN a Job_Seeker requests another Job_Seeker's profile, THE Profile_View_Controller SHALL return the JobSeekerProfile with all fields except password.
7. WHEN any authenticated user requests the list of all users (admin only), THE Profile_View_Controller SHALL return a paginated list of all users with their roles and basic profile info.
8. WHEN an Admin requests the list of all job seekers, THE Profile_View_Controller SHALL return a paginated list with full JobSeekerProfile data for each.
9. WHEN an Admin requests the list of all employers, THE Profile_View_Controller SHALL return a paginated list with full CompanyProfile data for each.

---

### Requirement 10: Analytics Dashboards

**User Story:** As an admin, employer, or job seeker, I want to see analytics and statistics relevant to my role, so that I can make data-driven decisions.

#### Acceptance Criteria

**Admin Analytics (10.1–10.7):**
1. WHEN an Admin requests the admin analytics endpoint, THE Analytics_Controller SHALL return platform-wide stats including: total users broken down by role (employee, employer, admin), total active job posts, total applications, total direct offers, and total companies.
2. WHEN an Admin requests admin analytics, THE Analytics_Controller SHALL return employer approval stats: total pending applications, total approved employers, total rejected employers, and approval rate percentage.
3. WHEN an Admin requests admin analytics, THE Analytics_Controller SHALL return the top 10 most demanded skills across all job posts (based on `required_skills` / `roles` / `tags` fields).
4. WHEN an Admin requests admin analytics, THE Analytics_Controller SHALL return application status breakdown: count of pending, reviewed, accepted, and rejected applications.
5. WHEN an Admin requests admin analytics, THE Analytics_Controller SHALL return new user registrations grouped by month for the last 12 months.
6. WHEN an Admin requests admin analytics, THE Analytics_Controller SHALL return the top 10 most active employers by number of job posts.
7. WHEN an Admin requests admin analytics, THE Analytics_Controller SHALL return average ATS score across all job seeker profiles that have been analyzed.

**Employer Analytics (10.8–10.14):**
8. WHEN an Employer requests their analytics endpoint, THE Analytics_Controller SHALL return their own stats: total job posts (active and inactive), total applications received across all posts, and total direct offers sent.
9. WHEN an Employer requests their analytics, THE Analytics_Controller SHALL return application status breakdown across all their job posts: count of pending, reviewed, accepted, and rejected.
10. WHEN an Employer requests their analytics, THE Analytics_Controller SHALL return per-job-post application counts for all their posts.
11. WHEN an Employer requests their analytics, THE Analytics_Controller SHALL return the top 10 skills among applicants to their job posts (based on applicants' ai_skills).
12. WHEN an Employer requests their analytics, THE Analytics_Controller SHALL return the average ATS score of applicants to their job posts.
13. WHEN an Employer requests their analytics, THE Analytics_Controller SHALL return direct offer stats: total sent, total accepted, total declined, and acceptance rate percentage.
14. WHEN an Employer requests their analytics, THE Analytics_Controller SHALL return the most recent 5 applications across all their posts.

**Job Seeker Analytics (10.15–10.20):**
15. WHEN a Job_Seeker requests their analytics endpoint, THE Analytics_Controller SHALL return their own stats: total applications submitted, and breakdown by status (pending, reviewed, accepted, rejected).
16. WHEN a Job_Seeker requests their analytics, THE Analytics_Controller SHALL return direct offer stats: total received, total accepted, total declined.
17. WHEN a Job_Seeker requests their analytics, THE Analytics_Controller SHALL return their current ATS score and the date it was last analyzed.
18. WHEN a Job_Seeker requests their analytics, THE Analytics_Controller SHALL return the number of job posts that match their profile (matched jobs count).
19. WHEN a Job_Seeker requests their analytics, THE Analytics_Controller SHALL return the top 5 job categories/roles they have applied to.
20. WHEN a Job_Seeker requests their analytics, THE Analytics_Controller SHALL return the most recent 5 applications with job post title and current status.

---

### Requirement 11: Matched Jobs

**User Story:** As a job seeker, I want to see job posts that match my profile, so that I can quickly find the most relevant opportunities.

#### Acceptance Criteria

1. WHEN a Job_Seeker requests the matched jobs endpoint, THE MatchedJobs_Controller SHALL return a paginated list of active job posts ranked by a `match_score` in descending order.
2. WHEN computing the match_score for a job post, THE MatchedJobs_Controller SHALL award 2 points for each skill in the job post's `roles`/`tags` that matches any skill in the seeker's `ai_skills` or `skills` array (case-insensitive).
3. WHEN computing the match_score, THE MatchedJobs_Controller SHALL award 3 bonus points if the job post's `location` matches the seeker's `ai_location` or `location` (case-insensitive partial match).
4. WHEN computing the match_score, THE MatchedJobs_Controller SHALL award 2 bonus points if the job post's `job_type` matches any value in the seeker's `job_types` array.
5. WHEN computing the match_score, THE MatchedJobs_Controller SHALL award 2 bonus points if the job post's `experience_level` matches the seeker's `job_level` (case-insensitive).
6. WHEN a Job_Seeker has no profile or no AI-analyzed skills, THE MatchedJobs_Controller SHALL return active job posts ordered by creation date (newest first) with match_score of 0.
7. WHEN a Job_Seeker requests matched jobs, each returned job post SHALL include the `match_score` field alongside all standard job post fields.
8. WHEN a Job_Seeker requests matched jobs, THE MatchedJobs_Controller SHALL exclude job posts the seeker has already applied to.
9. WHEN a Job_Seeker requests matched jobs, the response SHALL be paginated with the standard pagination envelope (data, current_page, per_page, total, total_pages, next_page, prev_page).
10. WHEN a Job_Seeker requests matched jobs with a `min_score` query parameter, THE MatchedJobs_Controller SHALL only return posts with match_score >= min_score.
