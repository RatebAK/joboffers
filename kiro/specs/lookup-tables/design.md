# Design Document: Lookup Tables (Categories, Cities, Roles)

## Overview

Three new MongoDB collections (`categories`, `cities`, `roles`) provide standardized reference data for job posts and job seeker profiles. Each collection follows the same shape and the same CRUD contract, so a single controller class can be reused with minimal parameterization.

Categories are **strictly validated**: when an employer creates or updates a job post, the `category` field must match a name in the `categories` collection. Cities and Roles are **advisory**: they feed autocomplete but never block freeform input. All three public listing endpoints return the full unpaginated list. All mutating endpoints are admin-only.

---

## Architecture

```
Public clients
    │
    ├── GET /api/categories         ─┐
    ├── GET /api/cities              ├── No auth required
    └── GET /api/roles              ─┘

Admin
    │
    ├── GET|POST   /api/admin/categories          ─┐
    ├── PUT|DELETE /api/admin/categories/{id}      │
    ├── GET|POST   /api/admin/cities               ├── jwt.auth + role:admin
    ├── PUT|DELETE /api/admin/cities/{id}          │
    ├── GET|POST   /api/admin/roles                │
    └── PUT|DELETE /api/admin/roles/{id}          ─┘

Employer
    │
    └── POST|PUT /api/employer/jobs  ── category validated against categories collection
```

Because all three lookup resources share identical CRUD behaviour, a single `LookupController` is parameterized with the model class name. Each resource gets its own named route group pointing to the same controller, keeping the route file clean.

---

## Components and Interfaces

### Models

All three models extend `MongoDB\Laravel\Eloquent\Model` and are minimal:

```php
// App\Models\Category  (collection: categories)
// App\Models\City      (collection: cities)
// App\Models\Role      (collection: roles)

protected $fillable = ['name'];
```

No relationships are defined — orphaned references (after a delete) remain in place per spec.

### LookupController

`App\Http\Controllers\API\LookupController`

A single controller that accepts the target model class as a constructor dependency (resolved via route–model binding pattern at route registration time).

```
index()   → return all documents, ordered by name ASC
store()   → validate name (required, string, unique in collection), create, return 201
update()  → find or 404, validate name (unique ignoring self), update, return 200
destroy() → find or 404, delete, return 200 + message
```

Validation uses inline `Validator::make()` (consistent with existing controllers such as `AdminUserController`).

### Form Validation Rules

| Field  | Store rule                                   | Update rule                                         |
|--------|----------------------------------------------|-----------------------------------------------------|
| `name` | `required\|string\|max:100\|unique:<collection>` | `required\|string\|max:100\|unique:<collection>,name,{id}` |

Uniqueness is case-insensitive at the application layer (names are trimmed and compared lowercase before the unique check, and stored in their original casing).

### Category Validation on Job Posts

The existing `JobPostController` validates the `category` field in its store/update request:

```php
'category' => 'nullable|string|exists:categories,name',
```

`exists` uses MongoDB's Eloquent driver, which supports the `exists` rule against any collection field.

### Seeders

`Database\Seeders\CitySeeder` and `Database\Seeders\RoleSeeder` use `updateOrInsert` / `firstOrCreate` semantics to be idempotent.

`Database\Seeders\DatabaseSeeder` calls both new seeders.

---

## Data Models

### Category document

```json
{
  "_id":        "ObjectId",
  "name":       "Technology",
  "created_at": "ISODate",
  "updated_at": "ISODate"
}
```

### City document

```json
{
  "_id":        "ObjectId",
  "name":       "Damascus",
  "created_at": "ISODate",
  "updated_at": "ISODate"
}
```

### Role document

```json
{
  "_id":        "ObjectId",
  "name":       "Frontend Developer",
  "created_at": "ISODate",
  "updated_at": "ISODate"
}
```

### Seed data

**Categories** (hardcoded, not seeded — admin manages them from scratch):

- Technology
- Healthcare
- Engineering
- Finance
- Education
- Marketing
- Sales
- Legal
- Design
- Operations

These are created by the admin via the API; no seeder is required for categories per the spec.

**Cities** (Syrian governorates and major cities — seeded):

Damascus, Aleppo, Homs, Hama, Latakia, Tartus, Deir ez-Zor, Raqqa, Hasakah, Qamishli, Daraa, As-Suwayda, Quneitra, Idlib, Rif Dimashq, Palmyra, Manbij

**Roles** (common job roles — seeded):

Software Engineer, Frontend Developer, Backend Developer, Full Stack Developer, Mobile Developer, DevOps Engineer, Data Scientist, Data Analyst, Product Manager, Project Manager, UX Designer, UI Designer, Graphic Designer, Marketing Specialist, Sales Representative, Accountant, Financial Analyst, HR Specialist, Customer Support, Operations Manager, Content Writer, Legal Advisor, Teacher, Nurse, Pharmacist

---

## Correctness Properties

A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.

### Property 1: Unique name enforcement across all lookup collections

*For any* lookup collection (categories, cities, roles) and any existing item name, attempting to create a second item with the same name (case-insensitively equal) SHALL result in a 422 response and no new document being inserted.

**Validates: Requirements 1.2, 1.6, 4.2, 4.6, 7.2, 7.6**

---

### Property 2: Admin CRUD round trip — create then retrieve

*For any* lookup collection and any valid name string, creating an item via POST and then listing all items via GET SHALL include a document whose `name` equals the created name.

**Validates: Requirements 1.1, 1.4, 4.1, 4.4, 7.1, 7.4**

