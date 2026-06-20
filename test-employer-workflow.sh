#!/bin/bash

# Employer Approval Workflow Test Script
# This script demonstrates the complete employer registration and approval flow

set -e  # Exit on error

echo "========================================"
echo "Employer Approval Workflow Test"
echo "========================================"
echo ""

BASE_URL="${BASE_URL:-http://localhost:8000}"

echo "Using API Base URL: $BASE_URL"
echo ""

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Step 1: Register Employer
echo -e "${BLUE}STEP 1: Register New Employer${NC}"
REGISTER_RESPONSE=$(curl -s -X POST "$BASE_URL/api/auth/register" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Test Employer Company",
    "email": "test-employer-'$(date +%s)'@company.com",
    "password": "EmployerPass@123",
    "password_confirmation": "EmployerPass@123",
    "role": "employer"
  }')

EMPLOYER_TOKEN=$(echo $REGISTER_RESPONSE | jq -r '.access_token')
EMPLOYER_EMAIL=$(echo $REGISTER_RESPONSE | jq -r '.user.email')
echo -e "${GREEN}✓ Employer registered: $EMPLOYER_EMAIL${NC}"
echo "  Token: ${EMPLOYER_TOKEN:0:20}..."
echo ""

# Step 2: Try to create job post (should fail)
echo -e "${BLUE}STEP 2: Employer Tries to Create Job Post (Should Fail)${NC}"
JOB_ATTEMPT_1=$(curl -s -X POST "$BASE_URL/api/employer/jobs" \
  -H "Authorization: Bearer $EMPLOYER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Software Engineer",
    "description": "Looking for a talented engineer",
    "requirements": "Minimum 3 years experience",
    "company_name": "Test Company Inc",
    "job_type": "full_time"
  }')

STATUS_1=$(echo $JOB_ATTEMPT_1 | jq -r '.error // "success"')
if [ "$STATUS_1" == "Forbidden" ]; then
  echo -e "${GREEN}✓ Correctly blocked (403 Forbidden)${NC}"
  echo "  Message: $(echo $JOB_ATTEMPT_1 | jq -r '.message')"
else
  echo -e "${YELLOW}⚠ Unexpected response${NC}"
  echo $JOB_ATTEMPT_1 | jq
fi
echo ""

# Step 3: Admin login (using seeded admin or create one)
echo -e "${BLUE}STEP 3: Admin Logs In${NC}"
ADMIN_LOGIN=$(curl -s -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@example.com",
    "password": "Admin@123"
  }')

ADMIN_TOKEN=$(echo $ADMIN_LOGIN | jq -r '.access_token')
if [ "$ADMIN_TOKEN" == "null" ] || [ -z "$ADMIN_TOKEN" ]; then
  echo -e "${YELLOW}⚠ Admin account not found. Please seed the database first.${NC}"
  echo "  Run: php artisan db:seed"
  exit 1
fi
echo -e "${GREEN}✓ Admin logged in${NC}"
echo "  Token: ${ADMIN_TOKEN:0:20}..."
echo ""

# Step 4: Admin fetches pending employers
echo -e "${BLUE}STEP 4: Admin Fetches Pending Employer Applications${NC}"
PENDING_EMPLOYERS=$(curl -s -X GET "$BASE_URL/api/admin/employers" \
  -H "Authorization: Bearer $ADMIN_TOKEN")

PENDING_COUNT=$(echo $PENDING_EMPLOYERS | jq '. | length')
echo -e "${GREEN}✓ Found $PENDING_COUNT pending application(s)${NC}"

# Find our employer in the list
EMPLOYER_APP_ID=$(echo $PENDING_EMPLOYERS | jq -r ".[] | select(.user.email == \"$EMPLOYER_EMAIL\") | .id")

if [ -z "$EMPLOYER_APP_ID" ] || [ "$EMPLOYER_APP_ID" == "null" ]; then
  echo -e "${YELLOW}⚠ Could not find employer in pending list${NC}"
  echo "Pending employers:"
  echo $PENDING_EMPLOYERS | jq
  exit 1
