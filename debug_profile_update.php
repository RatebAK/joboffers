<?php
/**
 * Production Debug Script for Profile Update Issue
 * 
 * This script connects directly to your production MongoDB to verify
 * if the profile update is actually being saved.
 * 
 * Run this from your production server where MongoDB connection works:
 * php debug_profile_update.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\JobSeekerProfile;

echo "🔍 Production Profile Update Debug Script\n";
echo str_repeat("=", 60) . "\n\n";

$email = 'RatebTest11@gmail.com';

try {
    echo "1️⃣  Connecting to database: " . config('database.connections.mongodb.database') . "\n\n";
    
    // Find the user
    echo "2️⃣  Looking for user: {$email}\n";
    $user = User::where('email', $email)->first();
    
    if (!$user) {
        echo "❌ ERROR: User not found!\n";
        echo "   Total users in database: " . User::count() . "\n";
        exit(1);
    }
    
    echo "✅ User found\n";
    echo "   ID: {$user->_id}\n";
    echo "   Name: {$user->name}\n";
    echo "   Roles: " . json_encode($user->roles) . "\n\n";
    
    // Find the profile
    echo "3️⃣  Looking for profile...\n";
    $profile = JobSeekerProfile::where('user_id', $user->_id)->first();
    
    if (!$profile) {
        echo "❌ ERROR: Profile not found!\n";
        exit(1);
    }
    
    echo "✅ Profile found\n";
    echo "   Profile ID: {$profile->_id}\n\n";
    
    // Show current data
    echo "4️⃣  CURRENT PROFILE DATA:\n";
    echo str_repeat("-", 60) . "\n";
    printf("   %-20s: %s\n", "First Name", $profile->first_name ?? 'NULL');
    printf("   %-20s: %s\n", "Last Name", $profile->last_name ?? 'NULL');
    printf("   %-20s: %s\n", "Full Name", $profile->full_name ?? 'NULL');
    printf("   %-20s: %s\n", "City", $profile->city ?? 'NULL');
    printf("   %-20s: %s\n", "Address", $profile->address ?? 'NULL');
    printf("   %-20s: %s\n", "Phone", $profile->phone ?? 'NULL');
    printf("   %-20s: %s\n", "Gender", $profile->gender ?? 'NULL');
    printf("   %-20s: %s\n", "Nationality", $profile->nationality ?? 'NULL');
    printf("   %-20s: %s\n", "Date of Birth", $profile->date_of_birth ?? 'NULL');
    printf("   %-20s: %s\n", "Marital Status", $profile->marital_status ?? 'NULL');
    printf("   %-20s: %s\n", "Location", $profile->location ?? 'NULL');
    printf("   %-20s: %s\n", "Updated At", $profile->updated_at?->toDateTimeString() ?? 'NULL');
    echo str_repeat("-", 60) . "\n\n";
    
    // Simulate the exact update operation
    echo "5️⃣  Simulating UPDATE operation (same as API endpoint)...\n";
    
    $updateData = [
        'first_name' => 'Tammam',
        'last_name' => 'Mabroukeh',
        'gender' => 'male',
        'nationality' => 'Syrian',
        'city' => 'Damascus',
        'address' => 'Barza',
        'phone' => '+963932444357',
        'date_of_birth' => '2002-06-03',
        'marital_status' => 'single',
        'full_name' => 'Tammam Mabroukeh',
        'location' => 'Damascus',
    ];
    
    echo "   Calling: jobSeekerProfile()->updateOrCreate(...)\n";
    
    $updatedProfile = $user->jobSeekerProfile()->updateOrCreate(
        ['user_id' => $user->_id],
        $updateData
    );
    
    echo "✅ Update operation completed\n";
    echo "   Returned Profile ID: {$updatedProfile->_id}\n\n";
    
    // Verify by reading fresh from database
    echo "6️⃣  Reading fresh from database...\n";
    $freshProfile = JobSeekerProfile::where('user_id', $user->_id)->first();
    
    echo "   AFTER UPDATE:\n";
    echo str_repeat("-", 60) . "\n";
    printf("   %-20s: %s\n", "First Name", $freshProfile->first_name ?? 'NULL');
    printf("   %-20s: %s\n", "Last Name", $freshProfile->last_name ?? 'NULL');
    printf("   %-20s: %s\n", "Full Name", $freshProfile->full_name ?? 'NULL');
    printf("   %-20s: %s\n", "City", $freshProfile->city ?? 'NULL');
    printf("   %-20s: %s\n", "Address", $freshProfile->address ?? 'NULL');
    printf("   %-20s: %s\n", "Phone", $freshProfile->phone ?? 'NULL');
    printf("   %-20s: %s\n", "Gender", $freshProfile->gender ?? 'NULL');
    printf("   %-20s: %s\n", "Nationality", $freshProfile->nationality ?? 'NULL');
    printf("   %-20s: %s\n", "Updated At", $freshProfile->updated_at?->toDateTimeString() ?? 'NULL');
    echo str_repeat("-", 60) . "\n\n";
    
    // Compare
    echo "7️⃣  VERIFICATION:\n";
    echo str_repeat("=", 60) . "\n";
    
    if ($freshProfile->first_name === 'Tammam' && 
        $freshProfile->last_name === 'Mabroukeh' &&
        $freshProfile->city === 'Damascus') {
        echo "✅ SUCCESS! Data was saved correctly to the database.\n";
        echo "\n";
        echo "   This means the Laravel backend is working correctly.\n";
        echo "   If you're seeing old data in your frontend, the issue is:\n";
        echo "   - Frontend caching\n";
        echo "   - Wrong API endpoint being called\n";
        echo "   - Wrong authentication token (different user)\n";
        echo "   - Browser cache\n";
    } else {
        echo "❌ FAILURE! Data was NOT saved to database.\n";
        echo "\n";
        echo "   Expected first_name: Tammam\n";
        echo "   Actual first_name: " . ($freshProfile->first_name ?? 'NULL') . "\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ ERROR:\n";
    echo "   " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Debug script completed\n";
