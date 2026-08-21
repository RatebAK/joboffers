# AI Resume Analysis Integration

This document describes the integration with the external AI resume analysis service.

## Overview

The platform uses an external AI service to analyze uploaded CVs and automatically populate job seeker profile fields. This reduces manual data entry and provides ATS scoring for employer searches.

## API Endpoint

**URL:** `https://recruitment-ai-api-c8vt.onrender.com/analyze-resume`  
**Method:** `POST`  
**Content-Type:** `multipart/form-data`

### Request

```bash
curl -X POST \
  'https://recruitment-ai-api-c8vt.onrender.com/analyze-resume' \
  -H 'Content-Type: multipart/form-data' \
  -F 'resume_id=123' \
  -F 'file=@resume.pdf'
```

**Parameters:**
- `resume_id` (string, required) - Unique identifier for the resume (we use the user's MongoDB `_id`)
- `file` (file, required) - The resume file (PDF, DOC, DOCX)

### Response Structure

```json
{
  "status": "success",
  "resume_id": "123",
  "analysis": {
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "+1234567890",
    "linkedin": "https://linkedin.com/in/johndoe",
    "github": "https://github.com/johndoe",
    "location": "New York, USA",
    "summary": "Experienced software developer...",
    "skills": ["JavaScript", "React", "Node.js"],
    "languages": ["English", "Spanish"],
    "education_history": [
      {
        "institution": "MIT",
        "degree": "BS Computer Science",
        "year": "2018-2022"
      }
    ],
    "work_history": [
      {
        "company": "Tech Corp",
        "role": "Software Engineer",
        "duration": "2022 - Present",
        "description": "Built web applications..."
      }
    ],
    "projects": ["E-commerce Platform", "Mobile App"],
    "ai_overall_evaluation": "Strong technical background...",
    "ats_score": 85
  }
}
```

## Laravel Integration

### Configuration

Set the API URL in your `.env` file:

```env
CV_ANALYSIS_API_URL=https://recruitment-ai-api-c8vt.onrender.com/analyze-resume
```

The URL is configured in `config/services.php`:

```php
'cv_analysis' => [
    'url' => env('CV_ANALYSIS_API_URL'),
],
```

### Service Class

`app/Services/CvAnalysisService.php` handles communication with the AI API:

```php
$service = new CvAnalysisService();
$analysis = $service->analyze($filePath, $userId);
```

**Parameters:**
- `$filePath` - Path to the stored CV file (relative to `storage/app/`)
- `$userId` - User's MongoDB `_id` (used as `resume_id`)

**Returns:** Array of analysis data

**Exceptions:**
- `CvAnalysisException` with code `422` - CV parsing failed
- `CvAnalysisException` with code `502` - Service unavailable

### Controller Endpoint

Job seekers upload and analyze CVs via:

```
POST /api/job-seeker/resume/upload-and-analyze
```

**Request:**
- `cv` (file, required) - PDF/DOC/DOCX file, max 10MB

**Response:**
```json
{
  "profile": {
    "id": "...",
    "ats_score": 85,
    "ai_skills": ["React", "Node.js"],
    "ai_summary": "...",
    "ai_analyzed_at": "2024-01-15T10:30:00Z"
  }
}
```

## Data Mapping

AI response fields are mapped to `JobSeekerProfile` fields:

| AI Field | Profile Field | Type | Notes |
|----------|---------------|------|-------|
| `full_name` | `ai_full_name` | string | |
| `email` | `ai_email` | string | |
| `phone` | `ai_phone` | string | |
| `location` | `ai_location` | string | |
| `summary` | `ai_summary` | string | |
| `skills` | `ai_skills` | array | List of skill names |
| `languages` | `ai_languages` | array | Spoken/written languages |
| `education_history` | `ai_education_history` | array | Structured education data |
| `work_history` | `ai_work_history` | array | Employment history |
| `projects` | `ai_projects` | array | Project names |
| `linkedin` | `ai_social_links.linkedin` | string | Extracted to nested object |
| `github` | `ai_social_links.github` | string | Extracted to nested object |
| `ai_overall_evaluation` | `ai_overall_evaluation` | string | AI's assessment |
| `ats_score` | `ats_score` | integer | **Unprefixed** for searchability |

### Why `ats_score` is Unprefixed

Unlike other AI-derived fields, `ats_score` is stored without the `ai_` prefix because employers actively search and filter by this field. Keeping it unprefixed makes queries cleaner:

```php
// Employer search
$seekers = JobSeekerProfile::where('ats_score', '>=', 80)->get();
```

## Error Handling

### Parse Failures (422)

When the AI cannot parse a CV:

```json
{
  "status": "error",
  "reason": "CV content could not be parsed"
}
```

The service throws `CvAnalysisException` with HTTP code 422, and the controller returns:

```json
{
  "message": "CV analysis failed",
  "reason": "CV content could not be parsed"
}
```

The uploaded file is deleted automatically.

### Service Unavailable (502)

When the AI service is down or unreachable:

```json
{
  "message": "CV analysis service unavailable"
}
```

## Testing

All CV analysis functionality is thoroughly tested in `tests/Feature/CvUploadTest.php`:

- ✓ Successful analysis with full data
- ✓ Minimal response handling
- ✓ Education history mapping
- ✓ Languages mapping
- ✓ Social links extraction
- ✓ Work history mapping
- ✓ Projects mapping
- ✓ Missing optional fields
- ✓ Timestamp storage
- ✓ Parse failure (422)
- ✓ Service unavailable (502)
- ✓ File validation
- ✓ Auth guards

Run tests:

```bash
./vendor/bin/pest tests/Feature/CvUploadTest.php
```

## Usage Example

```php
use App\Services\CvAnalysisService;
use App\Exceptions\CvAnalysisException;

$service = new CvAnalysisService();

try {
    $analysis = $service->analyze('public/resumes/cv.pdf', $user->_id);
    
    $profile->update([
        'ai_skills' => $analysis['skills'] ?? [],
        'ats_score' => $analysis['ats_score'] ?? null,
        'ai_summary' => $analysis['summary'] ?? null,
        // ... other fields
    ]);
} catch (CvAnalysisException $e) {
    if ($e->getHttpStatusCode() === 422) {
        // Handle parse failure
    } else {
        // Handle service unavailable (502)
    }
}
```

## Security Considerations

1. **File Validation**: Only PDF/DOC/DOCX files up to 10MB are accepted
2. **User Isolation**: Each user can only analyze their own CVs
3. **No PII Logging**: The service doesn't log CV contents or analysis results
4. **Cleanup on Failure**: Files are deleted if analysis fails

## Future Enhancements

- Retry logic for transient network failures
- Webhook support for async analysis
- Batch analysis for multiple CVs
- Analysis result caching
- AI confidence scores per field