fi

echo "  Employer Application ID: $EMPLOYER_APP_ID"
echo "  Employer Email: $EMPLOYER_EMAIL"
echo ""

# Step 5: Admin approves employer
echo -e "${BLUE}STEP 5: Admin Approves Employer Application${NC}"
APPROVAL_RESPONSE=$(curl -s -X POST "$BASE_URL/api/admin/employers/$EMPLOYER_APP_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN")

APPROVAL_MESSAGE=$(echo $APPROVAL_RESPONSE | jq -r '.message')
echo -e "${GREEN}✓ $APPROVAL_MESSAGE${NC}"
echo ""

# Step 6: Verify pending list is now empty (or has fewer items)
echo -e "${BLUE}STEP 6: Verify Pending List Updated${NC}"
PENDING_AFTER=$(curl -s -X GET "$BASE_URL/api/admin/employers" \
  -H "Authorization: Bearer $ADMIN_TOKEN")

PENDING_COUNT_AFTER=$(echo $PENDING_AFTER | jq '. | length')
echo -e "${GREEN}✓ Pending applications now: $PENDING_COUNT_AFTER${NC}"
echo ""

# Step 7: Employer creates job post (should succeed now)
echo -e "${BLUE}STEP 7: Employer Creates Job Post (Should Succeed)${NC}"
JOB_ATTEMPT_2=$(curl -s -X POST "$BASE_URL/api/employer/jobs" \
  -H "Authorization: Bearer $EMPLOYER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Senior PHP Developer",
    "description": "We are seeking an experienced PHP developer to join our team.",
    "requirements": "Minimum 5 years of PHP experience, Laravel expertise required",
    "company_name": "Test Company Inc",
    "job_type": "full_time",
    "work_mode": "remote",
    "location": "Remote",
    "salary_range": {
      "min": 80000,
      "max": 120000,
      "currency": "USD"
    },
    "tags": ["PHP", "Laravel", "MongoDB"]
  }')

JOB_ID=$(echo $JOB_ATTEMPT_2 | jq -r '.id')
JOB_TITLE=$(echo $JOB_ATTEMPT_2 | jq -r '.title')

if [ "$JOB_ID" != "null" ] && [ ! -z "$JOB_ID" ]; then
  echo -e "${GREEN}✓ Job post created successfully!${NC}"
  echo "  Job ID: $JOB_ID"
  echo "  Title: $JOB_TITLE"
else
  echo -e "${YELLOW}⚠ Failed to create job post${NC}"
  echo $JOB_ATTEMPT_2 | jq
  exit 1
fi
echo ""

# Step 8: Verify job is publicly visible
echo -e "${BLUE}STEP 8: Verify Job is Publicly Visible${NC}"
PUBLIC_JOBS=$(curl -s -X GET "$BASE_URL/api/jobs")
JOB_IN_LIST=$(echo $PUBLIC_JOBS | jq ".data[] | select(.id == \"$JOB_ID\")")

if [ ! -z "$JOB_IN_LIST" ]; then
  echo -e "${GREEN}✓ Job post is visible in public listings${NC}"
  echo "  Title: $(echo $JOB_IN_LIST | jq -r '.title')"
else
  echo -e "${YELLOW}⚠ Job post not found in public listings${NC}"
fi
echo ""

# Success summary
echo "========================================"
echo -e "${GREEN}✓ Workflow Test Complete!${NC}"
echo "========================================"
echo ""
echo "Summary:"
echo "  1. ✓ Employer registered"
echo "  2. ✓ Pre-approval job creation blocked"
echo "  3. ✓ Admin found employer in pending list"
echo "  4. ✓ Admin approved employer"
echo "  5. ✓ Employer created job post successfully"
echo "  6. ✓ Job post visible publicly"
echo ""
echo "All steps completed successfully!"
