# Requirements Document

## Introduction

This feature introduces three managed lookup collections — Categories, Cities, and Roles — to standardize the data entered into job posts and job seeker profiles. Categories are strictly enforced (a job post must reference a valid category). Cities and Roles are advisory: they seed autocomplete suggestions but do not block freeform input. All three collections are admin-managed via CRUD endpoints and exposed as public read-only lists.

## Glossary

- **Category**: A fixed classification for a job post (e.g., Technology, Healthcare, Engineering). Stored in the `categories` collection.
- **City**: A Syrian governorate or major city used as a location reference. Stored in the `cities` collection.
- **Role**: A common job role descriptor (e.g., Frontend Developer, Accountant). Stored in the `roles` collection.
- **Lookup_Item**: A generic term for a single document in any of the three lookup collections; has at minimum an `_id` and a `name` field.
- **Admin**: An authenticated user with the `admin` role.
- **Employer**: An authenticated, approved user with the `employer` role.
- **Job_Seeker**: An authenticated user with the `employee` role.
- **JobPost**: A document in the `job_posts` collection representing an open position.
- **JobSeekerProfile**: A document in the `job_seeker_profiles` collection representing a job seeker's profile.
- **LookupController**: The controller responsible for admin CRUD and public read operations on a lookup collection.

---

## Requirements

### Requirement 1: Category Management (Admin)

**User Story:** As an admin, I want to create, read, update, and delete job categories, so that I can maintain the canonical list of categories available to employers.

#### Acceptance Criteria

1. WHEN an admin sends a POST request to `/api/admin/categories` with a valid unique `name`, THE Category_Controller SHALL create a new category document and return it with HTTP 201.
2. WHEN an admin sends a POST request to `/api/admin/categories` with a `name` that already exists (case-insensitive), THE Category_Controller SHALL return HTTP 422 with a validation error message.
3. WHEN an admin sends a POST request to `/api/admin/categories` with a missing or empty `name`, THE Category_Controller SHALL return HTTP 422 with a validation error message.
4. WHEN an admin sends a GET request to `/api/admin/categories`, THE Category_Controller SHALL return the full list of categories with HTTP 200.
5. WHEN an admin sends a PUT request to `/api/admin/categories/{id}` with a valid unique `name`, THE Category_Controller SHALL update the category document and return the updated document with HTTP 200.
6. WHEN an admin sends a PUT request to `/api/admin/categories/{id}` with a `name` that already exists on a different category (case-insensitive), THE Category_Controller SHALL return HTTP 422 with a validation error message.
7. WHEN an admin sends a DELETE request to `/api/admin/categories/{id}`, THE Category_Controller SHALL remove the category document and return HTTP 200 with a confirmation message.
8. WHEN an admin sends a request to `/api/admin/categories/{id}` with an `id` that does not exist, THE Category_Controller SHALL return HTTP 404 with an error message.

---

### Requirement 2: Category Public Read

**User Story:** As any client, I want to retrieve the full list of categories without authentication, so that I can populate category selection UI without requiring login.

#### Acceptance Criteria

1. THE Category_Controller SHALL expose a GET endpoint at `/api/categories` that requires no authentication.
2. WHEN a client sends a GET request to `/api/categories`, THE Category_Controller SHALL return HTTP 200 with the complete, unpaginated list of categories.

---

### Requirement 3: Category Validation on Job Posts

**User Story:** As the system, I want to enforce that every job post references a valid category, so that category data remains consistent across the platform.

#### Acceptance Criteria

1. WHEN an employer sends a POST or PUT request to create or update a job post and the provided `category` value does not match any existing category name, THE Job_Post_Controller SHALL return HTTP 422 with a validation error message.
2. WHEN an employer sends a POST or PUT request to create or update a job post and the provided `category` value matches an existing category name, THE Job_Post_Controller SHALL accept the request and persist the `category` field.
3. WHEN an employer sends a POST or PUT request that omits the `category` field entirely, THE Job_Post_Controller SHALL accept the request without requiring a category (category remains optional on a job post).

---

### Requirement 4: City Management (Admin)

**User Story:** As an admin, I want to create, read, update, and delete cities, so that I can maintain the reference list used for location autocomplete.

#### Acceptance Criteria

1. WHEN an admin sends a POST request to `/api/admin/cities` with a valid unique `name`, THE City_Controller SHALL create a new city document and return it with HTTP 201.
2. WHEN an admin sends a POST request to `/api/admin/cities` with a `name` that already exists (case-insensitive), THE City_Controller SHALL return HTTP 422 with a validation error message.
3. WHEN an admin sends a POST request to `/api/admin/cities` with a missing or empty `name`, THE City_Controller SHALL return HTTP 422 with a validation error message.
4. WHEN an admin sends a GET request to `/api/admin/cities`, THE City_Controller SHALL return the full list of cities with HTTP 200.
5. WHEN an admin sends a PUT request to `/api/admin/cities/{id}` with a valid unique `name`, THE City_Controller SHALL update the city document and return the updated document with HTTP 200.
6. WHEN an admin sends a PUT request to `/api/admin/cities/{id}` with a `name` that already exists on a different city (case-insensitive), THE City_Controller SHALL return HTTP 422 with a validation error message.
7. WHEN an admin sends a DELETE request to `/api/admin/cities/{id}`, THE City_Controller SHALL remove the city document and return HTTP 200 with a confirmation message.
8. WHEN an admin sends a request to `/api/admin/cities/{id}` with an `id` that does not exist, THE City_Controller SHALL return HTTP 404 with an error message.

