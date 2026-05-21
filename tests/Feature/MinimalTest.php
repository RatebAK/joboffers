<?php
use App\Models\User;

test('minimal user creation', function () {
    $user = User::where('email', 'admin@example.com')->first();
    expect($user)->not->toBeNull();
});
 