# CV AI Job Platform — Backend Technical Report

## 1. Introduction

This report presents a comprehensive analysis of the backend architecture, design patterns, and feature set of the **CV AI Job Platform** — a RESTful API built to connect job seekers with employers through artificial intelligence–powered CV analysis, candidate matching, and scheduling services. The system is implemented in PHP 8.2 using the Laravel 12 framework with MongoDB as its primary data store, and exposes a stateless JSON API consumed by a separate frontend application.

The platform serves three user roles: **job seekers** (employees) who upload CVs and apply to job postings, **employers** who post vacancies and search for candidates, and **administrators** who govern the approval pipeline and monitor platform health through business intelligence dashboards.

---

## 2. Technology Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| Language | PHP 8.2+ | Server-side scripting |
| Framework | Laravel 12 | MVC web framework, routing, middleware, DI container |
| Database | MongoDB 7.x | Document-oriented storage via `mongodb/laravel-mongodb` driver |
| Authentication | JWT (JSON Web Tokens) | Stateless auth via `php-open-source-saver/jwt-auth` |
| File Storage | Cloudinary | CV/resume and image hosting (`cloudinary-labs/cloudinary-laravel`) |
| External AI Services | Custom Python microservices | CV analysis, job matching, resume matching, resume coaching |
| Google Integration | Google Calendar API | OAuth2-based meeting scheduling with Google Meet link generation |
| API Documentation | Knuckles Scribe | Auto-generated OpenAPI documentation |
| Testing | Pest v4 | BDD-style testing framework with Laravel plugin |
| Code Style | Laravel Pint | PSR-12 compliant code formatting |
| CSV Processing | League CSV v9 | Bulk employer onboarding via CSV import |

### 2.1 Dependency Summary (composer.json)

The production dependencies are deliberately minimal:

- `laravel/framework ^12.0` — core framework
- `mongodb/laravel-mongodb ^5.5` — Eloquent driver for MongoDB
- `php-open-source-saver/jwt-auth ^2.8` — JWT authentication
- `cloudinary-labs/cloudinary-laravel ^3.0` — Cloudinary SDK integration
- `google/apiclient 2.16` — Google APIs PHP Client
- `league/csv ^9.21` — CSV parsing for bulk operations
- `knuckleswtf/scribe ^5.10` — API documentation generation

Development dependencies include Pest for testing, Laravel Pint for code style, and Faker for test data generation.

---

## 3. System Architecture

### 3.1 Architectural Style

The platform follows a **layered monolithic architecture** organised around the Model-View-Controller (MVC) pattern, with the "View" layer replaced by JSON responses since this is a headless API. The layers are:

1. **Routing Layer** (`routes/api.php`) — maps HTTP verbs and URIs to controller actions, applies middleware groups
2. **Middleware Layer** — authentication (JWT) and authorization (role checking)
3. **Controller Layer** (`app/Http/Controllers/API/`) — request validation, orchestration, response formatting
4. **Service Layer** (`app/Services/`) — encapsulated business logic, external API integrations
5. **Model Layer** (`app/Models/`) — Eloquent ODM models with relationships, casts, and domain methods
6. **Observer Layer** (`app/Observers/`) — side-effect handling (notifications, audit logs)

### 3.2 Directory Structure

```
app/
├── Console/Commands/         # Artisan CLI commands
├── Exceptions/               # Typed exceptions (CvAnalysisException, DocumentUploadException)
├── Http/
│   ├── Controllers/API/      # 27 API controllers (one per resource/domain)
│   ├── Middleware/           # CheckRole middleware
│   └── Requests/            # Form Request validation classes
├── Jobs/                     # Queued jobs (SendBroadcastJob, SendBulkInviteJob)
├── Models/                   # 13 Eloquent/MongoDB models
├── Notifications/           # 4 Mailable notification classes
├── Observers/               # 2 Eloquent model observers
├── Providers/               # AppServiceProvider (singleton bindings, observer registration)
└── Services/                # 16 service classes
```

### 3.3 API Route Organisation

Routes are grouped hierarchically by authentication requirement and role:

