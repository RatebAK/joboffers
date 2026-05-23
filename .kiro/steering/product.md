# Product

CV AI Job Platform — a REST API connecting job seekers and employers.

## Core Capabilities

- Job seekers upload CVs which are analyzed by an external AI service, populating structured profile fields (skills, work history, ATS score, etc.)
- Job seekers browse/filter active job posts and submit applications
- Employers create and manage job posts, review applications, and update application statuses
- Employers search job seekers by skills, ATS score, location, and other criteria
- Employers send direct job offers to specific job seekers; seekers can accept (auto-creates an application) or decline

## User Roles

- `employee` — job seeker: manages profile, uploads CV, applies to jobs, receives direct offers
- `employer` — must be approved by admin before accessing employer routes; posts jobs, searches seekers, sends offers
- `admin` — approves/rejects employer accounts; has universal access to all routes
