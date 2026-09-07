<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Str;

/**
 * Turns a CV filename into a deterministic, role-appropriate job-seeker profile.
 *
 * The CV set is a staffing/consulting dataset (Business Analysts, Project
 * Managers / Scrum Masters, Java / Full-Stack / PHP / Hadoop developers, QA,
 * etc.), and the ROLE is encoded in the filename. So we:
 *   1. Parse a human name out of the filename (dropping role tokens).
 *   2. Infer a role family from role keywords in the filename.
 *   3. Emit structured + ai_* fields from a per-role template.
 *
 * No AI service is called — every value here is generated locally. Output is
 * deterministic per filename (seeded RNG) so re-runs are stable.
 */
class CvProfileFactory
{
    /** Role families keyed by detection priority (first match wins). */
    private const ROLES = [
        'scrum' => [
            'match'   => ['scrum master', 'scrum', 'agile-scrum', 'agile program', ' sm ', ' sm.', '-sm', '_sm'],
            'title'   => 'Scrum Master',
            'level'   => 'senior',
            'category' => 'Product & Management',
            'roles'   => ['Scrum Master', 'Agile Coach'],
            'skills'  => ['Scrum', 'Agile', 'JIRA', 'Kanban', 'Sprint Planning', 'Stakeholder Management', 'SAFe'],
            'langs'   => ['English'],
        ],
        'pm' => [
            'match'   => ['project manager', 'program manager', 'technical program', ' pm', 'pm ', 'pmp', 'pm.', '_pm', 'pm_', '- pm', 'pmcpcsm'],
            'title'   => 'Project Manager',
            'level'   => 'senior',
            'category' => 'Product & Management',
            'roles'   => ['Project Manager', 'Program Manager'],
            'skills'  => ['Project Management', 'PMP', 'Agile', 'Scrum', 'Risk Management', 'Stakeholder Management', 'MS Project', 'Budgeting'],
            'langs'   => ['English'],
        ],
        'bsa' => [
            'match'   => ['bsa', 'sr bsa', 'sr.bsa', 'business system analyst'],
            'title'   => 'Senior Business Systems Analyst',
            'level'   => 'senior',
            'category' => 'Product & Management',
            'roles'   => ['Business Systems Analyst', 'Business Analyst'],
            'skills'  => ['Requirements Gathering', 'UML', 'SQL', 'JIRA', 'Agile', 'Gap Analysis', 'UAT', 'Process Modeling', 'Confluence'],
            'langs'   => ['English'],
        ],
        'ba' => [
            'match'   => ['business analyst', ' ba', 'ba ', 'ba.', '_ba', 'ba_', '-ba', 'ba-', 'it ba'],
            'title'   => 'Business Analyst',
            'level'   => 'mid',
            'category' => 'Product & Management',
            'roles'   => ['Business Analyst'],
            'skills'  => ['Requirements Gathering', 'SQL', 'JIRA', 'Agile', 'Wireframing', 'UAT', 'Data Analysis', 'Stakeholder Management'],
            'langs'   => ['English'],
        ],
        'fullstack' => [
            'match'   => ['fullstack', 'full stack', 'full-stack'],
            'title'   => 'Full Stack Java Developer',
            'level'   => 'senior',
            'category' => 'Information Technology',
            'roles'   => ['Full Stack Developer', 'Java Developer'],
            'skills'  => ['Java', 'Spring Boot', 'React', 'Angular', 'REST APIs', 'Microservices', 'SQL', 'AWS', 'Git'],
            'langs'   => ['English'],
        ],
        'hadoop' => [
            'match'   => ['hadoop', 'big data', 'eda'],
            'title'   => 'Hadoop / Big Data Developer',
            'level'   => 'senior',
            'category' => 'Data & AI',
            'roles'   => ['Data Engineer', 'Hadoop Developer'],
            'skills'  => ['Hadoop', 'Spark', 'Hive', 'Kafka', 'Scala', 'Python', 'SQL', 'ETL', 'HDFS'],
            'langs'   => ['English'],
        ],
        'php' => [
            'match'   => ['php'],
            'title'   => 'Senior PHP Developer',
            'level'   => 'senior',
            'category' => 'Information Technology',
            'roles'   => ['Backend Developer', 'PHP Developer'],
            'skills'  => ['PHP', 'Laravel', 'MySQL', 'REST APIs', 'JavaScript', 'Git', 'Redis', 'Docker'],
            'langs'   => ['English'],
        ],
        'qa' => [
            'match'   => ['qa', 'testing', 'test'],
            'title'   => 'QA Engineer',
            'level'   => 'mid',
            'category' => 'Information Technology',
            'roles'   => ['QA Engineer'],
            'skills'  => ['Selenium', 'Test Automation', 'JIRA', 'Manual Testing', 'API Testing', 'SQL', 'Postman'],
            'langs'   => ['English'],
        ],
        'mobile' => [
            'match'   => ['mobile', 'android', 'ios'],
            'title'   => 'Mobile Developer',
            'level'   => 'mid',
            'category' => 'Information Technology',
            'roles'   => ['Mobile Developer'],
            'skills'  => ['Android', 'Kotlin', 'Java', 'iOS', 'Swift', 'REST APIs', 'Git'],
            'langs'   => ['English'],
        ],
        'java' => [
            'match'   => ['java', 'j2ee'],
            'title'   => 'Senior Java Developer',
            'level'   => 'senior',
            'category' => 'Information Technology',
            'roles'   => ['Backend Developer', 'Java Developer'],
            'skills'  => ['Java', 'Spring Boot', 'Hibernate', 'REST APIs', 'Microservices', 'SQL', 'Kafka', 'AWS', 'Git'],
            'langs'   => ['English'],
        ],
        // Fallback when no role keyword is found.
        'default' => [
            'match'   => [],
            'title'   => 'Software Engineer',
            'level'   => 'mid',
            'category' => 'Information Technology',
            'roles'   => ['Software Engineer'],
            'skills'  => ['Java', 'JavaScript', 'SQL', 'Git', 'REST APIs', 'Problem Solving'],
            'langs'   => ['English'],
        ],
    ];