1. **Public routes** (no authentication): job listings, company profiles, user search
2. **Authenticated routes** (JWT required, no role): notifications, meetings, employer application
3. **Role-guarded routes**: job seeker (`role:employee`), employer (`role:employer`), admin (`role:admin`)

The admin role implicitly grants access to all routes through the `CheckRole` middleware's universal bypass logic.

---

## 4. Design Patterns and Principles

### 4.1 Service Layer Pattern

Business logic is extracted from controllers into dedicated service classes, promoting single responsibility and testability. Examples:

- `CvAnalysisService` — encapsulates HTTP communication with the external AI analysis microservice
- `MeetingService` — orchestrates meeting lifecycle (creation, acceptance, rescheduling) with conflict detection and Google Calendar synchronisation
- `BroadcastService` — resolves recipient audiences and dispatches queued notification jobs
- `DocumentUploadService` — manages Cloudinary uploads with PDF delivery workarounds and delivery verification

Controllers remain thin orchestrators that validate input, delegate to services, and format responses.

### 4.2 Observer Pattern (Event-Driven Side Effects)

Eloquent model observers decouple core CRUD operations from their side effects:

- **NotificationObserver** — listens to `created` and `updated` events on `Application`, `DirectOffer`, and `Employer` models; creates in-app notifications and dispatches email notifications
- **EmployerObserver** — writes immutable audit log entries when an employer application is approved or rejected

This pattern ensures that notification logic does not pollute controller code and that side effects remain consistent regardless of where a model is modified.

### 4.3 Data Transfer Object (DTO) Pattern

The `StoredDocument` class is an immutable value object that encapsulates the result of a file upload:

```php
final class StoredDocument
{
    public function __construct(
        public readonly string $url,
        public readonly string $publicId,
        public readonly string $resourceType,
        public readonly ?string $mimeType = null,
        public readonly ?string $originalName = null,
    ) {}
}
```

This eliminates the need to pass multiple loosely-typed parameters between the upload service and consuming code.

### 4.4 Typed Exception Pattern

Domain-specific exceptions carry HTTP status codes alongside error messages:

- `CvAnalysisException` — wraps failures from external AI services (422 for validation failures, 502 for connectivity issues)
- `DocumentUploadException` — distinguishes upload failures from delivery failures with actionable error messages

Controllers catch these typed exceptions and map them directly to appropriate HTTP responses without generic catch-all handling.

### 4.5 Repository-Free Eloquent Usage

The project opts to use Eloquent models directly rather than introducing a repository abstraction layer. This is a deliberate choice for a MongoDB-backed application where the query builder's flexibility (regex queries, `elemMatch`, raw BSON types) would be difficult to abstract behind a generic interface without leaking implementation details.

### 4.6 Denormalisation for Query Performance

Company identity fields (`company_name`, `company_logo`) are denormalised onto `JobPost` documents at creation time. This avoids JOIN-equivalent lookups (which MongoDB handles less efficiently than relational databases) when listing job posts, while keeping the data immutable on the post to preserve historical accuracy.

### 4.7 Middleware-Based Authorization

A single `CheckRole` middleware handles all role-based access control:

1. Admins bypass all role checks
2. Users with any of the required roles are allowed through
3. Employers face an additional approval gate — the `is_employer` flag must be set by an admin before accessing employer-only routes

This centralises authorization logic and eliminates repetitive permission checks in controllers.

---

## 5. Authentication and Security

### 5.1 JWT Authentication Flow

The system uses stateless JWT tokens with HMAC-SHA256 signing:

- **Registration**: creates user, assigns role, returns JWT token immediately (auto-login)
- **Login**: validates credentials, returns token with 60-minute TTL
- **Refresh**: issues a new token within a 2-week refresh window
- **Logout**: invalidates the token via blacklisting

Token configuration (from `config/jwt.php`):
- TTL: 60 minutes
- Refresh TTL: 20160 minutes (14 days)
- Algorithm: HS256
- Blacklisting enabled

### 5.2 Password Security

Registration enforces strong password policies:
- Minimum 8 characters
- At least one uppercase letter
- At least one lowercase letter
- At least one digit
- At least one special character (`@$!%*#?&`)
- Confirmation field must match

### 5.3 Role-Based Access Control (RBAC)

