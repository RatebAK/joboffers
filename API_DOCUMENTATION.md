# JWT Role-Based Authentication API Documentation

## Overview

This API provides JWT-based authentication with role-based access control for a Laravel job offers application. The system supports three user roles: `admin`, `employer`, and `employee`, with admin users having universal access to all endpoints.

## Base URL

```
http://your-domain.com/api
```

## Authentication

All protected endpoints require a valid JWT token in the Authorization header:

```
Authorization: Bearer <jwt_token>
```

## User Roles

- **admin**: Full system access, can access all endpoints
- **employer**: Can post jobs and manage company data
- **employee**: Can search and apply for jobs

## Authentication Endpoints

### 1. User Registration

Register a new user with an optional role assignment.

**Endpoint:** `POST /auth/register`

**Request Body:**
```json
{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "SecurePass123",
    "password_confirmation": "SecurePass123",
    "role": "employee"
}
```

**Request Fields:**
- `name` (required, string): User's full name
- `email` (required, string, unique): Valid email address
- `password` (required, string, min:8): User password
- `password_confirmation` (required, string): Must match password
- `role` (optional, string): One of `admin`, `employer`, `employee` (defaults to `employee`)

**Success Response (201):**
```json
{
    "message": "User successfully registered",
    "user": {
        "id": "1",
        "name": "John Doe",
        "email": "john@example.com",
        "roles": ["employee"]
    },
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "bearer",
    "expires_in": 3600
}
```

**Error Responses:**
- `422 Unprocessable Entity`: Validation errors
- `409 Conflict`: Email already exists

### 2. User Login

Authenticate user and receive JWT token.

**Endpoint:** `POST /auth/login`

**Request Body:**
```json
{
    "email": "john@example.com",
    "password": "SecurePass123"
}
```

**Success Response (200):**
```json
{
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "bearer",
    "expires_in": 3600,
    "user": {
        "id": "1",
        "name": "John Doe",
        "email": "john@example.com",
        "roles": ["employee"]
    }
}
```

**Error Responses:**
- `401 Unauthorized`: Invalid credentials
- `422 Unprocessable Entity`: Validation errors

### 3. Token Refresh

Generate a new JWT token using the current valid token.

**Endpoint:** `POST /auth/refresh`

**Headers:**
```
Authorization: Bearer <current_jwt_token>
```

**Success Response (200):**
```json
{
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "bearer",
    "expires_in": 3600,
    "user": {
        "id": "1",
        "name": "John Doe",
        "email": "john@example.com",
        "roles": ["employee"]
    }
}
```

**Error Responses:**
- `401 Unauthorized`: Invalid or expired token

### 4. User Logout

Invalidate the current JWT token.

**Endpoint:** `POST /auth/logout`

**Headers:**
```
Authorization: Bearer <jwt_token>
```

**Success Response (200):**
```json
{
    "message": "Successfully logged out"
}
```

**Error Responses:**
- `401 Unauthorized`: Invalid or missing token

### 5. Get User Profile

Retrieve the authenticated user's profile information.

**Endpoint:** `GET /auth/profile`

**Headers:**
```
Authorization: Bearer <jwt_token>
```

**Success Response (200):**
```json
{
    "id": "1",
    "name": "John Doe",
    "email": "john@example.com",
    "roles": ["employee"]
}
```

**Error Responses:**
- `401 Unauthorized`: Invalid or missing token

## Protected Endpoints

### Admin Routes

These endpoints require `admin` role or admin universal access.

**Base Path:** `/admin`

- `GET /admin/employers` - List all employers
- `POST /admin/employers` - Create employer
- `GET /admin/employees` - List all employees
- `DELETE /admin/users/{id}` - Delete user

### Employer Routes

These endpoints require `employer` role (or admin universal access).

**Base Path:** `/employer`

- `GET /employer/status` - Get employer status
- `POST /employer/jobs` - Create job posting
- `GET /employer/jobs` - List employer's jobs
- `PUT /employer/jobs/{id}` - Update job posting

### Employee Routes

These endpoints require `employee` role (or admin universal access).

**Base Path:** `/employee`

