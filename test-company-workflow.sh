#!/bin/bash

# Company Profile & Job Post Workflow Test
# Tests: public info, private info, phone visibility, one-company rule, job post fields

BASE_URL="${BASE_URL:-http://localhost:8000}"
GREEN='\033[0;32m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

pass() { echo -e "${GREEN}✓ $1${NC}"; }
fail() { echo -e "${RED}✗ $1${NC}"; echo "$2" | jq 2>/dev/null || echo "$2"; exit 1; }
step() { echo ""; echo -e "${BLUE}--- $1 ---${NC}"; }

echo "========================================"
echo "Company Profile & Job Post Workflow Test"
echo "========================================"

STAMP=$(date +%s)
# Use gmail.com so dns validation passes
EMP_EMAIL="employer.${STAMP}@gmail.com"

# ------------------------------------------------------------------ #
# SETUP: Register as employer (auto-creates pending Employer record)   #
# ------------------------------------------------------------------ #

step "Register employer"
REG=$(curl -s -X POST "$BASE_URL/api/auth/register" \
  -H "Content-Type: application/json" \
  -d "{\"name\":\"Test Employer\",\"email\":\"$EMP_EMAIL\",\"password\":\"Pass@1234\",\"password_confirmation\":\"Pass@1234\",\"role\":\"employer\"}")
EMP_TOKEN=$(echo $REG | jq -r '.access_token')
[ "$EMP_TOKEN" == "null" ] || [ -z "$EMP_TOKEN" ] && fail "Register failed" "$REG"
pass "Registered: $EMP_EMAIL"

step "Admin login"
ADMIN=$(curl -s -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"Admin@123"}')
ADMIN_TOKEN=$(echo $ADMIN | jq -r '.access_token')
[ "$ADMIN_TOKEN" == "null" ] || [ -z "$ADMIN_TOKEN" ] && fail "Admin login failed — run: php artisan db:seed" "$ADMIN"
pass "Admin logged in"

step "Admin finds and approves employer (auto-created on register)"
PENDING=$(curl -s "$BASE_URL/api/admin/employers" \
  -H "Authorization: Bearer $ADMIN_TOKEN")
APP_ID=$(echo $PENDING | jq -r ".[] | select(.user.email == \"$EMP_EMAIL\") | ._id // .id")
[ -z "$APP_ID" ] || [ "$APP_ID" == "null" ] && fail "Employer not in pending list" "$PENDING"
APPROVE=$(curl -s -X POST "$BASE_URL/api/admin/employers/$APP_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN")
echo $APPROVE | jq -r '.message' | grep -qi "approv" || fail "Approval failed" "$APPROVE"
pass "Employer approved (id: $APP_ID)"

step "Employer re-login for fresh token with employer role"
RELOGIN=$(curl -s -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMP_EMAIL\",\"password\":\"Pass@1234\"}")
EMP_TOKEN=$(echo $RELOGIN | jq -r '.access_token')
[ "$EMP_TOKEN" == "null" ] || [ -z "$EMP_TOKEN" ] && fail "Re-login failed" "$RELOGIN"
pass "Token refreshed"

# ------------------------------------------------------------------ #
# One-company rule: job post must fail before company profile exists   #
# ------------------------------------------------------------------ #

step "Job post blocked — no company profile yet"
HTTP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/employer/jobs" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Test","communication_method":"by_forsa","vacancies":1,"job_type":"full_time","city":"Damascus","description":"Test"}')
[ "$HTTP" == "404" ] && pass "Correctly blocked with 404" || fail "Expected 404, got $HTTP"

# ------------------------------------------------------------------ #
# Create company public profile                                        #
# ------------------------------------------------------------------ #

step "Create company profile"
CREATE=$(curl -s -X POST "$BASE_URL/api/employer/company" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Tammam Company",
    "description": "We build software for the Arab world.",
    "industry": "Information Technology Services",
    "company_size": "less_than_10",
    "city": "Damascus",
    "country": "Syria",
    "phone": "0932444357",
    "phone_visible": false,
    "email": "tamammb97@gmail.com"
  }')
COMPANY_ID=$(echo $CREATE | jq -r '._id // .id')
[ "$COMPANY_ID" == "null" ] || [ -z "$COMPANY_ID" ] && fail "Create company failed" "$CREATE"
pass "Company created: $COMPANY_ID"

step "Company name is mutable — update it"
UPD=$(curl -s -X PUT "$BASE_URL/api/employer/company" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Tammam Company Updated"}')
NEW_NAME=$(echo $UPD | jq -r '.name')
[ "$NEW_NAME" == "Tammam Company Updated" ] && pass "Name updated: $NEW_NAME" \
  || fail "Name update failed (got: $NEW_NAME)" "$UPD"

step "Owner view — GET /employer/company includes all fields"
OWNER=$(curl -s "$BASE_URL/api/employer/company" \
  -H "Authorization: Bearer $EMP_TOKEN")
echo $OWNER | jq -e '.email' > /dev/null || fail "email missing from owner view" "$OWNER"
echo $OWNER | jq -e '.phone' > /dev/null || fail "phone missing from owner view" "$OWNER"
pass "Owner view OK (email + phone present)"

step "Public show — phone hidden when phone_visible=false"
PUB=$(curl -s "$BASE_URL/api/companies/$COMPANY_ID")
PHONE=$(echo $PUB | jq -r '.phone // "hidden"')
[ "$PHONE" == "hidden" ] && pass "Phone hidden in public view" \
  || fail "Phone leaked in public view: $PHONE"

step "Set phone_visible=true — phone now shows publicly"
curl -s -X PUT "$BASE_URL/api/employer/company" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"phone_visible": true}' > /dev/null
PUB2=$(curl -s "$BASE_URL/api/companies/$COMPANY_ID")
PHONE2=$(echo $PUB2 | jq -r '.phone // "hidden"')
[ "$PHONE2" == "0932444357" ] && pass "Phone visible: $PHONE2" \
  || fail "Phone not showing (got: $PHONE2)"
