<?php

namespace Database\Seeders;

use App\Models\CompanyProfile;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds demo companies, each backed by an APPROVED employer user.
 *
 * For every company:
 *   1. Create/find an employer User (roles ['employer'], is_employer = true).
 *   2. Create/find an Employer approval record with status 'approved'.
 *   3. Create/find the CompanyProfile linked by employer_id (= User _id).
 *
 * Idempotent: keyed by employer email + company name, safe to re-run.
 * All demo accounts share the DEMO_TAG marker so they can be cleanly removed.
 */
class CompanyProfileSeeder extends Seeder
{
    public const DEMO_TAG = 'demo_seed';

    public const DEFAULT_PASSWORD = 'Employer!123';

    public function run(): void
    {
        $companies = require database_path('seeders/data/DemoCompanies.php');

        foreach ($companies as $c) {
            // 1. Approved employer user
            $user = User::where('email', $c['employer_email'])->first();
            if (! $user) {
                $user = User::create([
                    'name'              => $c['employer_name'],
                    'email'             => $c['employer_email'],
                    'password'          => Hash::make(self::DEFAULT_PASSWORD),
                    'roles'             => ['employer'],
                    'is_employer'       => true,
                    'email_verified_at' => now(),
                    'demo_source'       => self::DEMO_TAG,
                ]);
            } else {
                // Ensure it is a usable, approved employer even if it pre-existed.
                $user->update([
                    'roles'       => array_values(array_unique(array_merge($user->roles ?? [], ['employer']))),
                    'is_employer' => true,
                ]);
            }

            // 2. Employer approval record
            $employer = Employer::where('user_id', (string) $user->_id)->first();
            if (! $employer) {
                Employer::create([
                    'user_id'      => (string) $user->_id,
                    'status'       => Employer::STATUS_APPROVED,
                    'review_notes' => 'Auto-approved (demo seed).',
                    'reviewed_at'  => now(),
                    'demo_source'  => self::DEMO_TAG,
                ]);
            } elseif ($employer->status !== Employer::STATUS_APPROVED) {
                $employer->update(['status' => Employer::STATUS_APPROVED, 'reviewed_at' => now()]);
            }

            // 3. Company profile
            $company = CompanyProfile::where('employer_id', (string) $user->_id)->first();
            if (! $company) {
                CompanyProfile::create([
                    'employer_id'   => (string) $user->_id,
                    'name'          => $c['name'],
                    'slug'          => Str::slug($c['name']),
                    'logo'          => $c['logo'] ?? null,
                    'description'   => $c['description'] ?? null,
                    'industry'      => $c['industry'] ?? null,
                    'company_size'  => $c['company_size'] ?? null,
                    'city'          => $c['city'] ?? null,
                    'country'       => $c['country'] ?? null,
                    'email'         => $c['email'] ?? null,
                    'phone_main'    => $c['phone_main'] ?? null,
                    'phone_visible' => true,
                    'rating'        => 0,
                    'review_count'  => 0,
                    'demo_source'   => self::DEMO_TAG,
                ]);
            }
        }

        $this->command->info('Seeded ' . count($companies) . ' demo companies with approved employers.');
    }
}
