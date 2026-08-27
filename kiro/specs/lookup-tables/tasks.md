# Implementation Plan: Lookup Tables (Categories, Cities, Roles)

## Overview

Implement three MongoDB-backed lookup collections (categories, cities, roles) with shared CRUD behaviour via a single parameterized controller, public read endpoints, admin mutation endpoints, category validation on job posts, freeform acceptance for cities/roles, and idempotent seeders.

---

## Tasks

- [x] 1. Create the three Eloquent models
  - Create `app/Models/Category.php` extending `MongoDB\Laravel\Eloquent\Model`, collection `categories`, fillable `['name']`
  - Create `app/Models/City.php` extending `MongoDB\Laravel\Eloquent\Model`, collection `cities`, fillable `['name']`
  - Create `app/Models/Role.php` extending `MongoDB\Laravel\Eloquent\Model`, collection `roles`, fillable `['name']`
  - _Requirements: 1.1, 4.1, 7.1_

- [x] 2. Implement LookupController with admin CRUD and public listing
  - Create `app/Http/Controllers/API/LookupController.php`
  - Constructor accepts the target model class string (passed from route)
  - `index()` — returns all documents ordered by `name` ASC, HTTP 200
  - `store()` — validates `name` (required, string, max 100, unique in collection, case-insensitive trim), creates document, returns HTTP 201
  - `update()` — finds by id or returns 404, validates `name` (unique ignoring self), updates, returns HTTP 200
  - `destroy()` — finds by id or returns 404, deletes, returns `{"message": "Deleted successfully."}` HTTP 200
  - Use `Validator::make()` inline, consistent with existing controllers
  - _Requirements: 1.1–1.8, 4.1–4.8, 7.1–7.8_

  - [ ]* 2.1 Write property test: P2 — create then retrieve round trip
    - `// Feature: lookup-tables, Property 2: create then retrieve round trip`
    - For each collection: create N random-named items via POST as admin, GET the list, assert all created names are present
    - Covers `CategoryLookupTest`, `CityLookupTest`, `RoleLookupTest`
    - _Requirements: 1.1, 1.4, 4.1, 4.4, 7.1, 7.4_

  - [ ]* 2.2 Write property test: P1 — unique name enforcement
    - `// Feature: lookup-tables, Property 1: unique name enforcement`
    - For each collection: insert an item, attempt a duplicate POST (same name, uppercase variant), assert 422 both times; also test duplicate via PUT on a different item
    - _Requirements: 1.2, 1.6, 4.2, 4.6, 7.2, 7.6_

  - [ ]* 2.3 Write property test: P3 — delete removes from list
    - `// Feature: lookup-tables, Property 3: delete removes from list`
    - For each collection: create an item, DELETE it, GET the list, assert the item's id is absent
    - _Requirements: 1.7, 4.7, 7.7_

  - [ ]* 2.4 Write property test: P4 — update preserves identity, changes name
    - `// Feature: lookup-tables, Property 4: update preserves identity`
    - For each collection: create an item, PUT a new name, assert response `_id` unchanged and `name` updated; GET list, assert new name present
    - _Requirements: 1.5, 4.5, 7.5_

- [x] 3. Register routes in `routes/api.php`
  - Add public routes (no auth): `GET /api/categories`, `GET /api/cities`, `GET /api/roles`
  - Add admin routes (inside existing `jwt.auth + role:admin` group): `GET|POST /api/admin/categories`, `PUT|DELETE /api/admin/categories/{id}`, same pattern for cities and roles
  - Import the three new model classes and `LookupController` at the top of the file
  - _Requirements: 1.4, 2.1, 4.4, 5.1, 7.4, 8.1, 11.1, 11.2, 11.3_

  - [ ]* 3.1 Write property test: P7 — public listing requires no authentication
    - `// Feature: lookup-tables, Property 7: public listing requires no authentication`
    - GET `/api/categories`, `/api/cities`, `/api/roles` with no `Authorization` header → 200 each
    - _Requirements: 2.1, 5.1, 8.1, 11.3_

  - [ ]* 3.2 Write property test: P8 — admin mutation endpoints reject non-admins
    - `// Feature: lookup-tables, Property 8: non-admin requests rejected`
    - POST/PUT/DELETE to each admin lookup endpoint: unauthenticated → 401, employee token → 403, employer token → 403
    - _Requirements: 11.1, 11.2_

- [x] 4. Checkpoint — ensure all tests pass
  - Run `./vendor/bin/pest tests/Feature/CategoryLookupTest.php tests/Feature/CityLookupTest.php tests/Feature/RoleLookupTest.php --run`
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Add category validation to JobPostController
  - In `JobPostController::store()` and `JobPostController::update()`, add `'category' => 'nullable|string|exists:categories,name'` to the validation rules
  - No changes needed to the model — `category` field already exists in `$fillable`
  - _Requirements: 3.1, 3.2, 3.3_

  - [ ]* 5.1 Write property test: P5 — invalid category rejected on job post
    - `// Feature: lookup-tables, Property 5: invalid category rejected`
    - Generate 100 random strings not present in the categories collection, attempt POST to `/api/employer/jobs` with each as `category`, assert all return 422
    - _Requirements: 3.1_

  - [ ]* 5.2 Write property test: P6 — valid category accepted on job post
    - `// Feature: lookup-tables, Property 6: valid category accepted`
    - Create a category, POST a job with that category name, assert 201; also test omitting `category` → 201
    - _Requirements: 3.2, 3.3_

  - [ ]* 5.3 Write property test: P10 — freeform city and role values always accepted on job posts and profiles
    - `// Feature: lookup-tables, Property 10: freeform city and role values accepted`
    - POST/PUT a job post with random strings for `city` and `roles` that are not in the cities/roles collections → assert 201/200
    - PATCH/PUT seeker profile with random `work_cities` and `job_roles` arrays → assert 200
    - _Requirements: 6.1, 6.2, 9.1, 9.2_

- [x] 6. Create City and Role seeders
  - Create `database/seeders/CitySeeder.php` — hardcoded list of Syrian governorates and cities; use `firstOrCreate(['name' => $name])` for each entry
  - Create `database/seeders/RoleSeeder.php` — hardcoded list of common job roles; use `firstOrCreate(['name' => $name])` for each entry
  - Register both seeders in `DatabaseSeeder::run()` after existing seeders
  - _Requirements: 10.1, 10.2, 10.3_

  - [ ]* 6.1 Write property test: P9 — seeder idempotence
    - `// Feature: lookup-tables, Property 9: seeder idempotence`
    - Truncate cities and roles collections, run `CitySeeder` and `RoleSeeder` twice each, assert count equals distinct name count (no duplicates)
    - _Requirements: 10.3_

- [x] 7. Final checkpoint — ensure all tests pass
  - Run `composer test` (full suite)
  - Ensure all tests pass, ask the user if questions arise.

---

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- `LookupController` is instantiated via a closure in the route file that passes the model class, so no IoC binding is needed
- The `exists:categories,name` validation rule works with the MongoDB Eloquent driver out of the box
- Deleting a lookup item does NOT cascade — orphaned `category`/`city`/`role` strings in existing records remain untouched
- All test files should use `afterEach` with `::truncate()` on the relevant collection, matching the pattern in `NotificationListTest.php`