    /** Tokens that are role/marker noise in filenames, not part of a name. */
    private const NOISE_TOKENS = [
        'resume', 'cv', 'profile', 'updated', 'final', 'new', 'copy', 'doc', 'docx',
        'sr', 'senior', 'jr', 'junior', 'lead',
        'ba', 'bsa', 'pm', 'pmp', 'csm', 'pmcpcsm', 'devops', 'qa', 'sm', 'inv', 'erp', 'msis', 'pmi',
        'business', 'analyst', 'systems', 'system', 'project', 'program', 'manager', 'scrum', 'master', 'agile',
        'java', 'j2ee', 'developer', 'dev', 'fullstack', 'full', 'stack', 'php', 'hadoop', 'mobile', 'testing', 'test',
        'it', 'technical', 'healthcare', 'health', 'care', 'finance', 'eda', 'uidev', 'phplead', 'aw', 'ab', 'nj',
        'certified', 'years', 'feb', 'mar', 'march', 'jan', 'app', 'employer', 'details',
    ];

    private array $cities = ['Damascus', 'Aleppo', 'Homs', 'Latakia', 'Tartus', 'Hama'];

    /**
     * Build a full seeker definition (account + profile + ai) from a filename.
     *
     * @return array<string, mixed>
     */
    public function fromFilename(string $filename): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        // Deterministic RNG seeded by the filename so runs are reproducible.
        $seed = crc32($base);
        mt_srand($seed);

        $roleKey = $this->detectRole($base);
        $role = self::ROLES[$roleKey];

        $name = $this->parseName($base);
        [$first, $last] = $this->splitName($name);

        $emailLocal = Str::slug($name, '.') ?: 'candidate';
        $email = $emailLocal . '.' . substr(md5($base), 0, 6) . '@talent.demo';

        $city = $this->cities[$seed % count($this->cities)];
        $years = $role['level'] === 'senior' ? mt_rand(6, 14) : ($role['level'] === 'mid' ? mt_rand(3, 6) : mt_rand(1, 3));
        $ats = mt_rand(64, 94);
        $skills = $role['skills'];

        // A couple of plausible work-history entries.
        $companiesPool = ['Infosys', 'TCS', 'Cognizant', 'Wipro', 'Accenture', 'Capgemini', 'HCL', 'Tech Mahindra'];
        $workHistory = [
            [
                'title'   => $role['title'],
                'company' => $companiesPool[mt_rand(0, count($companiesPool) - 1)],
                'from'    => (string) (2026 - $years),
                'to'      => 'Present',
                'summary' => "Worked as a {$role['title']} delivering enterprise projects.",
            ],
            [
                'title'   => $role['roles'][count($role['roles']) - 1],
                'company' => $companiesPool[mt_rand(0, count($companiesPool) - 1)],
                'from'    => (string) (2026 - $years - 3),
                'to'      => (string) (2026 - $years),
                'summary' => 'Contributed to client engagements and cross-functional teams.',
            ],
        ];

        mt_srand(); // restore randomness for anything after this call

        $salaryFrom = $role['level'] === 'senior' ? 2000 : ($role['level'] === 'mid' ? 1200 : 700);
        $salaryTo   = $salaryFrom + 1200;