---

### Requirement 5: City Public Read

**User Story:** As any client, I want to retrieve the full list of cities without authentication, so that I can power location autocomplete fields without requiring login.

#### Acceptance Criteria

1. THE City_Controller SHALL expose a GET endpoint at `/api/cities` that requires no authentication.
2. WHEN a client sends a GET request to `/api/cities`, THE City_Controller SHALL return HTTP 200 with the complete, unpaginated list of cities.

---

### Requirement 6: City Usage in Job Posts and Profiles (Freeform Accepted)

**User Story:** As the system, I want city fields on job posts and job seeker profiles to accept any string value, with the cities collection serving only as a suggestion source, so that data entry is never blocked by an incomplete city list.

#### Acceptance Criteria

1. WHEN an employer creates or updates a job post with a `city` value that does not exist in the cities collection, THE Job_Post_Controller SHALL accept the request and persist the `city` field without error.
2. WHEN a job seeker updates their profile with `work_cities` values that do not exist in the cities collection, THE Job_Seeker_Controller SHALL accept the request and persist the `work_cities` field without error.

---

### Requirement 7: Role Management (Admin)

**User Story:** As an admin, I want to create, read, update, and delete job roles, so that I can maintain the reference list used for role autocomplete across job posts and seeker profiles.

#### Acceptance Criteria

1. WHEN an admin sends a POST request to `/api/admin/roles` with a valid unique `name`, THE Role_Controller SHALL create a new role document and return it with HTTP 201.
2. WHEN an admin sends a POST request to `/api/admin/roles` with a `name` that already exists (case-insensitive), THE Role_Controller SHALL return HTTP 422 with a validation error message.
3. WHEN an admin sends a POST request to `/api/admin/roles` with a missing or empty `name`, THE Role_Controller SHALL return HTTP 422 with a validation error message.
4. WHEN an admin sends a GET request to `/api/admin/roles`, THE Role_Controller SHALL return the full list of roles with HTTP 200.
5. WHEN an admin sends a PUT request to `/api/admin/roles/{id}` with a valid unique `name`, THE Role_Controller SHALL update the role document and return the updated document with HTTP 200.
6. WHEN an admin sends a PUT request to `/api/admin/roles/{id}` with a `name` that already exists on a different role (case-insensitive), THE Role_Controller SHALL return HTTP 422 with a validation error message.
7. WHEN an admin sends a DELETE request to `/api/admin/roles/{id}`, THE Role_Controller SHALL remove the role document and return HTTP 200 with a confirmation message.
8. WHEN an admin sends a request to `/api/admin/roles/{id}` with an `id` that does not exist, THE Role_Controller SHALL return HTTP 404 with an error message.

---

### Requirement 8: Role Public Read

**User Story:** As any client, I want to retrieve the full list of roles without authentication, so that I can populate role selection UI without requiring login.

#### Acceptance Criteria

1. THE Role_Controller SHALL expose a GET endpoint at `/api/roles` that requires no authentication.
2. WHEN a client sends a GET request to `/api/roles`, THE Role_Controller SHALL return HTTP 200 with the complete, unpaginated list of roles.

---

### Requirement 9: Role Usage in Job Posts and Profiles (Freeform Accepted)

**User Story:** As the system, I want role fields on job posts and job seeker profiles to accept any string values, with the roles collection serving only as a suggestion source, so that data entry is never blocked by an incomplete roles list.

#### Acceptance Criteria

1. WHEN an employer creates or updates a job post with `roles` values that do not exist in the roles collection, THE Job_Post_Controller SHALL accept the request and persist the `roles` array without error.
2. WHEN a job seeker updates their profile with `job_roles` values that do not exist in the roles collection, THE Job_Seeker_Controller SHALL accept the request and persist the `job_roles` field without error.

---

### Requirement 10: Lookup Collection Seeding

**User Story:** As a developer, I want the cities and roles collections to be pre-populated with a hardcoded seed list on fresh deployment, so that the platform is usable without manual data entry.

#### Acceptance Criteria

1. WHEN the database seeder is executed, THE Seeder SHALL insert the predefined list of Syrian governorates and major cities into the `cities` collection, skipping any that already exist.
2. WHEN the database seeder is executed, THE Seeder SHALL insert the predefined list of common job roles into the `roles` collection, skipping any that already exist.
3. WHEN the database seeder is executed and a city or role name already exists in the collection, THE Seeder SHALL not create a duplicate document.

---

### Requirement 11: Access Control

**User Story:** As the system, I want all mutating lookup endpoints to be restricted to admins, so that only authorized users can modify the canonical lookup data.

#### Acceptance Criteria

1. WHEN an unauthenticated client sends a POST, PUT, or DELETE request to any admin lookup endpoint (`/api/admin/categories`, `/api/admin/cities`, `/api/admin/roles`), THE System SHALL return HTTP 401.
2. WHEN an authenticated non-admin user (employer or employee) sends a POST, PUT, or DELETE request to any admin lookup endpoint, THE System SHALL return HTTP 403.
3. WHEN an unauthenticated client sends a GET request to any public lookup endpoint (`/api/categories`, `/api/cities`, `/api/roles`), THE System SHALL return HTTP 200.
