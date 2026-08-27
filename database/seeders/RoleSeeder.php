<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            // Software & Engineering
            'Software Engineer',
            'Frontend Developer',
            'Backend Developer',
            'Full Stack Developer',
            'Mobile Developer',
            'iOS Developer',
            'Android Developer',
            'DevOps Engineer',
            'Site Reliability Engineer',
            'Cloud Architect',
            'QA Engineer',
            'Embedded Systems Engineer',
            'Network Engineer',
            'Cybersecurity Analyst',
            // Data & AI
            'Data Scientist',
            'Data Analyst',
            'Data Engineer',
            'Machine Learning Engineer',
            'AI Researcher',
            'Business Intelligence Analyst',
            // Design
            'UX Designer',
            'UI Designer',
            'Graphic Designer',
            'Product Designer',
            'Motion Designer',
            'Illustrator',
            // Product & Management
            'Product Manager',
            'Project Manager',
            'Scrum Master',
            'Program Manager',
            'Business Analyst',
            'Operations Manager',
            // Marketing & Sales
            'Marketing Specialist',
            'Digital Marketing Specialist',
            'SEO Specialist',
            'Content Writer',
            'Copywriter',
            'Social Media Manager',
            'Sales Representative',
            'Account Manager',
            'Brand Manager',
            // Finance & Legal
            'Accountant',
            'Financial Analyst',
            'Auditor',
            'Legal Advisor',
            'Compliance Officer',
            // HR & Admin
            'HR Specialist',
            'Recruiter',
            'Administrative Assistant',
            'Office Manager',
            // Healthcare
            'Nurse',
            'Pharmacist',
            'Medical Doctor',
            'Lab Technician',
            'Physical Therapist',
            // Education
            'Teacher',
            'Tutor',
            'Curriculum Developer',
            'Training Specialist',
            // Customer Support
            'Customer Support Specialist',
            'Technical Support Engineer',
            'Call Center Agent',
        ];

        foreach ($roles as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }
}
