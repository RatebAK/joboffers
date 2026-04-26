<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('Admin@123'),
            'roles' => ['admin'],
            'email_verified_at' => now(),
        ]);

        // Create employer user
        User::create([
            'name' => 'Tech Corp HR',
            'email' => 'employer@example.com',
            'password' => Hash::make('Employer@123'),
            'roles' => ['employer'],
            'email_verified_at' => now(),
        ]);

        // Create employee (job seeker) user
        User::create([
            'name' => 'John Doe',
            'email' => 'employee@example.com',
            'password' => Hash::make('Employee@123'),
            'roles' => ['employee'],
            'email_verified_at' => now(),
        ]);

        // Create user with multiple roles for testing
        User::create([
            'name' => 'Multi Role User',
            'email' => 'multirole@example.com',
            'password' => Hash::make('MultiRole@123'),
            'roles' => ['admin', 'employer', 'employee'],
            'email_verified_at' => now(),
        ]);

        // Create additional test users for each role
        User::create([
            'name' => 'Jane Smith',
            'email' => 'jane.employee@example.com',
            'password' => Hash::make('Employee@123'),
            'roles' => ['employee'],
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'StartupCo Recruiter',
            'email' => 'recruiter@startupco.com',
            'password' => Hash::make('Employer@123'),
            'roles' => ['employer'],
            'email_verified_at' => now(),
        ]);

        User::create([
            'name' => 'System Admin',
            'email' => 'sysadmin@example.com',
            'password' => Hash::make('Admin@123'),
            'roles' => ['admin'],
            'email_verified_at' => now(),
        ]);

        // Create employer who is also an employee (dual role)
        User::create([
            'name' => 'Freelancer Pro',
            'email' => 'freelancer@example.com',
            'password' => Hash::make('Freelancer@123'),
            'roles' => ['employer', 'employee'],
            'email_verified_at' => now(),
        ]);

        $this->command->info('Created test users for all roles:');
        $this->command->info('- Admin: admin@example.com (password: Admin@123)');
        $this->command->info('- Employer: employer@example.com (password: Employer@123)');
        $this->command->info('- Employee: employee@example.com (password: Employee@123)');
        $this->command->info('- Multi-role: multirole@example.com (password: MultiRole@123)');
        $this->command->info('- Additional test users created for comprehensive testing');
    }
}