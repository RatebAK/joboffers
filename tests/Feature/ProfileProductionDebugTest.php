<?php

use App\Models\User;
use App\Models\JobSeekerProfile;

test('check if user RatebTest11@gmail.com exists in database', function () {
    // Try to find the user
    $user = User::where('email', 'RatebTest11@gmail.com')->first();
    
    if (!$user) {
        dump('❌ User NOT found in database');
        expect($user)->not->toBeNull('User RatebTest11@gmail.com does not exist');
        return;
    }
    
    dump('✅ User found:', [
        'id' => $user->_id,
        'email' => $user->email,
        'name' => $user->name,
        'roles' => $user->roles,
    ]);
    
    // Check if profile exists
    $profile = JobSeekerProfile::where('user_id', $user->_id)->first();
    
    if (!$profile) {
        dump('❌ Profile NOT found for this user');
        expect($profile)->not->toBeNull('Profile does not exist');
        return;
    }
    
    dump('✅ Profile found:', [
        'id' => $profile->_id,
        'first_name' => $profile->first_name,
        'last_name' => $profile->last_name,
        'full_name' => $profile->full_name,
        'city' => $profile->city,
        'address' => $profile->address,
        'phone' => $profile->phone,
        'gender' => $profile->gender,
        'nationality' => $profile->nationality,
        'date_of_birth' => $profile->date_of_birth,
        'marital_status' => $profile->marital_status,
        'location' => $profile->location,
    ]);
    
    expect($user)->not->toBeNull();
    expect($profile)->not->toBeNull();
})->skip('Only run manually to check production data');

test('simulate exact frontend flow with existing user', function () {
    // This test simulates what the frontend is doing
    
    // Step 1: Login
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'RatebTest11@gmail.com',
        'password' => 'PassWord@123',
    ]);
    
    if ($loginResponse->status() !== 200) {
        dump('❌ Login failed:', $loginResponse->json());
        expect($loginResponse->status())->toBe(200, 'Login should succeed');
        return;
    }
    
    $token = $loginResponse->json('access_token');
    dump('✅ Login successful, token:', substr($token, 0, 20) . '...');
    
    // Step 2: Get current profile
    $getResponse = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/job-seeker/profile');
    
    if ($getResponse->status() !== 200) {
        dump('❌ Get profile failed:', $getResponse->json());
        expect($getResponse->status())->toBe(200, 'Get profile should succeed');
        return;
    }
    
    $currentProfile = $getResponse->json('profile');
    dump('✅ Current profile before update:', [
        'first_name' => $currentProfile['first_name'] ?? 'null',
        'last_name' => $currentProfile['last_name'] ?? 'null',
        'full_name' => $currentProfile['full_name'] ?? 'null',
        'city' => $currentProfile['city'] ?? 'null',
    ]);
    
    // Step 3: Update personal info (exact payload from user)
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
    
    $updateResponse = $this->withHeader('Authorization', "Bearer $token")
        ->putJson('/api/job-seeker/profile/personal-info', $updatePayload);
    
    if ($updateResponse->status() !== 200) {
        dump('❌ Update failed:', $updateResponse->json());
        expect($updateResponse->status())->toBe(200, 'Update should succeed');
        return;
    }
    
    dump('✅ Update response:', $updateResponse->json('message'));
    $updatedProfileFromResponse = $updateResponse->json('profile');
    dump('Profile returned in update response:', [
        'first_name' => $updatedProfileFromResponse['first_name'] ?? 'null',
        'last_name' => $updatedProfileFromResponse['last_name'] ?? 'null',
        'full_name' => $updatedProfileFromResponse['full_name'] ?? 'null',
        'city' => $updatedProfileFromResponse['city'] ?? 'null',
    ]);
    
    // Step 4: Get profile again (what frontend does next)
    $getAfterUpdateResponse = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/job-seeker/profile');
    
    if ($getAfterUpdateResponse->status() !== 200) {
        dump('❌ Get after update failed:', $getAfterUpdateResponse->json());
        expect($getAfterUpdateResponse->status())->toBe(200, 'Get after update should succeed');
        return;
    }
    
    $profileAfterUpdate = $getAfterUpdateResponse->json('profile');
    dump('✅ Profile after update:', [
        'first_name' => $profileAfterUpdate['first_name'] ?? 'null',
        'last_name' => $profileAfterUpdate['last_name'] ?? 'null',
        'full_name' => $profileAfterUpdate['full_name'] ?? 'null',
        'city' => $profileAfterUpdate['city'] ?? 'null',
        'address' => $profileAfterUpdate['address'] ?? 'null',
        'phone' => $profileAfterUpdate['phone'] ?? 'null',
    ]);
    
    // Verify the data was actually updated
    expect($profileAfterUpdate['first_name'])->toBe('Tammam');
    expect($profileAfterUpdate['last_name'])->toBe('Mabroukeh');
    expect($profileAfterUpdate['full_name'])->toBe('Tammam Mabroukeh');
    expect($profileAfterUpdate['city'])->toBe('Damascus');
    expect($profileAfterUpdate['address'])->toBe('Barza');
    expect($profileAfterUpdate['phone'])->toBe('+963932444357');
    
})->skip('Only run manually to check production data - requires real user to exist');
