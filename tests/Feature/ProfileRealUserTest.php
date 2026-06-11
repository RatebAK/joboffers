<?php

use App\Models\User;
use App\Models\JobSeekerProfile;

beforeEach(function () {
    User::truncate();
    JobSeekerProfile::truncate();
});

afterEach(function () {
    User::truncate();
    JobSeekerProfile::truncate();
});

test('real user update personal info issue - RatebTest11', function () {
    // Create the exact user from the report
    $user = User::create([
        'name' => 'Rateb Test 11',
        'email' => 'RatebTest11@gmail.com',
        'password' => 'PassWord@123',
        'roles' => ['employee'],
    ]);

    // Create initial profile with some existing data
    $profile = JobSeekerProfile::create([
        'user_id' => $user->_id,
        'first_name' => 'OldFirstName',
        'last_name' => 'OldLastName',
        'full_name' => 'Old Full Name',
        'city' => 'OldCity',
        'skills' => [
            ['name' => 'PHP', 'level' => 'advanced'],
        ],
    ]);

    // Log in as this user
    $token = auth('api')->login($user);

    // The exact payload from the user report
    $payload = [
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

    // Call the exact endpoint
    $response = $this->withHeader('Authorization', "Bearer $token")
        ->putJson('/api/job-seeker/profile/personal-info', $payload);

    // Check response
    $response->assertStatus(200);
    $response->assertJson([
        'message' => 'Personal information updated successfully',
    ]);

    // Verify in database IMMEDIATELY after update
    $profileInDb = JobSeekerProfile::where('user_id', $user->_id)->first();
    
    dump('Profile in DB after update:', $profileInDb->toArray());
    
    expect($profileInDb->first_name)->toBe('Tammam');
    expect($profileInDb->last_name)->toBe('Mabroukeh');
    expect($profileInDb->full_name)->toBe('Tammam Mabroukeh');
    expect($profileInDb->city)->toBe('Damascus');
    expect($profileInDb->address)->toBe('Barza');
    expect($profileInDb->phone)->toBe('+963932444357');
    expect($profileInDb->gender)->toBe('male');
    expect($profileInDb->nationality)->toBe('Syrian');

    // Now call the GET endpoint to see what it returns
    $getResponse = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('/api/job-seeker/profile');

    $getResponse->assertStatus(200);
    
    dump('GET /profile response:', $getResponse->json('profile'));

    // Verify GET returns the updated data
    $getResponse->assertJson([
        'profile' => [
            'first_name' => 'Tammam',
            'last_name' => 'Mabroukeh',
            'full_name' => 'Tammam Mabroukeh',
            'city' => 'Damascus',
            'address' => 'Barza',
            'phone' => '+963932444357',
            'gender' => 'male',
            'nationality' => 'Syrian',
        ],
    ]);

    // Verify that skills were NOT overwritten (merge behavior)
    expect($profileInDb->skills)->toBe([
        ['name' => 'PHP', 'level' => 'advanced'],
    ]);
});

test('debug - check if updateOrCreate is working correctly', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'roles' => ['employee'],
    ]);

    // Create initial profile
    $profile = JobSeekerProfile::create([
        'user_id' => $user->_id,
        'first_name' => 'Initial',
        'city' => 'InitialCity',
        'skills' => [['name' => 'Skill1']],
    ]);

    dump('Initial profile ID:', $profile->_id);
    dump('Initial first_name:', $profile->first_name);

    // Simulate what updatePersonalInfo does
    $updatedProfile = $user->jobSeekerProfile()->updateOrCreate(
        ['user_id' => $user->_id],
        ['first_name' => 'Updated', 'last_name' => 'Name']
    );

    dump('After updateOrCreate ID:', $updatedProfile->_id);
    dump('After updateOrCreate first_name:', $updatedProfile->first_name);
    dump('After updateOrCreate last_name:', $updatedProfile->last_name);
    dump('After updateOrCreate skills:', $updatedProfile->skills);

    // Check what's actually in the database
    $freshFromDb = JobSeekerProfile::where('user_id', $user->_id)->first();
    
    dump('Fresh from DB ID:', $freshFromDb->_id);
    dump('Fresh from DB first_name:', $freshFromDb->first_name);
    dump('Fresh from DB last_name:', $freshFromDb->last_name);
    dump('Fresh from DB skills:', $freshFromDb->skills);

    expect($freshFromDb->first_name)->toBe('Updated');
    expect($freshFromDb->last_name)->toBe('Name');
    expect($freshFromDb->skills)->toBe([['name' => 'Skill1']]);
});
