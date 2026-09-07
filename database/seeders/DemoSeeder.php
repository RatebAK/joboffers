<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orchestrates the full demo dataset in dependency order:
 *   1. Companies + approved employers
 *   2. Job seekers + profiles (attaches uploaded CVs from the manifest)
 *   3. Job posts (needs companies)
 *   4. Activity: applications + direct offers (needs seekers + jobs)
 *
 * No AI services are called — ai_* fields come from static CV-derived data.
 *
 * Run on its own:   php artisan db:seed --class=DemoSeeder
 * Remove it later:  php artisan demo:clear
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompanyProfileSeeder::class,
            JobSeekerProfileSeeder::class,
            JobPostSeeder::class,
            ActivitySeeder::class,
            ScenarioSeeder::class,
        ]);
    }
}
