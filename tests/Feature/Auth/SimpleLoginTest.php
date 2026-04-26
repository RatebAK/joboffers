<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('simple login test', function () {
    User::truncate();
    
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('Test@123'),
        'roles' => ['employee']
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'Test@123'
    ]);

    expect($response->status())->toBe(200);
});