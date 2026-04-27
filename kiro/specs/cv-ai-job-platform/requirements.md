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
- **Admin**: A user with the `admin` role who manages employer approvals.

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

1. WHEN a Job_Seeker requests their profile, THE JobSeeker_Profile_Controller SHALL return the full JobSeekerProfile document including any AI_Analysis fields.
2. WHEN a Job_Seeker submits a profile update with valid fields, THE JobSeeker_Profile_Controller SHALL update only the provided fields and return the updated profile.
3. IF a Job_Seeker submits a profile update with invalid data (e.g., non-numeric expected salary, URL exceeding 255 characters), THEN THE JobSeeker_Profile_Controller SHALL return a 422 response listing each validation error.
4. THE JobSeekerProfile SHALL store the `cv_file_path` field referencing the most recently uploaded CV file.
5. WHEN a Job_Seeker uploads a new CV, THE CV_Upload_Handler SHALL replace the stored `cv_file_path` with the new file path and delete the previous file from storage.

---

### Requirement 3: Job Seeker Browsing and Searching Job Posts

**User Story:** As a job seeker, I want to search and browse active job posts, so that I can find positions that match my skills and preferences.

#### Acceptance Criteria

1. WHEN a Job_Seeker requests the job search endpoint, THE Job_Search_Controller SHALL return a paginated list of active Job_Posts (15 per page by default).
2. WHEN a Job_Seeker provides a `keyword` query parameter, THE Job_Search_Controller SHALL return only Job_Posts where the title, description, or company name contains the keyword (case-insensitive).
3. WHEN a Job_Seeker provides a `location` query parameter, THE Job_Search_Controller SHALL return only Job_Posts where the location field contains the provided value (case-insensitive).
4. WHEN a Job_Seeker provides a `job_type` query parameter, THE Job_Search_Controller SHALL return only Job_Posts matching the specified type (`full_time`, `part_time`, `contract`, or `freelance`).
5. WHEN a Job_Seeker provides a `category` query parameter, THE Job_Search_Controller SHALL return only Job_Posts matching the specified category.
6. WHEN a Job_Seeker provides a `min_salary` query parameter, THE Job_Search_Controller SHALL return only Job_Posts where the salary range minimum is greater than or equal to the provided value.
7. WHEN multiple filter parameters are provided simultaneously, THE Job_Search_Controller SHALL apply all filters conjunctively (AND logic).
8. WHEN a Job_Seeker requests a specific Job_Post by ID, THE Job_Search_Controller SHALL return the full Job_Post details including employer company name.
9. IF a requested Job_Post ID does not exist, THEN THE Job_Search_Controller SHALL return a 404 response.

---

### Requirement 4: Job Seeker Applying to Job Posts

**User Story:** As a job seeker, I want to apply to job posts, so that I can express my interest to employers.

#### Acceptance Criteria

1. WHEN a Job_Seeker submits an application to an active Job_Post, THE Application_Controller SHALL create an Application record with status `pending` and return a 201 response.
2. IF a Job_Seeker attempts to apply to a Job_Post they have already applied to, THEN THE Application_Controller SHALL return a 409 response indicating a duplicate application.
3. IF a Job_Seeker attempts to apply to a Job_Post that does not exist or is inactive, THEN THE Application_Controller SHALL return a 404 response.
4. WHEN a Job_Seeker submits an application without attaching a CV file, THE Application_Controller SHALL use the CV file path stored in the Job_Seeker's JobSeekerProfile as the application resume.
5. WHEN a Job_Seeker submits an application with an attached CV file, THE Application_Controller SHALL store the file and use it as the application resume.
6. WHEN a Job_Seeker requests their application list, THE Application_Controller SHALL return a paginated list of the Job_Seeker's Applications including the associated Job_Post title and company name.
7. WHEN a Job_Seeker withdraws a pending application, THE Application_Controller SHALL delete the Application record and return a 200 response.
8. IF a Job_Seeker attempts to withdraw an application with status `accepted`, THEN THE Application_Controller SHALL return a 403 response.

---

### Requirement 5: Employer Job Post Management

**User Story:** As an employer, I want to create, update, and manage my job posts, so that I can attract suitable candidates.

#### Acceptance Criteria

1. WHEN an approved Employer submits a valid job post creation request, THE Job_Post_Controller SHALL create a Job_Post record associated with the employer and return a 201 response.
2. THE Job_Post_Controller SHALL require the following fields for job post creation: `title` (string, max 150), `description` (string), `requirements` (string), `company_name` (string, max 150), `job_type` (one of: `full_time`, `part_time`, `contract`, `freelance`).
3. WHEN an Employer creates a Job_Post, THE Job_Post_Controller SHALL set `is_active` to `true` by default.
4. WHEN an Employer updates their own Job_Post with valid data, THE Job_Post_Controller SHALL apply the changes and return the updated Job_Post.
5. IF an Employer attempts to update a Job_Post that belongs to a different employer, THEN THE Job_Post_Controller SHALL return a 403 response.
6. WHEN an Employer deactivates a Job_Post, THE Job_Post_Controller SHALL set `is_active` to `false`, preventing it from appearing in Job_Seeker search results.
7. WHEN an Employer requests their job post list, THE Job_Post_Controller SHALL return all Job_Posts belonging to that employer, including application counts.
8. WHEN an Employer deletes their own Job_Post, THE Job_Post_Controller SHALL remove the record and return a 200 response.
9. IF an Employer attempts to delete a Job_Post that belongs to a different employer, THEN THE Job_Post_Controller SHALL return a 403 response.

