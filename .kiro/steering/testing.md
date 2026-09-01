---
inclusion: fileMatch
fileMatchPattern: 'tests/**/*.php'
---

# Test Design Patterns

Authoritative rules for generating tests in this project. When writing or editing
any test, follow these. The reference implementation is
`tests/Feature/DirectOfferTest.php`; the full narrative guide is `tests/README.md`.

## Stack & environment

- **Pest v4** on PHPUnit, with `pestphp/pest-plugin-laravel`.
- Tests run against a **real MongoDB** database (`laravel_test` on
  `mongodb://localhost:27017`) — there is no in-memory DB and no migrations.
- Models extend `MongoDB\Laravel\Eloquent\Model`; IDs are Mongo `_id`, referenced
  as `(string) $model->_id`. Use `'000000000000000000000000'` as a non-existent id.
- Feature tests live in `tests/Feature`; pure unit tests in `tests/Unit`.

## Non-negotiable rules

1. **Never write cleanup.** No `$model->delete()`, `Model::truncate()`, or
   `afterEach` teardown. The global `RefreshMongoDatabase` concern (wired in
   `tests/Pest.php`) drops all collections before every Feature test. Cleanup is
   redundant and leaks state when an earlier assertion fails.

2. **Never hand-roll users/tokens.** Use the shared helpers from `tests/Pest.php`:
   - `createUser($role, $attrs = [])` → `User`
   - `tokenFor($role, $attrs = [])` → JWT string
   - `userWithToken($role, $attrs = [])` → `[User, string]`
   - `createCompanyFor($employer, $attrs = [])` → `CompanyProfile`
   - `createJob($employer, $attrs = [])` → active `JobPost`
   Roles are `admin`, `employer`, `employee`. Authenticate with `->withToken($token)`.

3. **Never call external services live.** Fake Cloudinary, the CV analysis API,
   Google, and the filesystem. A test that makes a real network call is broken.
   ```php
   Http::fake([config('services.cv_analysis.url').'/*' => Http::response(['ats_score' => 82])]);
   Storage::fake('public');
   ```

4. **Never leave debug output.** No `dump()`, `dd()`, `echo`, `ray()`, or
   `DO NOT DELETE` banners.

5. **Never assert on hardcoded real users or a production database.**

## Structure of a test file

Order: `use` imports → file-local helpers (only if reused within the file) →
`beforeEach` fixtures → tests grouped by behaviour with `// ──` section comments.

```php
<?php

use App\Models\Application;
use App\Models\DirectOffer;

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
    [$this->seeker, $this->seekerToken]     = userWithToken('employee');
    $this->job = createJob($this->employer);
});

// ── Sending offers ───────────────────────────────────────────────
test('sending an offer requires all fields', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/employer/offers', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['job_seeker_id', 'job_post_id', 'message']);
});
```

- Put common setup in `beforeEach` and hang it off `$this`.
- A helper used by **one** file (e.g. `pendingOffer()`) may sit at the top of that
  file. A helper needed by **two or more** files goes in `tests/Pest.php`.

## Naming

- Test names are lowercase sentences describing **behaviour**, not implementation:
  `test('a seeker cannot accept another seekers offer', ...)`.
- One behaviour per test. If a test needs "and" in its name, split it.
- Files are `PascalCaseTest.php` named after the feature/endpoint under test
  (e.g. `DirectOfferTest`, `JobPostTest`). Do not create `...SimpleTest`,
  `...DebugTest`, `...RealUserTest`, or `Example` variants.

## Assertions

Prefer semantic HTTP helpers and fluent expectations; they read better and give
clearer failures.

| Prefer | Over |
| --- | --- |
| `assertOk()` | `assertStatus(200)` |
| `assertCreated()` | `assertStatus(201)` |
| `assertUnauthorized()` | `assertStatus(401)` |
| `assertForbidden()` | `assertStatus(403)` |
| `assertNotFound()` | `assertStatus(404)` |
| `assertJsonValidationErrors([...])` | `assertJsonStructure(['errors' => [...]])` |
| `assertJsonPath('a.b', $v)` | manual `json()` digging |

Status codes without a dedicated helper (409, 422) use `assertStatus()`. Chain
expectations with `->and()`:
```php
expect($items)->not->toBeEmpty()
    ->and($items[0])->toHaveKeys(['job_seeker_name', 'job_post_title']);
```

## Coverage checklist for an endpoint

When generating tests for an endpoint, cover, as applicable:

- **Happy path** — valid request returns the expected status and body shape.
- **Validation** — missing/invalid fields return `422` with the right error keys.
- **AuthN** — unauthenticated request returns `401`.
- **AuthZ** — wrong role returns `403`; acting on another user's resource returns
  `403` (or `404` when the query is scoped by owner id).
- **Not found** — unknown id returns `404`.
- **Conflict / state guards** — duplicates or acting on a resolved resource return
  `409`.
- **Persistence** — where relevant, assert the change is actually stored.

## Data-driven tests

When one contract applies to several resources, use a `dataset` once instead of
copying a file (see `tests/Feature/EducationLookupTest.php`):

```php
dataset('lookups', [
    'universities' => ['universities', University::class],
    'faculties'    => ['faculties', Faculty::class],
]);

test('admin can create an item', function (string $segment, string $model) {
    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/{$segment}", ['name' => 'X'])
        ->assertCreated();
})->with('lookups');
```

Also use datasets for boundary/enum sweeps (valid enum values, length limits)
rather than near-identical copy-pasted tests.

## Response conventions to assert against

- Errors: `{"message": "..."}` or `{"errors": {"field": [...]}}`.
- Pagination payloads include: `data`, `current_page`, `per_page`, `total`,
  `total_pages`, `next_page`, `prev_page`.
- AI-derived fields are prefixed `ai_`; `ats_score` is unprefixed.

## Anti-patterns (reject these)

- Per-file `xxxAdmin()` / `xxxSeeker()` / `xxxJob()` helper duplication.
- Manual cleanup at the end of tests.
- Order-dependent tests that rely on data created by a previous test.
- Giant tests asserting many unrelated behaviours.
- Real HTTP / storage calls.
- Testing framework or ORM behaviour instead of application behaviour.
