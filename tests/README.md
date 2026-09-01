# Testing Guide

This project uses [Pest](https://pestphp.com/) on top of PHPUnit, running against
a real MongoDB test database (`laravel_test`). This document describes the
conventions every test should follow. `tests/Feature/DirectOfferTest.php` is the
reference implementation — copy its shape.

## Running the suite

```bash
# All tests (clears config cache first)
composer test

# A single file
./vendor/bin/pest tests/Feature/DirectOfferTest.php

# If you hit "Allowed memory size exhausted" running the whole suite at once,
# raise the limit for that run:
php -d memory_limit=1G ./vendor/pestphp/pest/bin/pest
```

MongoDB must be reachable at `mongodb://localhost:27017` (see `phpunit.xml`).

## Core principles

### 1. No manual cleanup — the database resets itself

Every Feature test starts with an empty database. This is handled globally by the
`RefreshMongoDatabase` concern, wired up in `tests/Pest.php`:

```php
pest()->extend(TestCase::class)
    ->use(RefreshMongoDatabase::class)
    ->beforeEach(fn () => $this->setUpRefreshMongoDatabase())
    ->in('Feature');
```

**Do not** write `$user->delete();`, `Model::truncate();`, or `afterEach` cleanup
at the end of tests. It is redundant and, worse, silently leaks state when an
assertion fails before the cleanup line runs.

```php
// ❌ Old style — don't do this
test('...', function () {
    $employer = User::factory()->employer()->create();
    // ...
    $employer->delete();   // never reached if an assertion above fails
});

// ✅ New style — the reset handles it
test('...', function () {
    [$employer, $token] = userWithToken('employer');
    // ...
});
```

### 2. Use the shared helpers, not per-file factory functions

`tests/Pest.php` exposes helpers available in every test. Prefer them over
redefining local functions like `offerEmployer()` / `catAdmin()` in each file.

| Helper | Returns | Use for |
| --- | --- | --- |
| `createUser($role, $attrs = [])` | `User` | A user of role `admin`/`employer`/`employee` |
| `tokenFor($role, $attrs = [])` | `string` | Just a JWT for a fresh user |
| `userWithToken($role, $attrs = [])` | `[User, string]` | When you need both the user and its token |
| `createCompanyFor($employer, $attrs = [])` | `CompanyProfile` | An employer's company profile |
| `createJob($employer, $attrs = [])` | `JobPost` | An active job post owned by an employer |

Domain builders take an `$attributes` array so tests override only what matters:

```php
$job = createJob($employer, ['title' => 'Senior Dev', 'is_active' => false]);
```

If several files need the same builder, add it to `tests/Pest.php` rather than
copying it. A helper used by only one file (e.g. `pendingOffer()` in
`DirectOfferTest`) may live at the top of that file.

### 3. Build shared fixtures in `beforeEach`

When most tests in a file need the same setup, create it once in `beforeEach` and
hang it off `$this`:

```php
beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
    [$this->seeker, $this->seekerToken]     = userWithToken('employee');
    $this->job = createJob($this->employer);
});
```

### 4. One behaviour per test, described in plain language

Test names read as sentences describing behaviour, not implementation:

```php
test('a seeker cannot accept another seekers offer', function () { ... });
```

### 5. Prefer expressive assertions

Use the semantic HTTP assertions and fluent expectations — they read better and
give clearer failure messages.

```php
// ✅ Prefer
->assertCreated()                       // over ->assertStatus(201)
->assertForbidden()                     // over ->assertStatus(403)
->assertNotFound()                      // over ->assertStatus(404)
->assertJsonValidationErrors(['email']) // over ->assertJsonStructure(['errors' => ['email']])

expect($items)->not->toBeEmpty()
    ->and($items[0])->toHaveKeys(['job_seeker_name', 'job_post_title']);
```

Status codes with no dedicated helper (e.g. 409, 422) still use `assertStatus()`.

## Data-driven tests

When one contract applies to several resources, express it once with a dataset
instead of copying the file. See `tests/Feature/EducationLookupTest.php`, which
runs the same CRUD contract across universities, faculties, and majors:

```php
dataset('lookups', [
    'universities' => ['universities', University::class],
    'faculties'    => ['faculties', Faculty::class],
    'majors'       => ['majors', Major::class],
]);

test('admin can create an item', function (string $segment, string $model) {
    // ...
})->with('lookups');
```

## External services

Tests must not depend on live external services (Cloudinary, the CV analysis AI
API, Google). Fake them so tests are deterministic and offline-friendly:

```php
Illuminate\Support\Facades\Http::fake([
    config('services.cv_analysis.url').'/*' => Http::response(['ats_score' => 82], 200),
]);

Illuminate\Support\Facades\Storage::fake('public');
```

Tests that make real network calls are considered broken.

## What not to commit

- `dump()` / `dd()` / `echo` debugging output.
- Tests that connect to a production database or hardcode real user credentials.
- "Example"/scaffolding stubs.
- `DO NOT DELETE` banner comments — if a test earns its place, its name says so.

## Migrating an old test file

1. Delete the local `xxxAdmin()` / `xxxSeeker()` / `xxxJob()` helper functions;
   replace calls with `userWithToken()` / `createJob()` etc.
2. Move common setup into `beforeEach` on `$this`.
3. Delete every `->delete()` / `truncate()` / `afterEach` cleanup line.
4. Swap `assertStatus(201|403|404)` for `assertCreated()`/`assertForbidden()`/`assertNotFound()`.
5. Run the file: `./vendor/bin/pest tests/Feature/YourTest.php` and confirm green.
```