# Reset
curl -s -X PUT "$BASE_URL/api/employer/company" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"phone_visible": false}' > /dev/null

# ------------------------------------------------------------------ #
# Private info                                                         #
# ------------------------------------------------------------------ #

step "Update private info"
PRIV=$(curl -s -X PUT "$BASE_URL/api/employer/company/private" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "expose_to_applicants": false,
    "address": "Mazzeh, Damascus, Syria",
    "industries": ["Information Technology Services"],
    "company_size": "less_than_10",
    "founded_year": 2020,
    "phone_main": "0932444357",
    "website": "https://tammam.co",
    "social_media": {
      "linkedin": "https://linkedin.com/company/tammam",
      "instagram": "https://instagram.com/tammam",
      "github": "https://github.com/tammam"
    }
  }')
echo $PRIV | jq -e '.private_info.website' > /dev/null || fail "private_info not saved" "$PRIV"
pass "Private info saved (website: $(echo $PRIV | jq -r '.private_info.website'))"

step "private_info NOT present in public response"
PUB3=$(curl -s "$BASE_URL/api/companies/$COMPANY_ID")
LEAK=$(echo $PUB3 | jq -r '.private_info // "not_present"')
[ "$LEAK" == "not_present" ] && pass "private_info not leaked" \
  || fail "private_info leaked!" "$PUB3"

step "Invalid company_size rejected (422)"
BAD=$(curl -s -o /dev/null -w "%{http_code}" -X PUT "$BASE_URL/api/employer/company" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"company_size":"massive"}')
[ "$BAD" == "422" ] && pass "Invalid company_size → 422" || fail "Expected 422, got $BAD"

# ------------------------------------------------------------------ #
# Job post — full new fields + one-company enforcement                 #
# ------------------------------------------------------------------ #

step "Create job post — company auto-filled from profile"
JOB=$(curl -s -X POST "$BASE_URL/api/employer/jobs" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "communication_method": "by_forsa",
    "title": "Senior Laravel Developer",
    "roles": ["Backend", "PHP"],
    "portfolio_required": false,
    "cover_letter_required": true,
    "gender": "no_preference",
    "education_level": "bachelor",
    "job_level": "senior",
    "experience_years": 4,
    "languages": ["Arabic", "English"],
    "vacancies": 2,
    "job_type": "full_time",
    "work_mode": "on_site",
    "city": "Damascus",
    "address": "Mazzeh Street",
    "salary_from": 500,
    "salary_to": 1000,
    "currency": "USD",
    "display_salary": true,
    "incentives": "Monthly performance bonuses",
    "description": "We are looking for a senior Laravel developer.",
    "requirements": "4+ years Laravel, MongoDB preferred.",
    "questions": [
      {"question": "Describe your last project.", "required": true},
      {"question": "What is your notice period?", "required": false}
    ],
    "tags": ["Laravel", "PHP", "MongoDB"],
    "category": "Engineering"
  }')
JOB_ID=$(echo $JOB | jq -r '._id // .id')
[ "$JOB_ID" == "null" ] || [ -z "$JOB_ID" ] && fail "Job post creation failed" "$JOB"
pass "Job created: $JOB_ID"

AUTO_COMPANY=$(echo $JOB | jq -r '.company_name')
[ "$AUTO_COMPANY" == "Tammam Company Updated" ] \
  && pass "company_name auto-filled: $AUTO_COMPANY" \
  || fail "company_name not auto-filled (got: $AUTO_COMPANY)"

step "Job post visible in public listing (city filter)"
LIST=$(curl -s "$BASE_URL/api/jobs?city=Damascus")
FOUND=$(echo $LIST | jq -r ".data[] | select((._id // .id) == \"$JOB_ID\") | .title")
[ -n "$FOUND" ] && pass "Job in public listing: $FOUND" || fail "Job not in listing" "$LIST"

step "Job detail has questions array (2 items)"
DETAIL=$(curl -s "$BASE_URL/api/jobs/$JOB_ID")
Q=$(echo $DETAIL | jq '.questions | length')
[ "$Q" == "2" ] && pass "Questions saved ($Q)" || fail "Questions missing (got $Q)" "$DETAIL"

step "Company detail includes job in jobs list"
CO=$(curl -s "$BASE_URL/api/companies/$COMPANY_ID")
JOB_TITLE_IN_CO=$(echo $CO | jq -r ".jobs[] | select(.title == \"Senior Laravel Developer\") | .title")
[ -n "$JOB_TITLE_IN_CO" ] && pass "Job in company detail: $JOB_TITLE_IN_CO" \
  || fail "Job not in company detail" "$(echo $CO | jq '.jobs')"

step "Invalid communication_method rejected (422)"
BAD2=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/api/employer/jobs" \
  -H "Authorization: Bearer $EMP_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"communication_method":"by_fax","title":"x","vacancies":1,"job_type":"full_time","city":"x","description":"x"}')
[ "$BAD2" == "422" ] && pass "Invalid communication_method → 422" || fail "Expected 422, got $BAD2"

echo ""
echo "========================================"
echo -e "${GREEN}✓ All tests passed${NC}"
echo "========================================"
