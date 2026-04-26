<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Call the UserSeeder to create test users with roles
        $this->call(UserSeeder::class);

        // Optionally create additional random users using factory
        // User::factory(10)->create();
    }
}
