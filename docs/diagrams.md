# Platform Diagrams

> All diagrams use [Mermaid](https://mermaid.js.org/). Render in GitHub, Notion, VS Code (Markdown Preview Mermaid Support extension), or [mermaid.live](https://mermaid.live).

---

## 1. Database — Collection Relationships

```mermaid
erDiagram
    users {
        ObjectId _id
        string name
        string email
        string password
        array roles
        boolean is_employer
    }

    job_seeker_profiles {
        ObjectId _id
        string user_id
        string cv_file_path
        integer ats_score
        array ai_skills
        string analysis_status
    }

    employers {
        ObjectId _id
        string user_id
        string status
        string partner_type
    }

    company_profiles {
        ObjectId _id
        string employer_id
        string name
        string industry
        float rating
    }

    job_posts {
        ObjectId _id
        string employer_id
        string company_profile_id
        string title
        string category
        boolean is_active
    }

    applications {
        ObjectId _id
        string user_id
        string job_post_id
        string status
    }

    direct_offers {
        ObjectId _id
        string employer_id
        string job_seeker_id
        string job_post_id
        string status
    }

    notifications {
        ObjectId _id
        string user_id
        string type
        datetime read_at
    }

    audit_logs {
        ObjectId _id
        string action
        string actor_id
        string target_id
    }

    coach_sessions {
        ObjectId _id
        string user_id
        string title
    }

    coach_messages {
        ObjectId _id
        string session_id
        string role
        string content
    }

    users ||--o| job_seeker_profiles : "has profile"
    users ||--o{ employers : "applies as"
    users ||--o| company_profiles : "owns"
    users ||--o{ job_posts : "posts"
    users ||--o{ applications : "submits"
    users ||--o{ notifications : "receives"
    users ||--o{ coach_sessions : "opens"
    users ||--o{ audit_logs : "logged as actor"

    employers ||--o| company_profiles : "linked to"
    job_posts ||--o{ applications : "receives"
    job_posts ||--o{ direct_offers : "referenced by"
    users ||--o{ direct_offers : "receives offer"

    coach_sessions ||--o{ coach_messages : "contains"
```

---

## 2. User Roles & Access Control

```mermaid
flowchart TD
    U[User registers] --> R{Role assigned}

    R -->|default| E[employee / Job Seeker]
    R -->|applies & approved| EM[employer]
    R -->|system-set| A[admin]

    E --> E1[Upload CV]
    E --> E2[Browse & apply to jobs]
    E --> E3[Receive direct offers]
    E --> E4[AI resume coaching]
    E --> E5[View notifications]

    EM --> EM1{Approved by admin?}
    EM1 -->|No — pending| EM2[Limited access]
    EM1 -->|Yes| EM3[Post jobs]
    EM1 -->|Yes| EM4[Search & filter seekers]
    EM1 -->|Yes| EM5[Send direct offers]
    EM1 -->|Yes| EM6[Review applications]
    EM1 -->|Yes| EM7[AI candidate matching]

    A --> A1[Approve / reject employers]
    A --> A2[View BI reports]
    A --> A3[Send broadcasts]
    A --> A4[Bulk onboard employers]
    A --> A5[Trigger CV re-analysis]
    A --> A6[View audit log]

    style E fill:#3b82f6,color:#fff
    style EM fill:#10b981,color:#fff
    style A fill:#f59e0b,color:#fff
    style EM2 fill:#ef4444,color:#fff
```

---

## 3. CV Upload & AI Analysis Flow

```mermaid
sequenceDiagram
    actor Seeker
    participant API as Laravel API
    participant Cloud as Cloudinary
    participant AI as AI Analysis Service
    participant DB as MongoDB

    Seeker->>API: POST /job-seeker/resume/upload-and-analyze
    API->>Cloud: Upload CV file
    Cloud-->>API: Return secure_url + public_id
    API->>DB: Save cv_file_path, analysis_status = processing
    API->>AI: POST {file_url, user_id}

    alt Analysis succeeds
        AI-->>API: Return ai_skills, ats_score, summary, etc.
        API->>DB: Update profile with AI fields, status = completed
        API-->>Seeker: 200 OK — analysis complete
    else Analysis fails
        AI-->>API: Error response
        API->>DB: Set analysis_status = error, analysis_error = reason
        API-->>Seeker: 422 — analysis failed with reason
    end
```

---

## 4. Employer Approval & Notification Flow

```mermaid
sequenceDiagram
    actor Employer
    actor Admin
    participant API as Laravel API
    participant DB as MongoDB
    participant Observer as NotificationObserver
    participant Queue as Laravel Queue

    Employer->>API: POST /employer/apply (with document)
    API->>DB: Create Employer {status: pending}
    API-->>Employer: 201 — application submitted

    Admin->>API: POST /admin/employers/{id}/approve
    API->>DB: Update Employer {status: approved}
    DB-->>Observer: Employer.updated event fires

    Observer->>DB: Create Notification {type: employer_decision}
    Observer->>Queue: Dispatch EmployerApprovalDecision email

    API->>DB: Update User {roles: [employer], is_employer: true}
    API->>DB: AuditLogService::log(employer_approved)
    API-->>Admin: 200 — approved
    Queue-->>Employer: Email: "Your account has been approved"
```

---

## 5. Admin Broadcast Flow

```mermaid
sequenceDiagram
    actor Admin
    participant API as Laravel API
    participant BroadcastService
    participant Queue as Laravel Queue
    participant DB as MongoDB

    Admin->>API: POST /admin/broadcast {audience, subject, body}
    API->>BroadcastService: resolveRecipients(audience)
    BroadcastService->>DB: Query users by role
    DB-->>BroadcastService: User list (N recipients)
    BroadcastService->>Queue: Dispatch SendBroadcastJob × N
    API->>DB: AuditLogService::log(broadcast_sent)
    API-->>Admin: 200 {status: queued, recipient_count: N}

    loop For each recipient
        Queue->>DB: Create Notification {type: broadcast}
        Queue-->>Recipient: Send email via BroadcastNotification
    end
```

---

## 6. Application Lifecycle

```mermaid
stateDiagram-v2
    [*] --> pending : Seeker applies

    pending --> reviewed : Employer opens application
    pending --> rejected : Employer rejects
    pending --> withdrawn : Seeker withdraws

    reviewed --> shortlisted : Employer shortlists
    reviewed --> rejected : Employer rejects

    shortlisted --> hired : Employer hires
    shortlisted --> rejected : Employer rejects

    hired --> [*]
    rejected --> [*]
    withdrawn --> [*]

    note right of pending : Notification sent to employer\n(new_application)
    note right of reviewed : Notification sent to seeker\n(application_status_changed)
    note right of hired : Notification sent to seeker\n(application_status_changed)
```

---

## 7. Direct Offer Lifecycle

```mermaid
stateDiagram-v2
    [*] --> pending : Employer sends offer

    pending --> accepted : Seeker accepts
    pending --> declined : Seeker declines

    accepted --> [*] : Application auto-created
    declined --> [*]

    note right of pending : Notification sent to seeker\n(direct_offer_received)
    note right of accepted : Application created with\nstatus = pending
```

---

## 8. Admin BI Report Dependencies

```mermaid
flowchart LR
    subgraph Collections
        U[users]
        JSP[job_seeker_profiles]
        E[employers]
        JP[job_posts]
        APP[applications]
    end

    subgraph Reports
        CH[Churn Report]
        FN[Conversion Funnel]
        PL[Pipeline Report]
        CAT[Top Categories]
        TAL[Talent Report]
    end

    U --> CH
    JP --> CH
    JSP --> CH
    APP --> CH

    U --> FN
    JSP --> FN
    APP --> FN

    E --> PL
    U --> PL

    JP --> CAT
    APP --> CAT

    JSP --> TAL

    style CH fill:#3b82f6,color:#fff
    style FN fill:#3b82f6,color:#fff
    style PL fill:#3b82f6,color:#fff
    style CAT fill:#3b82f6,color:#fff
    style TAL fill:#3b82f6,color:#fff
```

---

## 9. API Layer Architecture

```mermaid
flowchart TD
    Client([Client / Frontend])

    Client -->|JWT Bearer Token| MW[jwt.auth Middleware]
    MW --> RC[role:admin / role:employer / role:employee]

    RC --> subgraph1

    subgraph subgraph1[Controllers]
        direction TB
        AC[Auth]
        JSC[JobSeeker]
        EMC[Employer]
        ADM[Admin]
        NOT[Notifications]
    end

    subgraph1 --> subgraph2

    subgraph subgraph2[Services]
        direction TB
        CVA[CvAnalysisService]
        CHN[ChurnReportService]
        FNL[ConversionFunnelService]
        TAL[TalentReportService]
        BOB[BulkOnboardingService]
        BRS[BroadcastService]
        ALS[AuditLogService]
    end

    subgraph2 --> subgraph3

    subgraph subgraph3[External / Async]
        direction TB
        CLD[Cloudinary]
        AIS[AI Analysis API]
        QUE[Laravel Queue]
        MAL[Mail]
    end

    subgraph2 --> DB[(MongoDB)]
    QUE --> DB
    QUE --> MAL
```

---

## Rendering Options

| Tool | How |
|---|---|
| **GitHub** | Paste into any `.md` file — renders automatically |
| **mermaid.live** | Paste diagram code at [mermaid.live](https://mermaid.live) |
| **VS Code** | Install "Markdown Preview Mermaid Support" extension |
| **Notion** | Use a `/code` block, set language to `mermaid` |
| **Obsidian** | Renders natively in preview mode |
| **Slides (Reveal.js / Slidev)** | [Slidev](https://sli.dev) supports Mermaid natively — great for presentations |
| **Export to PNG/SVG** | Use mermaid.live → Export → PNG or SVG |

For a proper presentation I'd recommend **Slidev** — it's a markdown-based slide tool that renders Mermaid diagrams inline, looks great, and exports to PDF.
