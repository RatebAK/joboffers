<?php

namespace App\Models;

use MongoDB\Laravel\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /**
     * The attributes that are mass assignable.
     * Replaced 'is_employer' with 'roles'.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'roles', // New: stores an array of roles (e.g., ['job_seeker', 'employer'])
    ];

    /**
     * The attributes that should be cast.
     * 'roles' is cast to an array for easy manipulation.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'roles' => 'array', // New: Casts the roles field to a PHP array
    ];
    
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = ['password', 'remember_token'];

    // --- JWT Methods ---

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // --- Relationships (Unchanged) ---

    // Relationship with job seeker profile (for job seekers)
    public function jobSeekerProfile()
    {
        return $this->hasOne(JobSeekerProfile::class);
    }

    // Relationship with applications (for job seekers)
    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    // Relationship with job posts (for employers)
    public function jobPosts()
    {
        return $this->hasMany(JobPost::class, 'employer_id');
    }

    // --- Role Checkers (Refactored) ---

    /**
     * Check if the user has a specific role.
     *
     * @param string $role
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        // Ensure roles is treated as an array and check if the role exists
        return in_array($role, $this->roles ?? []);
    }

    /**
     * Check if user is an employer.
     *
     * @return bool
     */
    public function isEmployer(): bool
    {
        return $this->hasRole('employer');
    }

    /**
     * Check if user is a job seeker.
     * We assume a user is a job seeker if they don't have the employer role.
     * You might adjust this logic depending on your default user creation process.
     *
     * @return bool
     */
    public function isJobSeeker(): bool
    {
        // Check explicitly for the 'job_seeker' role, or if no roles are defined
        // For simplicity, let's just check for the explicit role
        return $this->hasRole('job_seeker');
    }
}
