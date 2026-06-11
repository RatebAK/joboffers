<?php

use App\Models\User;
use App\Models\JobSeekerProfile;

/**
 * IMPORTANT: This test connects to the PRODUCTION database
 * 
 * To run this test with production database:
 * DB_DATABASE=joboffers DB_URI="mongodb+srv://sa20sy_db_user:O1tUrvlHB8XUP6tj@cluster0.pjsoi1u.mongodb.net/joboffers?retryWrites=true&w=majority&appName=Cluster0" ./vendor/bin/pest tests/Production/CheckRealUserTest.php
 */

test('check if user RatebTest11@gmail.com exists in production database', function () {
    $dbName = config('database.connections.mongodb.database');
    dump('🔍 Connected to database:', $dbName);
    
    // Try to find the user
    $user = User::where('email', 'RatebTest11@gmail.com')->first();
    
    if (!$user) {
        dump('❌ User NOT found in database');
        dump('Total users in database:', User::count());
        dump('Sample emails:', User::limit(5)->pluck('email')->toArray());
        return;
    }
    
    dump('✅ User found:', [
        'id' => (string)$user->_id,
        'email' => $user->email,
        'name' => $user->name,
        'roles' => $user->roles,
    ]);
    
    // Check if profile exists
    $profile = JobSeekerProfile::where('user_id', $user->_id)->first();
    
    if (!$profile) {
        dump('❌ Profile NOT found for this user');
        dump('Total profiles in database:', JobSeekerProfile::count());
        return;
    }
    
    dump('✅ Profile found:');
    dump([
        'profile_id' => (string)$profile->_id,
        'user_id' => (string)$profile->user_id,
        'first_name' => $profile->first_name ?? 'NULL',
        'last_name' => $profile->last_name ?? 'NULL',
        'full_name' => $profile->full_name ?? 'NULL',
        'city' => $profile->city ?? 'NULL',
        'address' => $profile->address ?? 'NULL',
        'phone' => $profile->phone ?? 'NULL',
        'gender' => $profile->gender ?? 'NULL',
        'nationality' => $profile->nationality ?? 'NULL',
        'date_of_birth' => $profile->date_of_birth ?? 'NULL',
        'marital_status' => $profile->marital_status ?? 'NULL',
        'location' => $profile->location ?? 'NULL',
        'updated_at' => $profile->updated_at?->toDateTimeString() ?? 'NULL',
    ]);
    
    expect($user)->not->toBeNull();
});

test('simulate exact frontend flow with production data', function () {
    $dbName = config('database.connections.mongodb.database');
    dump('🔍 Testing with database:', $dbName);
    
    // Step 1: Login
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'RatebTest11@gmail.com',
        'password' => 'PassWord@123',
    ]);
    
    if ($loginResponse->status() !== 200) {
        dump('❌ Login failed:', $loginResponse->json());
        expect($loginResponse->status())->toBe(200, 'Login should succeed - check if user exists');
        return;
    }
    
    $token = $loginResponse->json('access_token');
    dump('✅ Login successful');
    
    // Step 2: Get current profile BEFORE update
    $getBeforeResponse = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/job-seeker/profile');
    
    if ($getBeforeResponse->status() !== 200) {
        dump('❌ Get profile failed:', $getBeforeResponse->json());
        return;
    }
    
    $profileBefore = $getBeforeResponse->json('profile');
    dump('📋 Current profile BEFORE update:', [
        'id' => $profileBefore['id'] ?? 'null',
        'first_name' => $profileBefore['first_name'] ?? 'NULL',
        'last_name' => $profileBefore['last_name'] ?? 'NULL',
        'full_name' => $profileBefore['full_name'] ?? 'NULL',
        'city' => $profileBefore['city'] ?? 'NULL',
        'address' => $profileBefore['address'] ?? 'NULL',
        'phone' => $profileBefore['phone'] ?? 'NULL',
    ]);
    
    // Step 3: Update personal info
    $updatePayload = [
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
    
    dump('📤 Sending UPDATE request...');
    
    $updateResponse = $this->withHeader('Authorization', "Bearer $token")
        ->putJson('/api/job-seeker/profile/personal-info', $updatePayload);
    
    dump('📥 Update response status:', $updateResponse->status());
    
    if ($updateResponse->status() !== 200) {
        dump('❌ Update failed:', $updateResponse->json());
        return;
    }
    
    dump('✅ Update response message:', $updateResponse->json('message'));
    
    // Step 4: Verify in database DIRECTLY
    $user = User::where('email', 'RatebTest11@gmail.com')->first();
    $profileFromDb = JobSeekerProfile::where('user_id', $user->_id)->first();
    
    dump('💾 Profile from database AFTER update:', [
        'first_name' => $profileFromDb->first_name ?? 'NULL',
        'last_name' => $profileFromDb->last_name ?? 'NULL',
        'full_name' => $profileFromDb->full_name ?? 'NULL',
        'city' => $profileFromDb->city ?? 'NULL',
        'address' => $profileFromDb->address ?? 'NULL',
        'phone' => $profileFromDb->phone ?? 'NULL',
        'updated_at' => $profileFromDb->updated_at?->toDateTimeString() ?? 'NULL',
    ]);
    
    // Step 5: Get profile again via API
    $getAfterResponse = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/job-seeker/profile');
    
    $profileAfter = $getAfterResponse->json('profile');
    dump('📋 Profile from GET API AFTER update:', [
        'first_name' => $profileAfter['first_name'] ?? 'NULL',
        'last_name' => $profileAfter['last_name'] ?? 'NULL',
        'full_name' => $profileAfter['full_name'] ?? 'NULL',
        'city' => $profileAfter['city'] ?? 'NULL',
        'address' => $profileAfter['address'] ?? 'NULL',
        'phone' => $profileAfter['phone'] ?? 'NULL',
    ]);
    
    // Compare
    dump('');
    dump('🔍 COMPARISON:');
    dump('  Database first_name: "' . ($profileFromDb->first_name ?? 'NULL') . '"');
    dump('  API first_name:      "' . ($profileAfter['first_name'] ?? 'NULL') . '"');
    dump('  Database city:       "' . ($profileFromDb->city ?? 'NULL') . '"');
    dump('  API city:            "' . ($profileAfter['city'] ?? 'NULL') . '"');
    
    if ($profileFromDb->first_name === 'Tammam' && ($profileAfter['first_name'] ?? null) !== 'Tammam') {
        dump('');
        dump('⚠️  PROBLEM FOUND: Database has updated data but API returns old data!');
        dump('    This suggests a caching issue or the API is reading from wrong source');
    } elseif ($profileFromDb->first_name !== 'Tammam') {
        dump('');
        dump('⚠️  PROBLEM FOUND: Data was NOT saved to database!');
        dump('    Check the controller logic');
    } else {
        dump('');
        dump('✅ SUCCESS: Both database and API return updated data');
    }
});
