# Job Seeker Profile Sections - Implementation Summary

## Overview

Split the monolithic job seeker profile update endpoint into dedicated endpoints for each section, with separate update and delete operations for array-based sections.

## Problem

The original `PUT /api/job-seeker/profile` endpoint handled all profile sections in one massive request:
- Personal Information
- Career Information  
- Social Links
- Skills (array)
- Education History (array)
- Work Experience (array)

This made it difficult to:
- Update individual sections without sending all data
- Delete specific sections
- Maintain clean frontend code
- Properly validate each section independently

## Solution

Created dedicated endpoints for each profile section with proper CRUD operations.

---

## New Endpoints

### Personal Information
- **PUT** `/api/job-seeker/profile/personal-info`
- Updates: first_name, last_name, full_name, image, gender, nationality, city, location, phone, date_of_birth, marital_status

### Career Information
- **PUT** `/api/job-seeker/profile/career-info`
- Updates: salary ranges, job status, years of experience, education level, job level, job types, job roles, work cities, current job title, experience summary, expected salary, actively seeking status

### Social Links
- **PUT** `/api/job-seeker/profile/social-links`
- Updates: LinkedIn, GitHub, Portfolio, Twitter URLs

### Skills
- **PUT** `/api/job-seeker/profile/skills` - Replace all skills
- **DELETE** `/api/job-seeker/profile/skills` - Remove all skills

### Education History
- **PUT** `/api/job-seeker/profile/education` - Replace all education entries
- **DELETE** `/api/job-seeker/profile/education` - Remove all education entries

### Work Experience
- **PUT** `/api/job-seeker/profile/work-experience` - Replace all work experience entries
- **DELETE** `/api/job-seeker/profile/work-experience` - Remove all work experience entries

### Legacy Endpoint
- **PUT** `/api/job-seeker/profile` - Kept for backwards compatibility (deprecated)

---

## API Examples

### Update Personal Info
```bash
curl -X PUT /api/job-seeker/profile/personal-info \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "full_name": "Jane Doe",
    "phone": "+961 70 123456",
    "city": "Beirut",
    "location": "Beirut, Lebanon"
  }'
```

**Response:**
```json
{
  "message": "Personal information updated successfully",
  "profile": {
    "id": "...",
    "full_name": "Jane Doe",
    "phone": "+961 70 123456",
    "city": "Beirut"
  }
}
```

### Update Skills
```bash
curl -X PUT /api/job-seeker/profile/skills \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "skills": [
      { "name": "React", "level": "advanced" },
      { "name": "TypeScript", "level": "intermediate" },
      { "name": "Node.js", "level": "beginner" }
    ]
  }'
```

### Delete Skills
```bash
curl -X DELETE /api/job-seeker/profile/skills \
  -H "Authorization: Bearer TOKEN"
```

**Response:**
```json
{
  "message": "Skills deleted successfully"
}
```

### Update Work Experience
```bash
curl -X PUT /api/job-seeker/profile/work-experience \
  -H "Authorization: Bearer TOKEN" \
  -d '{
    "work_experience": [
      {
        "job_title": "Senior Frontend Developer",
        "company_name": "Acme Corp",
        "job_roles": ["React", "TypeScript"],
        "from_date": "2020-01",
        "to_date": "2023-06",
        "is_currently_working": false,
        "description": "Led frontend development team"
      },
      {
        "job_title": "Frontend Developer",
        "company_name": "Tech Startup",
        "from_date": "2023-07",
        "is_currently_working": true
      }
    ]
  }'
```

---

## Validation Rules

### Personal Information
- `phone`: max 20 characters
- `gender`: enum (male, female, other, prefer_not_to_say)
- `marital_status`: enum (single, married, divorced, widowed, prefer_not_to_say)
- `image`: valid URL

### Career Information
- `years_of_experience`: integer, 0-60
- `job_level`: enum (entry, junior, mid, senior, lead, manager, director, executive)
- `current_job_status`: enum (employed, unemployed, freelancing, student, open_to_work)
- `job_types`, `job_roles`, `work_cities`: arrays of strings

### Social Links
- All fields: valid URLs
- Required: `social_links` object (can be empty)

### Skills
- Required: `skills` array
- Each skill: `name` (required, max 50 chars), `level` (enum: beginner, intermediate, advanced, expert)

