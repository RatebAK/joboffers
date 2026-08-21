# AI Job Matching Integration

This document describes the integration with the external AI job matching service that matches candidates to job descriptions.

## Overview

Employers can use AI to find the best-matched candidates for their jobs based on skills, requirements, and job descriptions. The AI extracts requirements and ranks candidates by skill matching scores.

## API Endpoint

**URL:** `https://recruitment-ai-api-c8vt.onrender.com/match-job-to-candidates`  
**Method:** `POST`  
**Content-Type:** `application/x-www-form-urlencoded`

### Request

```bash
curl -X POST \
  'https://recruitment-ai-api-c8vt.onrender.com/match-job-to-candidates' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'job_description=programming&limit=5'
```

**Parameters:**
- `job_description` (string, required) - The job description or requirements to match against
- `limit` (integer, optional) - Maximum number of candidates to return (default: 10)

### Response Structure

```json
{
  "status": "success",
  "extracted_requirements": ["programming"],
  "candidates": [
    {
      "resume_id": "user_Id_test_99",
      "full_name": "Amer Mahfudh",
      "matched_skills_score": 0,
      "skills": [
        "Data Science",
        "Machine Learning",
        "Python",
        "TypeScript"
      ]
    },
    {
      "resume_id": "1",
      "full_name": "Sarieh Al Tabaa",
      "matched_skills_score": 0,
      "skills": [
        "React",
        "Laravel",
        "Node.js",
        "Docker"
      ]
    }
  ]
}
```

## Laravel Integration

### Configuration

Set the API URL in your `.env` file:

```env
JOB_MATCHING_API_URL=https://recruitment-ai-api-c8vt.onrender.com/match-job-to-candidates
```

The URL is configured in `config/services.php`:

```php
'job_matching' => [
    'url' => env('JOB_MATCHING_API_URL'),
],
```

### Service Class

`app/Services/JobMatchingService.php` handles communication with the AI API:

```php
$service = new JobMatchingService();
$result = $service->matchJobToCandidates($jobDescription, $limit);
```

**Parameters:**
- `$jobDescription` - The job description text to match against
- `$limit` - Maximum number of candidates to return (default: 10)

**Returns:** Array with `extracted_requirements` and `candidates`

**Exceptions:**
- `CvAnalysisException` with code `422` - Invalid job description or matching failed
- `CvAnalysisException` with code `502` - Service unavailable

### Controller Endpoints

#### 1. Match Candidates by Job Description

**Endpoint:** `POST /api/employer/match-candidates`  
**Auth:** Employer role required

**Request:**
```json
{
  "job_description": "Senior React developer with 5+ years experience",
  "limit": 10
}
```

**Response:**
```json
{
  "extracted_requirements": ["React", "5+ years", "Senior level"],
  "candidates": [
    {
      "user_id": "664f1a2b3c4d5e6f7a8b9c0d",
      "resume_id": "664f1a2b3c4d5e6f7a8b9c0d",
      "full_name": "Jane Smith",
      "matched_skills_score": 85,
      "skills": ["React", "TypeScript", "Node.js"],
      "profile_url": "/api/employer/job-seeker/664f1a2b3c4d5e6f7a8b9c0d"
    }
  ]
}
```

**Validation:**
- `job_description` - required, string, max 5000 chars
- `limit` - optional, integer, min: 1, max: 50

#### 2. Match Candidates to Existing Job Post

**Endpoint:** `POST /api/employer/jobs/{jobPostId}/match-candidates`  
**Auth:** Employer role required (must own the job post)

**Request:**
```json
{
  "limit": 15
}
```

**Response:**
```json
{
  "job_post": {
    "id": "664f1a2b3c4d5e6f7a8b9c0d",
    "title": "Senior React Developer"
  },
  "extracted_requirements": ["React", "Senior level"],
  "candidates": [...]
}
```

Uses the job post's `description` field for matching. Falls back to `title` if description is empty.

## Features

### 1. Requirement Extraction

The AI automatically extracts key requirements from job descriptions:

```json
{
  "extracted_requirements": [
    "React",
    "5+ years experience",
    "Senior level",
    "TypeScript"
  ]
}
```