---

### Requirement 6: Employer Searching Job Seekers

**User Story:** As an employer, I want to search and filter job seekers by skills, ATS score, and other criteria, so that I can identify the best candidates for my positions.

#### Acceptance Criteria

1. WHEN an approved Employer requests the job seeker search endpoint, THE Employer_Search_Controller SHALL return a paginated list of Job_Seekers whose profiles are marked `is_actively_seeking = true` (10 per page by default).
2. WHEN an Employer provides a `skills` query parameter (comma-separated list), THE Employer_Search_Controller SHALL return only Job_Seekers whose `skills` array contains all of the specified skills (case-insensitive).
3. WHEN an Employer provides a `min_ats_score` query parameter, THE Employer_Search_Controller SHALL return only Job_Seekers whose `ats_score` is greater than or equal to the provided value.
4. WHEN an Employer provides a `max_ats_score` query parameter, THE Employer_Search_Controller SHALL return only Job_Seekers whose `ats_score` is less than or equal to the provided value.
5. WHEN an Employer provides a `location` query parameter, THE Employer_Search_Controller SHALL return only Job_Seekers whose profile location contains the provided value (case-insensitive).
6. WHEN an Employer provides a `keyword` query parameter, THE Employer_Search_Controller SHALL return only Job_Seekers whose `summary` or `current_job_title` contains the keyword (case-insensitive).
7. WHEN multiple filter parameters are provided simultaneously, THE Employer_Search_Controller SHALL apply all filters conjunctively (AND logic).
8. WHEN an Employer requests a specific Job_Seeker's public profile by user ID, THE Employer_Search_Controller SHALL return the Job_Seeker's profile excluding sensitive fields (password, email, phone).
9. IF a requested Job_Seeker profile does not exist or the user is not a job seeker, THEN THE Employer_Search_Controller SHALL return a 404 response.

---

### Requirement 7: Employer Sending Direct Job Offers

**User Story:** As an employer, I want to send direct job offers to specific job seekers, so that I can proactively recruit candidates I find suitable.

#### Acceptance Criteria

1. WHEN an approved Employer sends a Direct_Offer to a Job_Seeker, THE Direct_Offer_Controller SHALL create a Direct_Offer record with status `pending` and return a 201 response.
2. THE Direct_Offer_Controller SHALL require the following fields: `job_seeker_id` (valid user ID with employee role), `job_post_id` (valid Job_Post ID belonging to the employer), `message` (string, max 1000 characters).
3. IF an Employer attempts to send a Direct_Offer referencing a Job_Post that does not belong to them, THEN THE Direct_Offer_Controller SHALL return a 403 response.
4. IF an Employer attempts to send a Direct_Offer to a user who is not a Job_Seeker, THEN THE Direct_Offer_Controller SHALL return a 422 response.
5. IF an Employer has already sent a Direct_Offer for the same Job_Post to the same Job_Seeker, THEN THE Direct_Offer_Controller SHALL return a 409 response.
6. WHEN a Job_Seeker requests their received Direct_Offers, THE Direct_Offer_Controller SHALL return a paginated list of Direct_Offers addressed to that Job_Seeker, including the Job_Post title and employer company name.
7. WHEN a Job_Seeker accepts a Direct_Offer, THE Direct_Offer_Controller SHALL update the Direct_Offer status to `accepted` and automatically create an Application record with status `pending`.
8. WHEN a Job_Seeker declines a Direct_Offer, THE Direct_Offer_Controller SHALL update the Direct_Offer status to `declined`.
9. IF a Job_Seeker attempts to accept or decline a Direct_Offer that was not addressed to them, THEN THE Direct_Offer_Controller SHALL return a 403 response.
10. WHEN an Employer requests their sent Direct_Offers, THE Direct_Offer_Controller SHALL return a paginated list of Direct_Offers sent by that employer, including the Job_Seeker name and offer status.

---

### Requirement 8: Application Status Management by Employer

**User Story:** As an employer, I want to review and update the status of applications to my job posts, so that I can manage my hiring pipeline.

#### Acceptance Criteria

1. WHEN an Employer requests applications for one of their Job_Posts, THE Application_Controller SHALL return a paginated list of Applications for that post, including the applicant's name and ATS score.
2. WHEN an Employer updates an Application status to `reviewed`, `accepted`, or `rejected`, THE Application_Controller SHALL persist the new status and return the updated Application.
3. IF an Employer attempts to update the status of an Application for a Job_Post that does not belong to them, THEN THE Application_Controller SHALL return a 403 response.
4. WHEN an Employer provides feedback text when updating an Application, THE Application_Controller SHALL store the feedback on the Application record.
5. IF an Employer attempts to set an Application status to a value outside of `pending`, `reviewed`, `accepted`, or `rejected`, THEN THE Application_Controller SHALL return a 422 response.