### Education History
- Required: `education_history` array
- Fields: certificate_type, university, faculty, major, grade, from_date, awarded_date

### Work Experience
- Required: `work_experience` array
- Fields: job_title, company_name, job_roles (array), from_date, to_date, is_currently_working, description

---

## Testing

Created comprehensive test suite with 22 tests covering:

✅ **Update Operations**
- Personal information updates
- Career information updates
- Social links updates
- Skills updates
- Education history updates
- Work experience updates

✅ **Delete Operations**
- Delete all skills
- Delete all education history
- Delete all work experience

✅ **Validation**
- Phone length validation
- Gender enum validation
- Job level enum validation
- Years of experience range validation
- URL format validation
- Required field validation
- Array requirement validation

✅ **Integration**
- Multiple sections can be updated independently
- All sections are preserved when updating one
- Unauthorized access denied
- Non-employee role access denied

**Test Results:**
```
Tests:    22 passed (69 assertions)
Duration: 1.00s
```

---

## Files Modified

### Controller
- `app/Http/Controllers/API/JobSeekerController.php`
  - Added: `updatePersonalInfo()`
  - Added: `updateCareerInfo()`
  - Added: `updateSocialLinks()`
  - Added: `updateSkills()` / `deleteSkills()`
  - Added: `updateEducation()` / `deleteEducation()`
  - Added: `updateWorkExperience()` / `deleteWorkExperience()`
  - Kept: `update()` (marked as deprecated)

### Routes
- `routes/api.php`
  - Added 9 new routes under `/api/job-seeker/profile/`
  - Kept legacy route for backwards compatibility

### Tests
- `tests/Feature/JobSeekerProfileSectionsTest.php` (NEW)
  - 22 comprehensive tests

---

## Benefits

### For Frontend Developers

**Before:**
```javascript
// Had to send ALL profile data every time
await api.put('/profile', {
  first_name: 'Jane',
  last_name: 'Doe',
  full_name: 'Jane Doe',
  // ... 50+ fields even if only updating phone
  phone: '+961 70 123456',
  skills: [...],
  education_history: [...],
  work_experience: [...]
});
```

**After:**
```javascript
// Update only what you need
await api.put('/profile/personal-info', {
  phone: '+961 70 123456'
});

// Or update skills separately
await api.put('/profile/skills', {
  skills: [
    { name: 'React', level: 'advanced' }
  ]
});

// Delete skills when needed
await api.delete('/profile/skills');
```

### For API Consumers

1. **Cleaner Requests** - Send only relevant data
2. **Better Performance** - Smaller payloads
3. **Easier Validation** - Section-specific error messages
4. **Explicit Deletes** - DELETE endpoints instead of sending empty arrays
5. **Better Documentation** - Each endpoint clearly documented
6. **Type Safety** - Easier to type in TypeScript/Frontend frameworks

---

## Migration Path

### For Existing Clients

The legacy endpoint `PUT /api/job-seeker/profile` still works for backwards compatibility:

```javascript
// ✅ Still works
await api.put('/profile', { /* all fields */ });
```

### Recommended Migration

```javascript
// ❌ Old way
await api.put('/profile', {
  full_name: 'Jane Doe',
  phone: '+961 70 123456',
  current_job_title: 'Developer',
  skills: [...]
});

// ✅ New way
await api.put('/profile/personal-info', {
  full_name: 'Jane Doe',
  phone: '+961 70 123456'
});

await api.put('/profile/career-info', {
  current_job_title: 'Developer'
});

await api.put('/profile/skills', {
  skills: [...]
});
```

---

## Security

- All endpoints require JWT authentication
- All endpoints require `employee` role
- Users can only update their own profile
- Input validation on all fields
- URL validation for social links and image URLs

---

## API Documentation

Regenerated Scribe documentation includes all new endpoints with:
- Request examples
- Response examples  
- Validation rules
- Field descriptions

Available at: `public/docs/index.html`

---

## Next Steps (Optional Enhancements)

1. **Partial Array Updates** - Add endpoints to update/delete individual items (e.g., update one skill, delete one work experience entry)
2. **Batch Operations** - Single endpoint to update multiple sections at once (transaction-safe)
3. **Field-Level Permissions** - Control which fields can be updated after CV analysis
4. **Audit Log** - Track profile change history
5. **Validation Profiles** - Different validation rules for different job levels
