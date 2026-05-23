<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory;
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
        'roles',
        'is_employer', // set to true when admin approves employer application
    ];

    /**
     * The attributes that should be cast.
     * 'roles' is cast to an array for easy manipulation.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'roles'             => 'array',
        'is_employer'       => 'boolean',
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
     * Check if user is a job seeker (employee).
     *
     * @return bool
     */
    public function isJobSeeker(): bool
    {
        // Check explicitly for the 'employee' role
        return $this->hasRole('employee');
    }

    /**
     * Check if user is an admin.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    /**
     * Check if user has any of the specified roles.
     *
     * @param array $roles
     * @return bool
     */
    public function hasAnyRole(array $roles): bool
    {
        // Ensure roles is treated as an array
        $userRoles = $this->roles ?? [];
        
        // Check if any of the specified roles exist in the user's roles
        return !empty(array_intersect($roles, $userRoles));
    }
}
