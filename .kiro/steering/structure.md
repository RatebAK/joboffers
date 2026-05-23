# Project Structure

```
app/
  Http/
    Controllers/API/   # All API controllers (one per resource)
    Middleware/        # CheckRole — role-based access control
    Requests/          # Form request classes (validation)
  Models/              # Eloquent/MongoDB models
  Services/            # Business logic (e.g. CvAnalysisService)
  Exceptions/          # Typed exceptions (e.g. CvAnalysisException)
  Notifications/       # Email/notification classes
  Providers/           # AppServiceProvider, AuthServiceProvider

routes/
  api.php              # All API routes, grouped by role middleware

config/
  jwt.php              # JWT config
  services.php         # External service URLs (cv_analysis.url)
  scribe.php           # API doc generation config

tests/
  Feature/             # HTTP-level feature tests (Pest)
  Unit/                # Unit tests for services/helpers

database/
  migrations/          # Schema migrations
  seeders/             # DatabaseSeeder, UserSeeder

.kiro/steering/        # AI steering rules (this folder)
kiro/specs/            # Feature specs (requirements, design, tasks)
public/docs/           # Generated Scribe API documentation
```

## Key Conventions

- All API controllers live in `app/Http/Controllers/API/`
- Routes are grouped by middleware: `jwt.auth` + `role:employee`, `role:employer`, `role:admin`
- Public routes (no auth) are declared at the top of `routes/api.php`
- Models extend `MongoDB\Laravel\Eloquent\Model` (not the standard Eloquent Model)
- `User` extends `MongoDB\Laravel\Auth\User` and implements `JWTSubject`
- AI-derived profile fields are prefixed with `ai_` (e.g. `ai_skills`, `ai_summary`)
- `ats_score` is unprefixed because it is used as a filterable/searchable field
- Services go in `app/Services/`, throw typed exceptions from `app/Exceptions/`
- External HTTP calls use Laravel's `Http` facade inside service classes
- Ownership checks (employer owns job post, etc.) are done in the controller before any mutation
- All API responses are JSON; errors follow `{"message": "..."}` or `{"errors": {...}}` shape
- Pagination responses include: `data`, `current_page`, `per_page`, `total`, `total_pages`, `next_page`, `prev_page`