### 2. Skill Matching Scores

Each candidate gets a `matched_skills_score` (0-100) indicating relevance:

```json
{
  "matched_skills_score": 85,
  "skills": ["React", "TypeScript", "Node.js"]
}
```

### 3. Profile URLs

Responses include direct links to view full candidate profiles:

```json
{
  "profile_url": "/api/employer/job-seeker/664f1a2b3c4d5e6f7a8b9c0d"
}
```

### 4. Resume ID Mapping

The AI uses `resume_id` which corresponds to our user's MongoDB `_id`:

- AI's `resume_id` → Our `user_id` in JobSeekerProfile
- Used to enrich responses with profile data and URLs

## Use Cases

### 1. Find Candidates for New Job

Employer writes a job description and gets instant matches:

```bash
curl -X POST /api/employer/match-candidates \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "job_description": "Full-stack developer with React and Node.js experience",
    "limit": 10
  }'
```

### 2. Find Matches for Existing Job Post

Employer already has a job post and wants to find matches:

```bash
curl -X POST /api/employer/jobs/664f1a2b3c4d5e6f7a8b9c0d/match-candidates \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"limit": 20}'
```

### 3. Refine Search

Adjust the job description or limit to get different results:

```json
{
  "job_description": "React developer, 3+ years, remote work experience",
  "limit": 5
}
```

## Error Handling

### Parse Failures (422)

When the AI cannot process the job description:

```json
{
  "message": "Job matching failed",
  "reason": "Invalid job description"
}
```

### Service Unavailable (502)

When the AI service is down:

```json
{
  "message": "Job matching service unavailable"
}
```

### Ownership Check (403)

Trying to match candidates to another employer's job post:

```json
{
  "message": "You do not own this job post"
}
```

### Not Found (404)

Job post doesn't exist:

```json
{
  "message": "Job post not found"
}
```

## Testing

All job matching functionality is thoroughly tested in `tests/Feature/JobMatchingTest.php`:

### Coverage
- ✓ Match by job description
- ✓ Match to existing job post
- ✓ Response structure validation
- ✓ Default and custom limits
- ✓ Validation (description required, limits, length)
- ✓ Error handling (422, 502)
- ✓ Auth guards (employer only)
- ✓ Ownership checks
- ✓ Fallback to job title
- ✓ Service errors

Run tests:

```bash
./vendor/bin/pest tests/Feature/JobMatchingTest.php
```

**18/18 tests passing** with 74 assertions.

## Security Considerations

1. **Employer Only**: Only approved employers can use job matching
2. **Ownership Validation**: Employers can only match to their own job posts
3. **Rate Limiting**: Consider adding rate limits to prevent abuse
4. **Input Validation**: Job descriptions limited to 5000 characters
5. **Result Limiting**: Maximum 50 candidates per request

## Performance

- **Response Time**: Typically 1-3 seconds depending on AI processing
- **Candidate Limit**: Recommend 10-20 for best UX
- **Caching**: Consider caching results for identical job descriptions

## Integration Tips

### 1. Show Loading State

Matching takes a few seconds - show a loading indicator:

```javascript
setLoading(true);
await matchCandidates(jobDescription);
setLoading(false);
```

### 2. Display Extracted Requirements

Show what the AI extracted to give feedback:

```javascript
<Tags>
  {extractedRequirements.map(req => (
    <Tag key={req}>{req}</Tag>
  ))}
</Tags>
```

### 3. Sort by Score

Display candidates ordered by `matched_skills_score`:

```javascript
const sorted = candidates.sort((a, b) => 
  b.matched_skills_score - a.matched_skills_score
);
```

### 4. Highlight Matched Skills

Show which candidate skills match the job:

```javascript
const matchedSkills = candidate.skills.filter(skill =>
  extractedRequirements.includes(skill)
);
```

## Future Enhancements

- WebSocket support for real-time matching
- Match history and saved searches
- Candidate comparison view
- Bulk matching for multiple job posts
- Match score explanations
- Candidate ranking adjustments
- Integration with direct offer sending