Three roles with hierarchical privileges:
- `employee` — job seeker operations
- `employer` — job posting and candidate management (requires admin approval)
- `admin` — universal access, platform governance

The `User` model provides helper methods: `hasRole()`, `hasAnyRole()`, `isEmployer()`, `isJobSeeker()`, `isAdmin()`.

### 5.4 Input Validation

The system uses Laravel's validation system extensively:
- Inline `Validator::make()` calls in controllers for complex, context-dependent rules
- Dedicated `FormRequest` classes (`BulkOnboardingRequest`, `BroadcastRequest`, `CreateMeetingRequest`, `RescheduleMeetingRequest`) for reusable validation
- All request data is validated before any database interaction

### 5.5 Ownership Checks

Controllers enforce resource ownership before mutations. For example, job post updates verify that the authenticated employer's ID matches the post's `employer_id`. This prevents horizontal privilege escalation where an authenticated employer could modify another employer's resources.

---

## 6. Data Layer — MongoDB Models

### 6.1 Model Architecture

All models extend `MongoDB\Laravel\Eloquent\Model` (or `MongoDB\Laravel\Auth\User` for the authentication model). This provides:

- Automatic ObjectId generation for `_id` fields
- Eloquent-compatible relationships (`belongsTo`, `hasMany`, `hasOne`)
- Attribute casting (arrays, booleans, integers, datetimes)
- Mass-assignment protection via `$fillable`

### 6.2 Core Models

| Model | Collection | Key Fields | Relationships |
|-------|-----------|-----------|--------------|
| User | users | name, email, password, roles, is_employer | hasOne(JobSeekerProfile), hasMany(Application, JobPost) |
| JobSeekerProfile | job_seeker_profiles | Personal info, career info, skills[], education[], work_experience[], AI fields (ai_skills, ats_score, etc.) | belongsTo(User) |
| JobPost | job_posts | title, description, job_type, work_mode, salary range, questions[], tags[], is_active | belongsTo(User), belongsTo(CompanyProfile), hasMany(Application) |
| CompanyProfile | company_profiles | name, slug, logo, description, private_info{}, ratings, reviews[] | belongsTo(User) |
| Application | applications | user_id, job_post_id, status, cover_letter, resume, eager profile fields | belongsTo(User), belongsTo(JobPost) |
| DirectOffer | direct_offers | employer_id, job_seeker_id, job_post_id, message, status | belongsTo(User), belongsTo(JobPost) |
| Employer | employers | user_id, status, document_path, reviewed_by, partner_type | belongsTo(User) |
| Meeting | meetings | organizer_id, invitee_id, title, meeting_type, proposed_date/time, status, notes[], previous_schedules[] | belongsTo(User) ×2 |
| Notification | notifications | user_id, type, message, related_entity_id/type, read_at | belongsTo(User) |
| AuditLog | audit_logs | action, actor_id, actor_name, target_id, target_type, metadata{} | — |
| GoogleOAuthToken | google_oauth_tokens | user_id, access_token (encrypted), refresh_token (encrypted), is_valid | belongsTo(User) |
| CoachSession | coach_sessions | user_id, title | hasMany(CoachMessage) |
| CoachMessage | coach_messages | session_id, role, content | belongsTo(CoachSession) |

### 6.3 Database Indexing Strategy

Migrations create MongoDB indexes for performance-critical queries:

- `audit_logs`: indexes on `actor_id`, `created_at` (descending), `action`
- `notifications`: compound index on `user_id` + `read_at`, index on `created_at` (descending)

### 6.4 AI-Derived Fields Convention

Fields populated by external AI analysis are prefixed with `ai_` (e.g., `ai_skills`, `ai_summary`, `ai_work_history`, `ai_overall_evaluation`). The exception is `ats_score`, which is unprefixed because it serves as a filterable/searchable metric across the platform.

---

## 7. Feature Catalogue

### 7.1 Authentication and User Management

