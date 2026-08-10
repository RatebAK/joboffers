# Admin Business Intelligence — API & UI Requirements

> All endpoints require `Authorization: Bearer <token>` with an `admin` role, except Notifications which require any authenticated user.

---

## Table of Contents

1. [Reports](#1-reports)
   - [Churn Report](#11-churn-report)
   - [Conversion Funnel](#12-conversion-funnel)
   - [Approval Pipeline](#13-approval-pipeline)
   - [Top Job Categories](#14-top-job-categories)
   - [Talent Market Report](#15-talent-market-report)
2. [Operations](#2-operations)
   - [Bulk Employer Onboarding](#21-bulk-employer-onboarding)
   - [Platform Broadcast](#22-platform-broadcast)
   - [Manual CV Re-analysis](#23-manual-cv-re-analysis)
   - [Audit Log](#24-audit-log)
3. [Notifications (All Users)](#3-notifications-all-users)
   - [List Notifications](#31-list-notifications)
   - [Unread Count](#32-unread-count)
   - [Mark One as Read](#33-mark-one-as-read)
   - [Mark All as Read](#34-mark-all-as-read)

---

## 1. Reports

### 1.1 Churn Report

**Endpoint:** `GET /api/admin/reports/churn`

**Purpose:** Identifies inactive employers (no posts within a time window) and job seekers who uploaded a CV but never applied. Used to build re-engagement email campaigns.

**Query Parameters:**

| Parameter    | Type    | Default | Description |
|---|---|---|---|
| `window_days`| integer | `30`    | Inactivity window. Valid: `30`, `60`, `90`. Invalid values default to `30`. |
| `format`     | string  | —       | Set to `csv` to download as a file. |

**Response (JSON):**

```json
{
  "window_days": 30,
  "employers": [
    {
      "user_id": "string",
      "name": "string",
      "email": "string",
      "registered_at": "ISO8601",
      "last_post_date": "ISO8601 | null",
      "total_posts": 0
    }
  ],
  "seekers": [
    {
      "user_id": "string",
      "name": "string",
      "email": "string",
      "registered_at": "ISO8601",
      "cv_uploaded_at": "ISO8601 | null",
      "ats_score": "integer | null"
    }
  ]
}
```

**Response (CSV):** `Content-Type: text/csv`, `Content-Disposition: attachment; filename="churn_report.csv"`

Two sections: `CHURNED EMPLOYERS` then `CHURNED JOB SEEKERS`.

**UI Requirements:**

- Two tabs: **Inactive Employers** / **Inactive Seekers**
- Window selector: `30 / 60 / 90 days` toggle
- Employer table: Name, Email, Registered, Last Post, Total Posts
- Seeker table: Name, Email, Registered, CV Uploaded, ATS Score
- **Export CSV** button — downloads the file
- **Broadcast to this list** shortcut button — pre-fills the broadcast form with the emails

---

### 1.2 Conversion Funnel

**Endpoint:** `GET /api/admin/reports/funnel`

**Purpose:** Shows where job seekers drop off across the four stages: Registered → CV Uploaded → Applied → Hired.

**Query Parameters:** None.

**Response:**

```json
{
  "stages": [
    { "stage": "registered",  "count": 500,  "drop_off_pct": null },
    { "stage": "cv_uploaded", "count": 320,  "drop_off_pct": 36.0 },
    { "stage": "applied",     "count": 180,  "drop_off_pct": 43.75 },
    { "stage": "hired",       "count": 42,   "drop_off_pct": 76.67 }
  ]
}
```

- `drop_off_pct` is `null` for the first stage
- Each count is guaranteed ≤ the previous stage count

**UI Requirements:**

- Horizontal funnel chart (widths proportional to counts)
- Each stage card shows: stage label, count, drop-off % badge (red if > 50%)
- Stage labels: Registered → CV Uploaded → Applied → Hired
- Optional: click a stage to open the churn report pre-filtered to that segment

---

### 1.3 Approval Pipeline

**Endpoint:** `GET /api/admin/reports/pipeline`

**Purpose:** Shows pending employer applications, how long they've been waiting, and an estimated lost revenue figure.

**Query Parameters:**

| Parameter                     | Type  | Default | Description |
|---|---|---|---|
| `daily_revenue_per_employer`  | float | `10`    | Estimated daily revenue per unapproved employer. |

**Response:**

```json
{
  "pending_count": 5,
  "avg_wait_days": 3.2,
  "daily_revenue_per_employer": 10,
  "estimated_lost_revenue": 160.0,
  "employers": [
    {
      "user_id": "string",
      "name": "string",
      "email": "string",
      "submitted_at": "ISO8601",
      "days_waiting": 4
    }
  ]
}
```

**UI Requirements:**

- Summary KPI cards at the top: **Pending**, **Avg Wait Days**, **Est. Lost Revenue**
- Revenue rate input field (editable, re-fetches on change)
- Table of pending employers: Name, Email, Submitted, Days Waiting
- Each row has an **Approve** and **Reject** quick-action button (calls existing employer approval endpoints)
- Highlight rows where `days_waiting > 7` in amber

---

### 1.4 Top Job Categories

**Endpoint:** `GET /api/admin/reports/categories`

**Purpose:** Ranks job categories by application count or post count. Guides sales outreach to high-demand sectors.

**Query Parameters:**

| Parameter | Type    | Default        | Description |
|---|---|---|---|
| `sort_by` | string  | `applications` | `applications` or `posts` |
| `limit`   | integer | `10`           | 1–50 |

**Response:**

```json
{
  "sort_by": "applications",
  "categories": [
    {
      "category": "Technology",
      "post_count": 42,
      "application_count": 310
    }
  ]
}
```

**UI Requirements:**

- Horizontal bar chart: category names on Y-axis, count on X-axis
- Toggle: **Sort by Applications** / **Sort by Posts**
- Limit selector: `5 / 10 / 20 / 50`
- Hovering a bar shows exact counts for both posts and applications

---

### 1.5 Talent Market Report

**Endpoint:** `GET /api/admin/reports/talent`

**Purpose:** Anonymized aggregate of skill demand and ATS score distribution across job seekers. No PII is ever returned.

**Query Parameters:**

| Parameter  | Type    | Default | Description |
|---|---|---|---|
| `limit`    | integer | `20`    | Top N skills to return (max 100) |
| `industry` | string  | —       | Filter by job role/industry label |
| `format`   | string  | —       | `csv` to download |

**Response (success):**

```json
{
  "profile_count": 142,
  "top_skills": [
    { "skill": "PHP", "count": 87 },
    { "skill": "Laravel", "count": 64 }
  ],
  "ats_stats": {
    "average": 71.4,
    "median": 73.0,
    "minimum": 12,
    "maximum": 98
  }
}
```

**Response (insufficient data):** `422`

```json
{ "message": "Insufficient data for anonymized report" }
```

**UI Requirements:**

- Industry filter dropdown (free text + suggestions)
- Skill limit selector
- Top skills section: horizontal bar chart (skill name + count)
- ATS Stats section: four KPI tiles — Average, Median, Min, Max
- Profile count badge: "Based on N profiles"
- **Export CSV** button
- Warning banner if `profile_count < 10`: "Low sample size — interpret with caution"

---

## 2. Operations

### 2.1 Bulk Employer Onboarding

**Endpoint:** `POST /api/admin/onboarding/bulk`

**Purpose:** Uploads a CSV of employer accounts (agencies, universities, etc.), creates `pending` User + Employer records, and dispatches invite emails asynchronously.

**Request:** `multipart/form-data`

| Field  | Type | Required | Description |
|---|---|---|---|
| `file` | file | Yes | CSV file, max 2 MB. MIME: `text/csv` or `text/plain` |

**CSV Format:**

```csv
name,email,company_name,partner_type
Acme HR,hr@acme.com,Acme Corp,agency
State University,careers@uni.edu,State University,university
Big Firm,hr@bigfirm.com,Big Firm Ltd,enterprise
Jane Doe,jane@startup.com,Startup,
```

- `partner_type` is optional. Valid values: `agency`, `university`, `enterprise`
- `name`, `email`, `company_name` are required per row
- Rows missing required fields or with duplicate emails are skipped

**Response:**

```json
{
  "total_rows": 5,
  "created": 4,
  "skipped": 1,
  "skipped_rows": [
    { "email": "existing@example.com", "reason": "email_exists" },
    { "email": "", "reason": "missing_required_fields" }
  ]
}
```

**Skip reasons:**

| Reason | Description |
|---|---|
| `email_exists` | Email already registered in the platform |
| `missing_required_fields` | One of `name`, `email`, or `company_name` is blank |

**UI Requirements:**

- Drag-and-drop CSV upload area with format instructions
- CSV column reference shown inline: `name`, `email`, `company_name`, `partner_type (optional)`
- After upload: summary card showing Created / Skipped / Total
- Expandable **Skipped Rows** table: email + reason
- **Download skipped rows as CSV** button
- Progress indicator during upload (file may take a few seconds)

---

### 2.2 Platform Broadcast

**Endpoint:** `POST /api/admin/broadcast`

**Purpose:** Sends an email and creates an in-app notification for a defined audience. Delivery is queued asynchronously.

**Request Body (JSON):**

```json
{
  "subject": "string (required)",
  "body": "string (required)",
  "audience": "employees | employers | all",
  "user_ids": ["id1", "id2"]
}
```

- `audience` is required when `user_ids` is not provided
- `user_ids` overrides `audience` — targets only those specific users
- `audience=all` excludes admins

**Audience values:**

| Value       | Targets |
|---|---|
| `employees` | All users with the `employee` role |
| `employers` | All users with the `employer` role |
| `all`       | All non-admin users |
| *(user_ids)*| Exactly those user IDs |

**Response:**

```json
{
  "status": "queued",
  "recipient_count": 142
}
```

**UI Requirements:**

- Subject input (single line)
- Body textarea (multi-line, markdown preview optional)
- Audience selector: radio group — Employees / Employers / Everyone / Specific Users
- "Specific Users" option reveals a user ID multi-select or paste area
- Recipient count preview (live, fetched from the pipeline/churn report context if available)
- **Send** button → shows confirmation modal: "You are about to send to N recipients. Continue?"
- After send: success toast with recipient count
- Audit trail link: "View in Audit Log"

---

### 2.3 Manual CV Re-analysis

**Endpoint:** `POST /api/admin/users/{userId}/reanalyze`

**Purpose:** Forces a fresh AI CV analysis for a specific job seeker. Updates `ai_skills`, `ats_score`, `ai_summary`, etc.

**URL Parameters:**

| Parameter | Description |
|---|---|
| `userId`  | The job seeker's user ID |

**Response (success):**

```json
{
  "message": "CV re-analysis triggered successfully.",
  "user_id": "string",
  "analysis_status": "processing | completed | error"
}
```

**Error responses:**

| Status | Body | Condition |
|---|---|---|
| `404` | `{ "message": "User not found" }` | User doesn't exist or is not an `employee` |
| `422` | `{ "message": "No CV file found for this user" }` | User has no CV on file |

**UI Requirements:**

- Available on the job seeker profile detail page as a button: **Re-analyze CV**
- Button disabled if `cv_file_path` is null (show tooltip: "No CV uploaded")
- On click: confirmation prompt — "This will re-run AI analysis and overwrite existing scores."
- Show inline spinner while request is in-flight
- On success: show updated `analysis_status` and `ats_score`
- On error: display the error message inline

---

### 2.4 Audit Log

**Endpoint:** `GET /api/admin/audit-log`

**Purpose:** Read-only, paginated log of all sensitive admin actions. Required for enterprise compliance.

**Query Parameters:**

| Parameter     | Type    | Default | Description |
|---|---|---|---|
| `action_type` | string  | —       | Filter to a specific action |
| `date_from`   | string  | —       | ISO date `YYYY-MM-DD` |
| `date_to`     | string  | —       | ISO date `YYYY-MM-DD` |
| `per_page`    | integer | `20`    | Max `100` |

**Valid `action_type` values:**

| Value | Triggered by |
|---|---|
| `employer_approved` | Admin approves employer application |
| `employer_rejected` | Admin rejects employer application |
| `broadcast_sent` | Admin sends platform broadcast |
| `cv_reanalysis_triggered` | Admin triggers manual CV re-analysis |
| `bulk_employer_onboarded` | Admin uploads employer CSV |

**Response:**

```json
{
  "data": [
    {
      "id": "string",
      "action": "employer_approved",
      "actor_id": "string",
      "actor_name": "string",
      "target_id": "string | null",
      "target_type": "User | Employer | null",
      "metadata": {},
      "created_at": "ISO8601"
    }
  ],
  "current_page": 1,
  "per_page": 20,
  "total": 42,
  "total_pages": 3,
  "next_page": 2,
  "prev_page": null
}
```

**UI Requirements:**

- Filter bar: Action Type dropdown, Date From / Date To date pickers, per-page selector
- Table: Timestamp, Action (coloured badge), Actor, Target, Metadata (expandable)
- Action badge colours: green = approved, red = rejected, blue = broadcast, yellow = reanalysis, purple = bulk
- No create/edit/delete controls — read-only view
- **Export page as CSV** button
- Timestamp shown in local timezone with full ISO string in tooltip

---

## 3. Notifications (All Users)

> These endpoints are available to all authenticated users (`employee`, `employer`, `admin`). Each user only sees their own notifications.

### 3.1 List Notifications

**Endpoint:** `GET /api/notifications`

**Query Parameters:**

| Parameter  | Type    | Default | Description |
|---|---|---|---|
| `per_page` | integer | `15`    | Results per page |

**Response:**

```json
{
  "data": [
    {
      "id": "string",
      "type": "application_status_changed | direct_offer_received | employer_decision | new_application | broadcast",
      "message": "string",
      "read_at": "ISO8601 | null",
      "related_entity_id": "string | null",
      "related_entity_type": "Application | DirectOffer | Employer | null",
      "created_at": "ISO8601"
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 42,
  "total_pages": 3,
  "next_page": 2,
  "prev_page": null
}
```

**Notification types:**

| Type | Who receives it | Trigger |
|---|---|---|
| `application_status_changed` | Employee | Employer updates application status |
| `direct_offer_received` | Employee | Employer sends a direct offer |
| `employer_decision` | Employer | Admin approves or rejects their account |
| `new_application` | Employer | A job seeker applies to their job post |
| `broadcast` | Any non-admin | Admin sends a platform broadcast |

---

### 3.2 Unread Count

**Endpoint:** `GET /api/notifications/unread-count`

**Response:**

```json
{ "unread_count": 3 }
```

---

### 3.3 Mark One as Read

**Endpoint:** `POST /api/notifications/{id}/read`

**Response (success):** The updated notification object (same shape as list item, `read_at` now set).

**Response (not found / not owned):** `404 { "message": "Notification not found." }`

---

### 3.4 Mark All as Read

**Endpoint:** `POST /api/notifications/read-all`

**Response:**

```json
{
  "message": "All notifications marked as read.",
  "updated": 5
}
```

---

### Notifications UI Requirements

- Bell icon in the top navigation bar with unread count badge
- Dropdown panel showing the 5 most recent notifications, newest first
- Each notification item shows: icon (by type), message, relative timestamp ("2 min ago")
- Unread notifications have a highlighted background
- Click a notification → marks it as read and navigates to the related entity if applicable
- **Mark all as read** button at the top of the dropdown
- **View all** link → full notifications page with pagination
- Full page: same list layout with infinite scroll or paginator
- Notification type icons:
  - `application_status_changed` → briefcase icon
  - `direct_offer_received` → envelope icon
  - `employer_decision` → checkmark / X icon
  - `new_application` → person icon
  - `broadcast` → megaphone icon
