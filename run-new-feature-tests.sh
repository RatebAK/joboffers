#!/bin/bash

# Test runner for new features
# Run this script to test UserProfileController, AnalyticsController, and MatchedJobsController

echo "========================================="
echo "Running New Feature Tests"
echo "========================================="
echo ""

echo "1. Testing UserProfileController..."
echo "   - 18 tests covering profile viewing, admin lists, pagination, authorization"
./vendor/bin/pest tests/Feature/UserProfileViewTest.php

echo ""
echo "2. Testing AnalyticsController..."
echo "   - 3 tests covering admin, employer, and seeker analytics"
./vendor/bin/pest tests/Feature/AnalyticsTest.php

echo ""
echo "3. Testing MatchedJobsController..."
echo "   - 4 tests covering job matching algorithm, exclusions, and edge cases"
./vendor/bin/pest tests/Feature/MatchedJobsTest.php

echo ""
echo "========================================="
echo "Test Summary"
echo "========================================="
echo "Total: 25 comprehensive tests"
echo ""
echo "To run all tests:"
echo "  composer test"
echo ""
echo "To run specific test file:"
echo "  ./vendor/bin/pest tests/Feature/UserProfileViewTest.php"
echo "  ./vendor/bin/pest tests/Feature/AnalyticsTest.php"
echo "  ./vendor/bin/pest tests/Feature/MatchedJobsTest.php"