- `GET /employee/jobs` - Search available jobs
- `POST /employee/applications` - Apply for job
- `GET /employee/applications` - List user's applications

## Role-Based Access Control

### Access Rules

1. **Admin Universal Access**: Users with `admin` role can access ALL endpoints
2. **Role-Specific Access**: Users can only access endpoints matching their assigned roles
3. **Multi-Role Support**: Users can have multiple roles (e.g., both `employer` and `employee`)

### Middleware Usage

Routes are protected using the `role` middleware:

```php
// Admin only
Route::middleware(['auth:api', 'role:admin'])->group(function () {
    Route::get('/admin/employers', [AdminController::class, 'employers']);
});

// Employer only (admin can also access)
Route::middleware(['auth:api', 'role:employer'])->group(function () {
    Route::get('/employer/status', [EmployerController::class, 'status']);
});

// Employee only (admin can also access)
Route::middleware(['auth:api', 'role:employee'])->group(function () {
    Route::get('/employee/jobs', [JobSeekerController::class, 'jobs']);
});
```

## Error Responses

### HTTP Status Codes

- `200 OK`: Successful request
- `201 Created`: Resource created successfully
- `401 Unauthorized`: Authentication required or failed
- `403 Forbidden`: Insufficient permissions
- `422 Unprocessable Entity`: Validation errors
- `500 Internal Server Error`: Server error

### Error Response Format

**Authentication Errors (401):**
```json
{
    "error": "Unauthorized",
    "message": "Authentication token is required"
}
```

**Authorization Errors (403):**
```json
{
    "error": "Forbidden",
    "message": "Insufficient permissions. Required roles: admin"
}
```

**Validation Errors (422):**
```json
{
    "email": ["The email field is required."],
    "password": ["The password must be at least 8 characters."]
}
```

## Common Error Messages

### Authentication (401)
- "Authentication token is required"
- "Invalid authentication token"
- "Authentication token has expired"
- "Authentication token has been invalidated"
- "Invalid credentials"

### Authorization (403)
- "Insufficient permissions. Required roles: admin"
- "Insufficient permissions. Required roles: employer"
- "Insufficient permissions. Required roles: employee"

## Testing Credentials

For development and testing, use these seeded users:

| Email | Password | Roles | Description |
|-------|----------|-------|-------------|
| admin@example.com | Admin@123 | admin | System administrator |
| employer@example.com | Employer@123 | employer | Company recruiter |
| employee@example.com | Employee@123 | employee | Job seeker |
| multirole@example.com | MultiRole@123 | admin, employer, employee | User with all roles |

## Example Usage

### Complete Authentication Flow

1. **Register a new user:**
```bash
curl -X POST http://your-domain.com/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jane Smith",
    "email": "jane@example.com",
    "password": "SecurePass123",
    "password_confirmation": "SecurePass123",
    "role": "employer"
  }'
```

2. **Login to get token:**
```bash
curl -X POST http://your-domain.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "jane@example.com",
    "password": "SecurePass123"
  }'
```

3. **Access protected endpoint:**
```bash
curl -X GET http://your-domain.com/api/employer/status \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

4. **Refresh token:**
```bash
curl -X POST http://your-domain.com/api/auth/refresh \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

5. **Logout:**
```bash
curl -X POST http://your-domain.com/api/auth/logout \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

## Security Considerations

1. **Token Storage**: Store JWT tokens securely on the client side
2. **HTTPS**: Always use HTTPS in production
3. **Token Expiration**: Tokens expire after 1 hour by default
4. **Token Blacklisting**: Logged out tokens are blacklisted
5. **Password Security**: Passwords are hashed using bcrypt
6. **Role Validation**: All role assignments are validated server-side

## Rate Limiting

Consider implementing rate limiting for authentication endpoints to prevent brute force attacks:

- Login attempts: 5 per minute per IP
- Registration: 3 per minute per IP
- Token refresh: 10 per minute per user

## Changelog

### Version 1.0.0
- Initial JWT authentication implementation
- Role-based access control (admin, employer, employee)
- User registration with role assignment
- Token refresh and blacklisting
- Comprehensive error handling
- Multi-role user support

---

For technical support or questions about this API, please refer to the development team or check the project repository.