---

### Property 3: Delete removes from list

*For any* lookup collection and any existing item, deleting that item via DELETE and then listing all items via GET SHALL return a list that does not contain that item's `_id`.

**Validates: Requirements 1.7, 4.7, 7.7**

---

### Property 4: Update preserves identity, changes name

*For any* lookup collection and any existing item, updating that item's name via PUT SHALL return the same `_id` with the new `name`, and the old name SHALL NOT appear in the subsequent GET listing (unless another item shares it).

**Validates: Requirements 1.5, 4.5, 7.5**

---

### Property 5: Category existence check blocks invalid job post category

*For any* set of existing categories and any `category` value not present in that set, a create/update job post request with that `category` value SHALL be rejected with HTTP 422.

**Validates: Requirements 3.1**

---

### Property 6: Category existence check accepts valid job post category

*For any* existing category name, a create/update job post request with that exact `category` value SHALL be accepted (HTTP 200/201).

**Validates: Requirements 3.2**

---

### Property 7: Public listing requires no authentication

*For any* of the three public endpoints (`/api/categories`, `/api/cities`, `/api/roles`), a GET request with no `Authorization` header SHALL return HTTP 200.

**Validates: Requirements 2.1, 5.1, 8.1, 11.3**

---

### Property 8: Admin mutation endpoints reject non-admins

*For any* admin lookup mutation endpoint and any request from an unauthenticated client, THE System SHALL return HTTP 401. *For any* such endpoint and any request from an authenticated non-admin user, THE System SHALL return HTTP 403.

**Validates: Requirements 11.1, 11.2**

---

### Property 9: Seeder idempotence

*For any* number of seeder executions, the `cities` and `roles` collections SHALL contain no duplicate `name` values after any run.

**Validates: Requirements 10.3**

---

### Property 10: Freeform city and role values are always accepted

*For any* string value for `city` on a job post, or `work_cities` / `job_roles` on a seeker profile, the controller SHALL accept the value regardless of whether it exists in the cities or roles collections.

**Validates: Requirements 6.1, 6.2, 9.1, 9.2**

---

## Error Handling

| Situation | HTTP status | Response shape |
|---|---|---|
| Name missing / empty | 422 | `{"errors": {"name": [...]}}` |
| Name already exists | 422 | `{"errors": {"name": [...]}}` |
| Resource not found | 404 | `{"message": "Not found."}` |
| Unauthenticated | 401 | `{"message": "Unauthenticated."}` |
| Insufficient role | 403 | `{"message": "Forbidden."}` |
| Category does not exist (job post) | 422 | `{"errors": {"category": [...]}}` |

All error responses follow the existing project convention: `{"message": "..."}` for single-field errors, `{"errors": {...}}` for validation bags.

---

## Testing Strategy

### Unit tests (`tests/Unit/`)

Not applicable for this feature — all logic is thin controller/validation work best covered at the HTTP level.

### Feature tests (`tests/Feature/`)

One test file per resource, plus one for job post category validation:

- `tests/Feature/CategoryLookupTest.php`
- `tests/Feature/CityLookupTest.php`
- `tests/Feature/RoleLookupTest.php`
- `tests/Feature/JobPostCategoryValidationTest.php`

Each test file covers:

- Auth / access control (unauthenticated → 401, non-admin → 403, admin → 200/201)
- Public listing returns 200 with no auth
- Create: valid name → 201, duplicate name → 422, empty name → 422
- Update: valid → 200 with updated name, duplicate → 422, missing id → 404
- Delete: existing → 200, missing id → 404
- Response shape assertions

`JobPostCategoryValidationTest.php` covers:

- Creating a job post with a valid category → accepted
- Creating a job post with an invalid category → 422
- Creating a job post with no category → accepted

### Property-based tests

The testing framework is **Pest v4** (already in use). Pest does not ship a built-in property-based library, so tests use **data-driven parametrize** (`->with()`) and randomized helpers inline for the properties below. For true property-based generation, each property test generates 100 randomized inputs using `fake()` and a helper.

**Property test tagging format:** `// Feature: lookup-tables, Property {N}: {title}`

Each correctness property maps to one test:

| Property | Test location | Approach |
|---|---|---|
| P1 – Unique name enforcement | Each `*LookupTest.php` | Insert item, attempt duplicate insert, assert 422 |
| P2 – Create then retrieve round trip | Each `*LookupTest.php` | Create N random items, list, assert all names present |
| P3 – Delete removes from list | Each `*LookupTest.php` | Create, delete, list, assert absent |
| P4 – Update preserves identity | Each `*LookupTest.php` | Create, update name, list, assert new name present, old absent |
| P5 – Invalid category rejected | `JobPostCategoryValidationTest.php` | Random nonexistent category string → 422 |
| P6 – Valid category accepted | `JobPostCategoryValidationTest.php` | Existing category name → 201 |
| P7 – Public listing no auth | Each `*LookupTest.php` | GET without token → 200 |
| P8 – Non-admin rejected | Each `*LookupTest.php` | POST/PUT/DELETE as employee/employer → 401/403 |
| P9 – Seeder idempotence | `tests/Feature/LookupSeederTest.php` | Run seeder twice, count distinct names == total count |
| P10 – Freeform values accepted | `JobPostCategoryValidationTest.php` | Random city/role strings on job post → 201/200 |

Minimum 100 iterations for randomized property tests; use `afterEach` truncation (matching existing test patterns) for collection cleanup.