        return [
            'role_key'   => $roleKey,
            'email'      => $email,
            'name'       => $name,
            'cv_file'    => $filename,

            'profile' => [
                'first_name'          => $first,
                'last_name'           => $last,
                'full_name'           => $name,
                'gender'              => 'no_preference',
                'nationality'        => 'Syrian',
                'city'                => $city,
                'location'            => $city . ', Syria',
                'current_job_title'   => $role['title'],
                'current_job_status'  => 'employed',
                'job_level'           => $role['level'],
                'years_of_experience' => $years,
                'education_level'     => 'bachelor',
                'job_types'           => ['full-time', 'remote'],
                'job_roles'           => $role['roles'],
                'work_cities'         => [$city, 'Remote'],
                'expected_salary'     => $salaryTo,
                'salary_range_from'   => $salaryFrom,
                'salary_range_to'     => $salaryTo,
                'is_actively_seeking' => true,
                'experience_summary'  => "{$role['title']} with {$years} years of experience.",
                'skills'              => $this->asSkillObjects($skills),
                'education_history'   => [[
                    'id'               => 1,
                    'certificate_type' => 'bachelor',
                    'university'       => 'University of Technology',
                    'faculty'          => 'Computer Science',
                    'major'            => 'Computer Science',
                    'major_name'       => 'Computer Science',
                    'grade'            => 'Good',
                    'from_date'        => (string) (2026 - $years - 4) . '-09-01',
                    'awarded_date'     => (string) (2026 - $years) . '-07-01',
                ]],
                'work_experience'     => array_map(fn ($w, $i) => [
                    'id'                   => $i + 1,
                    'job_title'            => $w['title'],
                    'company_name'         => $w['company'],
                    'job_roles'            => $role['roles'],
                    'from_date'            => $w['from'] . '-01-01',
                    'to_date'              => $w['to'] === 'Present' ? null : $w['to'] . '-01-01',
                    'is_currently_working' => $w['to'] === 'Present',
                    'description'          => $w['summary'],
                ], $workHistory, array_keys($workHistory)),
            ],

            'ai' => [
                'ai_full_name'          => $name,
                'ai_email'              => $email,
                'ai_phone'              => null,
                'ai_location'           => $city . ', Syria',
                'ai_summary'            => "{$role['title']} with {$years}+ years across enterprise engagements. Strong in " . implode(', ', array_slice($skills, 0, 4)) . '.',
                'ai_skills'             => $skills,
                'ai_work_history'       => $workHistory,
                'ai_education_history'  => [[
                    'degree'      => 'BSc Computer Science',
                    'institution' => 'University of Technology',
                    'year'        => (string) (2026 - $years),
                ]],
                'ai_projects'           => [],
                'ai_languages'          => $role['langs'],
                'ai_social_links'       => [],
                'ai_overall_evaluation' => "Strong {$role['title']} candidate; good fit for {$role['category']} roles.",
                'ats_score'             => $ats,
            ],
        ];
    }

    /** Role families used to spread out name-only CVs (no role token in filename). */
    private const DISTRIBUTED_ROLES = ['ba', 'bsa', 'pm', 'scrum', 'java', 'fullstack', 'php', 'hadoop', 'qa', 'mobile'];

    private function detectRole(string $base): string
    {
        // Keep some spacing/punctuation forms so tokens like " sm " or "-sm" match.
        $spaced = ' ' . strtolower(str_replace(['_', '-', '.'], ' ', $base)) . ' ';
        $dashed = ' ' . strtolower(str_replace(['_', '.'], ' ', $base)) . ' ';

        foreach (self::ROLES as $key => $def) {
            if ($key === 'default') {
                continue;
            }
            foreach ($def['match'] as $needle) {
                $n = strtolower($needle);
                if (str_contains($spaced, $n) || str_contains($dashed, $n)) {
                    return $key;
                }
            }
        }

        // No role token in the filename (e.g. "Alekhya Resume"). Rather than make
        // everyone a generic Software Engineer, assign a role deterministically
        // from the filename hash so the talent pool stays varied and realistic.
        $idx = crc32($base) % count(self::DISTRIBUTED_ROLES);

        return self::DISTRIBUTED_ROLES[$idx];
    }

    private function parseName(string $base): string
    {
        // Normalise separators, drop parenthetical/version junk.
        $s = preg_replace('/\(.*?\)/', ' ', $base);
        $s = str_replace(['_', '-', '.', '+'], ' ', (string) $s);
        $s = preg_replace('/\d+/', ' ', $s); // strip numbers (years, versions)

        $words = preg_split('/\s+/', trim((string) $s)) ?: [];

        $keep = [];
        foreach ($words as $w) {
            $lw = strtolower($w);
            if ($lw === '' || in_array($lw, self::NOISE_TOKENS, true)) {
                continue;
            }
            if (strlen($w) === 1) {
                // Keep single-letter initials (e.g. "B Shaker" -> keep "B").
                $keep[] = strtoupper($w);
                continue;
            }
            $keep[] = ucfirst($lw);
            if (count($keep) >= 3) {
                break; // First + middle/last is enough for a display name.
            }
        }

        $name = trim(implode(' ', $keep));

        return $name !== '' ? $name : 'Candidate ' . substr(md5($base), 0, 4);
    }

    /** @return array{0:string,1:string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', $name) ?: [];
        $first = $parts[0] ?? $name;
        $last = count($parts) > 1 ? end($parts) : '';

        return [$first, $last];
    }

    /** @return array<int, array<string, mixed>> */
    private function asSkillObjects(array $skills): array
    {
        $out = [];
        foreach (array_values($skills) as $i => $s) {
            $out[] = ['id' => $i + 1, 'name' => $s, 'level' => $i < 3 ? 'advanced' : 'intermediate'];
        }

        return $out;
    }
}
