<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\JobSeekerProfile;
use Illuminate\Console\Command;

class DebugUserProfile extends Command
{
    protected $signature = 'debug:user-profile {email}';
    protected $description = 'Debug a user profile by email';

    public function handle()
    {
        $email = $this->argument('email');
        
        $this->info("🔍 Searching for user: {$email}");
        $this->info("📊 Database: " . config('database.connections.mongodb.database'));
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("❌ User not found!");
            $this->info("Total users in database: " . User::count());
            return 1;
        }
        
        $this->info("✅ User found:");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $user->_id],
                ['Email', $user->email],
                ['Name', $user->name],
                ['Roles', json_encode($user->roles)],
            ]
        );
        
        $profile = JobSeekerProfile::where('user_id', $user->_id)->first();
        
        if (!$profile) {
            $this->error("❌ Profile not found!");
            return 1;
        }
        
        $this->info("\n✅ Profile found:");
        $this->table(
            ['Field', 'Value'],
            [
                ['Profile ID', $profile->_id],
                ['User ID', $profile->user_id],
                ['First Name', $profile->first_name ?? 'NULL'],
                ['Last Name', $profile->last_name ?? 'NULL'],
                ['Full Name', $profile->full_name ?? 'NULL'],
                ['City', $profile->city ?? 'NULL'],
                ['Address', $profile->address ?? 'NULL'],
                ['Phone', $profile->phone ?? 'NULL'],
                ['Gender', $profile->gender ?? 'NULL'],
                ['Nationality', $profile->nationality ?? 'NULL'],
                ['Date of Birth', $profile->date_of_birth ?? 'NULL'],
                ['Marital Status', $profile->marital_status ?? 'NULL'],
                ['Location', $profile->location ?? 'NULL'],
                ['Updated At', $profile->updated_at?->toDateTimeString() ?? 'NULL'],
                ['Created At', $profile->created_at?->toDateTimeString() ?? 'NULL'],
            ]
        );
        
        return 0;
    }
}
