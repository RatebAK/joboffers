<?php

use App\Models\JobSeekerProfile;
use App\Models\User;
use function Pest\Laravel\{putJson, getJson};

test('debug: verify exact data flow', function () {
    User::truncate();
    JobSeekerProfile::truncate();
    
    $seeker = User::factory()->employee()->create([
        'email' => 'test@example.com',
    ]);
    $token = auth('api')->login($seeker);
    
    echo "\n=== INITIAL STATE ===\n";
    $initialProfile = JobSeekerProfile::where('user_id', (string) $seeker->_id)->first();
    echo "Profile exists: " . ($initialProfile ? 'YES' : 'NO') . "\n";
    
    echo "\n=== UPDATING PERSONAL INFO ===\n";
    $putResponse = $this->withToken($token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Debug Test User',
        'phone' => '+961 70 999999',
    ]);
    
    echo "Status: " . $putResponse->status() . "\n";
    echo "Response: " . json_encode($putResponse->json(), JSON_PRETTY_PRINT) . "\n";
    
    echo "\n=== CHECKING DATABASE ===\n";
    $dbProfile = JobSeekerProfile::where('user_id', (string) $seeker->_id)->first();
    echo "Profile exists: " . ($dbProfile ? 'YES' : 'NO') . "\n";
    if ($dbProfile) {
        echo "Full name in DB: " . ($dbProfile->full_name ?? 'NULL') . "\n";
        echo "Phone in DB: " . ($dbProfile->phone ?? 'NULL') . "\n";
        echo "User ID in DB: " . $dbProfile->user_id . "\n";
    }
    
    echo "\n=== GETTING PROFILE ===\n";
    $getResponse = $this->withToken($token)->getJson('/api/job-seeker/profile');
    echo "Status: " . $getResponse->status() . "\n";
    $profileData = $getResponse->json('profile');
    echo "Full name from GET: " . ($profileData['full_name'] ?? 'NULL') . "\n";
    echo "Phone from GET: " . ($profileData['phone'] ?? 'NULL') . "\n";
    
    // Assertions
    expect($putResponse->status())->toBe(200);
    expect($dbProfile)->not->toBeNull();
    expect($dbProfile->full_name)->toBe('Debug Test User');
    expect($dbProfile->phone)->toBe('+961 70 999999');
    expect($getResponse->status())->toBe(200);
    expect($profileData['full_name'])->toBe('Debug Test User');
    expect($profileData['phone'])->toBe('+961 70 999999');
    
    User::truncate();
    JobSeekerProfile::truncate();
});