- **Registration** with role assignment and strong password validation
- **Login** with JWT token issuance
- **Token refresh** within a 14-day window
- **Logout** with token blacklisting
- **Profile retrieval** for authenticated users
- **Public user profile viewing** (any user can view any other user's profile)

### 7.2 Job Seeker Features

#### Profile Management (Sectioned Updates)
- Personal information (name, image upload to Cloudinary, gender, nationality, contact details)
- Career information (job status, experience, salary expectations, actively seeking flag)
- Social links (LinkedIn, GitHub, portfolio, Twitter)
- Skills (name + level: beginner/intermediate/advanced/expert)
- Education history (certificate type, university, faculty, major, dates)
- Work experience (job title, company, roles, dates, description)

#### CV Upload and AI Analysis
- Upload CV (PDF/DOC/DOCX, max 10MB) to Cloudinary
- Automatic AI analysis extracting: skills, work history, education, languages, projects, ATS score, overall evaluation
- Analysis status tracking (pending → processing → completed/error)
- Retry failed analysis
- Resume deletion with proper Cloudinary cleanup

#### Job Applications
- Apply to active job posts with optional cover letter, resume override, and eager profile fields
- Default cover letter auto-attachment
- Duplicate application prevention
- Application withdrawal (pending only)
- Application history with job post details

#### AI-Powered Features
- **Matched Jobs** — local algorithm scoring posts by skill overlap (+2), location match (+3), job type match (+2), experience level match (+2)
- **Resume-to-Jobs Matching** — external AI service finding compatible job posts
- **Resume Coach** — AI chat sessions for resume improvement advice

#### Direct Offers
- View received offers from employers
- Accept offers (auto-creates application)
- Decline offers

#### Analytics
- Application statistics by status
- Offers received/accepted/declined counts
- ATS score and analysis date
- Available matched jobs count
- Top applied job categories

### 7.3 Employer Features

#### Company Profile Management
- Create/update public profile (name, description, industry, size, location, phone, email)
- Private info block (address, industry tags, founded year, website, social media)
- Expose-to-applicants toggle for private fields
- Logo and cover image upload (Cloudinary)
- Slug-based public URL generation

#### Job Post Management
- Create job posts with comprehensive fields (title, roles, requirements, questions, salary, location, etc.)
- Auto-populate company identity from profile
- Update, delete, activate, deactivate posts
- View own posts with application counts
- Human-readable job IDs (JOB-0001, JOB-0002, etc.)

#### Application Management
- View applications per job post with applicant name and ATS score
- Update application status (pending → reviewed → accepted → rejected)
- Provide feedback to applicants

#### Candidate Search
- Search actively-seeking job seekers by skills (all must match), ATS score range, location, keyword
- View individual job seeker profiles (filtered field set)

#### Direct Offers
- Send personalised offers to specific seekers for specific posts
- Duplicate offer prevention
- View sent offers with status tracking

#### AI-Powered Features
- **Job-to-Candidate Matching** — provide job description, receive ranked candidates
- **Match Candidates to Job Post** — use existing post's description for matching

#### Analytics
- Job post statistics (total, active, inactive)
- Applications by status, per-job breakdown
- Offers sent/accepted/declined
- Top applicant skills
- Average applicant ATS score
- Recent applications feed

### 7.4 Administrator Features

#### Employer Governance
- View pending employer applications
- Approve (grants employer role + sets is_employer flag)
- Reject (with optional review notes)
- Approval triggers email notification to applicant

#### User Management
- List all users (paginated)
- List all job seekers with full profiles
- List all employers with company profiles

#### Business Intelligence Reports
- **Churn Report** — inactive employers (no posts within window) and seekers with CV but no applications; CSV export
- **Conversion Funnel** — registered → CV uploaded → applied → hired with drop-off percentages
- **Approval Pipeline** — pending employers with wait times and estimated lost revenue
- **Top Categories** — job categories ranked by applications or posts
- **Talent Market Report** — anonymised skill demand and ATS score statistics; CSV export; PII excluded

#### Platform Operations
- **Broadcast System** — send email + in-app notification to employees, employers, all, or specific user IDs; queued delivery
- **Bulk Onboarding** — CSV upload creating employer accounts with invite email dispatch; validates required columns, skips duplicates
- **Manual CV Re-analysis** — trigger fresh AI analysis for any job seeker
- **Audit Log Viewer** — filterable, paginated log of admin actions (approvals, rejections, broadcasts, re-analyses, bulk onboarding)

#### Meeting Oversight
- View all platform meetings with participant info
- Filter by status, date range

### 7.5 Notification System

- In-app notifications stored in MongoDB with read/unread tracking
- Notification types: application_status_changed, direct_offer_received, employer_decision, broadcast, new_application, meeting_invitation/accepted/declined/cancelled/rescheduled
- Endpoints: list (paginated), mark read, mark all read, unread count
- Email notifications via Laravel Notification classes (queued with ShouldQueue)

### 7.6 Meeting Scheduling System

#### Core Functionality
- Create meetings between employer and seeker (opposite roles enforced)
- Meeting types: in_person, phone_call, video_call
- Status lifecycle: pending → accepted/declined/cancelled/rescheduled → completed

#### Google Calendar Integration
- OAuth2 flow (connect, callback, status, disconnect)
- Encrypted token storage with automatic refresh
- Auto-create Google Calendar events with Meet links on acceptance
- Auto-update calendar events on reschedule
- Auto-delete calendar events on cancellation

#### Advanced Features
- Conflict detection (time overlap algorithm using minutes-since-midnight)
- Meeting notes (append-only, participant-restricted)
- Reschedule history (previous_schedules array)
- Upcoming meetings widget (next 5 accepted)

---

## 8. External Service Integration Architecture

The platform integrates with four external AI microservices through a consistent pattern:

```
Controller → Service → HTTP::post(config URL) → Parse Response → Return/Throw
```

### 8.1 Integration Points

| Service | Config Key | Purpose | Timeout |
|---------|-----------|---------|---------|
| CV Analysis | `services.cv_analysis.url` | Extract structured data from CVs | 120s |
| Job Matching | `services.job_matching.url` | Match job description to candidates | Default |
| Resume Matching | `services.resume_matching.url` | Match resume to available jobs | 300s |
| Resume Coach | `services.resume_coach.url` | AI chat for resume improvement | 60s |

### 8.2 Error Handling Pattern

All external service calls follow a three-tier error handling strategy:
1. **Network/timeout errors** → caught as Throwable → throw `CvAnalysisException` with 502 status
2. **HTTP error responses** → check `$response->failed()` → throw with upstream status code
3. **Business logic failures** → check `status !== 'success'` in response body → throw with 422 status

### 8.3 Google Calendar Integration

The Google Meet service uses OAuth2 with offline access and automatic token refresh. Tokens are stored encrypted using Laravel's `Crypt` facade. Calendar operations (create, update, delete) are wrapped in try-catch blocks that log failures but never block the main meeting flow.

---

## 9. File Storage Architecture (Cloudinary)

### 9.1 Upload Strategy

Documents are uploaded with `resource_type: raw` to preserve original file bytes. The `DocumentUploadService` handles several Cloudinary-specific concerns:

1. **Extension derivation** — creates a temp copy with the correct extension because Laravel's temp files use `.tmp`
2. **PDF delivery** — Cloudinary blocks PDF delivery by default; the service supports both direct PDF delivery (when enabled in Cloudinary console) and a fallback extension approach
3. **Delivery verification** — after upload, performs a HEAD request to confirm the URL is publicly accessible before passing it to the AI service

### 9.2 File Lifecycle

```
Upload → Store to Cloudinary → Verify Deliverable → Record on Profile → Trigger AI Analysis
```

On replacement: old file deleted from Cloudinary → new file uploaded → AI fields cleared → new analysis triggered.

---

## 10. Testing Strategy

### 10.1 Framework and Configuration

- **Pest v4** with Laravel plugin for HTTP-level feature testing
- Test database: separate MongoDB instance (`laravel_test` on `localhost:27017`)
- Tests clear config cache before execution

### 10.2 Test Organisation

```
tests/
├── Feature/          # HTTP-level integration tests
│   ├── AuthTest.php  # Registration, login, profile, logout, refresh
│   ├── MeetingNoteTest.php  # Meeting notes access control
│   └── ...
├── Helpers/
│   └── TestUserHelper.php   # Shared trait for user registration in tests
└── Unit/             # Service-level unit tests
```

### 10.3 Test Characteristics

- Tests create and clean up their own data (no shared state)
- User factory with role-specific states (`employee()`, `employer()`, `admin()`)
- JWT tokens generated via `auth('api')->login($user)` for authenticated requests
- Comprehensive validation testing (all rule permutations for registration)
- Access control testing (403 for non-participants, 401 for unauthenticated)

---

## 11. API Response Conventions

### 11.1 Pagination Format

All paginated endpoints return a consistent envelope:

```json
{
  "data": [...],
  "current_page": 1,
  "per_page": 15,
  "total": 42,
  "total_pages": 3,
  "next_page": 2,
  "prev_page": null
}
```

### 11.2 Error Responses

- **Validation errors**: `{ "errors": { "field": ["message"] } }` with HTTP 422
- **Not found**: `{ "message": "Resource not found" }` with HTTP 404
- **Forbidden**: `{ "message": "Forbidden" }` with HTTP 403
- **Unauthorized**: `{ "error": "Unauthorized", "message": "..." }` with HTTP 401
- **Conflict**: `{ "message": "..." }` with HTTP 409

### 11.3 Success Responses

- **Create**: HTTP 201 with created resource
- **Update/Action**: HTTP 200 with updated resource or confirmation message
- **Delete**: HTTP 200 with `{ "message": "... deleted successfully" }`

---

## 12. Queued Job Processing

The platform uses Laravel's queue system for asynchronous operations:

- `SendBroadcastJob` — sends individual email + creates in-app notification per recipient
- `SendBulkInviteJob` — dispatches invitation emails to bulk-onboarded employers

The development environment runs both the application server and queue worker concurrently via `composer dev` (using `concurrently` to run `php artisan serve`, `php artisan queue:listen`, and `npm run dev` in parallel).

---

## 13. Audit and Compliance

### 13.1 Audit Log Design

The `AuditLog` model is append-only (no `updated_at` column) and records:
- **Action type**: employer_approved, employer_rejected, broadcast_sent, cv_reanalysis_triggered, bulk_employer_onboarded
- **Actor identification**: admin user ID and denormalised name
- **Target**: optional entity ID and type
- **Metadata**: arbitrary JSON context (counts, reasons, etc.)

### 13.2 Audit Trigger Points

Audit entries are written from two sources:
1. **EmployerObserver** — automatically on employer status changes
2. **Service/Controller level** — explicitly via `AuditLogService::log()` for broadcasts, re-analysis, and bulk operations

### 13.3 Privacy Considerations

The `TalentReportService` explicitly defines PII fields that must never appear in aggregated reports:
```php
private const PII_FIELDS = [
    'name', 'email', 'phone', 'user_id',
    'ai_email', 'ai_phone', 'ai_full_name', 'ai_location',
];
```

Reports require at least 5 matching profiles to prevent re-identification through small dataset attacks.

---

## 14. Scalability and Performance Considerations

### 14.1 Current Approach

The system uses in-application aggregation (loading collections into PHP memory and filtering/grouping with Laravel Collections). This is visible in analytics controllers, reporting services, and search features. This approach prioritises code simplicity and avoids MongoDB aggregation pipeline complexity.

### 14.2 MongoDB Query Optimisation

- Case-insensitive searches use `MongoDB\BSON\Regex` objects
- Array field searches use `elemMatch` for AND-logic skill matching
- Denormalised fields on JobPost avoid cross-collection lookups
- Strategic indexes on high-query-volume collections

### 14.3 Trade-offs

- In-memory filtering (loading all users/posts into PHP) trades horizontal scalability for implementation simplicity
- The approach is suitable for the current scale but would require MongoDB aggregation pipelines or dedicated analytics databases for larger datasets
- External AI service calls are synchronous with generous timeouts (up to 300s), which ties up PHP-FPM workers during processing

---

## 15. Configuration Management

### 15.1 Environment Variables

The system uses `.env` files for all environment-specific configuration:
- `MONGODB_URI` — database connection string
- `JWT_SECRET` — HMAC signing key
- `CV_ANALYSIS_API_URL`, `JOB_MATCHING_API_URL`, `RESUME_MATCHING_API_URL`, `RESUME_COACH_API_URL` — external service endpoints
- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` — OAuth2 credentials
- `CLOUDINARY_*` — cloud storage credentials and PDF delivery settings

### 15.2 Service Configuration

External services are configured in `config/services.php` with environment variable fallbacks, providing a single source of truth for service URLs and feature flags (like `cloudinary.pdf_delivery_enabled`).

---

## 16. Conclusion

The CV AI Job Platform backend demonstrates a pragmatic approach to building a feature-rich REST API. It leverages Laravel's ecosystem effectively — Eloquent ODM for MongoDB, middleware for cross-cutting concerns, observers for event-driven side effects, and queues for background processing. The service layer provides clean separation of business logic from HTTP concerns, while typed exceptions enable precise error handling across external service boundaries.

The architecture balances simplicity with functionality: it avoids over-engineering (no repository pattern, no CQRS, no event sourcing) while still maintaining clear boundaries between concerns. The system's integration with multiple AI microservices through a consistent HTTP-based pattern demonstrates a practical microservices consumption strategy without the complexity of a full service mesh.

Key architectural strengths include the comprehensive notification system, the multi-layered security model (JWT + RBAC + ownership checks + employer approval gates), and the thoughtful handling of external service failures. The codebase is well-documented through Scribe annotations, making it self-documenting for API consumers.

---

## Appendix A: API Endpoint Summary

| Group | Endpoints | Auth Required | Role |
|-------|-----------|--------------|------|
| Authentication | 5 (register, login, profile, logout, refresh) | Partial | — |
| Job Posts (Public) | 3 (list, show, search) | No | — |
| Company (Public) | 2 (list, show) | No | — |
| User Search (Public) | 2 (search, profile) | No | — |
| Job Seeker | ~25 (profile sections, CV, applications, offers, matching, coach, analytics) | Yes | employee |
| Employer | ~18 (company, jobs, applications, seekers, offers, matching, analytics) | Yes | employer |
| Admin | ~16 (employers, reports, broadcast, onboarding, re-analysis, audit, meetings) | Yes | admin |
| Notifications | 4 (list, read, mark-all, count) | Yes | — |
| Meetings | ~10 (CRUD, actions, notes) | Yes | — |
| Google OAuth | 4 (connect, callback, status, disconnect) | Partial | — |

**Total: ~90 API endpoints**

## Appendix B: Model Relationship Diagram

```
User (1) ──── (1) JobSeekerProfile
  │
  ├── (1) ──── (N) Application ──── (1) JobPost
  │                                       │
  ├── (1) ──── (N) JobPost ─────────── (1) CompanyProfile
  │
  ├── (1) ──── (N) DirectOffer
  │
  ├── (1) ──── (N) Meeting (as organizer)
  ├── (1) ──── (N) Meeting (as invitee)
  │
  ├── (1) ──── (N) Notification
  │
  ├── (1) ──── (1) GoogleOAuthToken
  │
  ├── (1) ──── (N) CoachSession ──── (N) CoachMessage
  │
  └── (1) ──── (N) Employer (application records)
```

## Appendix C: Authentication Flow Sequence

```
Client                    API Server                    Database
  │                           │                            │
  │── POST /auth/register ──→│                            │
  │                           │── validate rules ────────→│
  │                           │── create User ───────────→│
  │                           │── generate JWT ──────────→│
  │←── 201 {token, user} ────│                            │
  │                           │                            │
  │── GET /job-seeker/profile │                            │
  │   Authorization: Bearer T │                            │
  │                           │── verify JWT ────────────→│
  │                           │── check role ────────────→│
  │                           │── query profile ─────────→│
  │←── 200 {profile} ────────│                            │
```

## Appendix D: CV Analysis Pipeline

```
1. User uploads CV file (PDF/DOC/DOCX)
2. DocumentUploadService stores to Cloudinary (resource_type: raw)
3. HEAD request verifies public deliverability
4. Profile.attachResume() records URL, clears old AI fields
5. CvAnalysisService.analyze() POSTs file_url to external AI
6. AI service returns structured analysis (skills, history, ATS score)
7. Profile.applyAiAnalysis() persists all ai_* fields
8. Status transitions: pending → processing → completed (or error)
```
