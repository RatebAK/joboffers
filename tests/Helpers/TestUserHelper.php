<?php

namespace Tests\Helpers;

trait TestUserHelper
{
    /**
     * Register a user and return the token
     * 
     * @param string $role 'admin', 'employer', or 'employee'
     * @param string|null $email Custom email or auto-generated
     * @param string|null $name Custom name or auto-generated
     * @param bool $approveEmployer If role is employer, set is_employer = true
     * @return array ['token' => string, 'user_id' => string, 'user' => array]
     */
    protected function registerUser(
        string $role = 'employee',
        ?string $email = null,
        ?string $name = null,
        bool $approveEmployer = false
    ): array {
        $email = $email ?? "test_{$role}_" . uniqid() . '@test.com';
        $name = $name ?? ucfirst($role) . ' User';
        
        $response = $this->postJson('/api/auth/register', [
            'name'     => $name,
            'email'    => $email,
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role'     => $role,
        ]);

        $response->assertStatus(201);
        
        $token = $response->json('access_token');
        $userId = $response->json('user.id');
        $user = $response->json('user');

        // Auto-approve employer if requested
        if ($role === 'employer' && $approveEmployer) {
            $userModel = \App\Models\User::find($userId);
            if ($userModel) {
                $userModel->is_employer = true;
                $userModel->save();
            }
        }

        return [
            'token' => $token,
            'user_id' => $userId,
            'user' => $user,
        ];
    }

    /**
     * Register an admin user
     */
    protected function registerAdmin(?string $email = null, ?string $name = null): array
    {
        return $this->registerUser('admin', $email, $name);
    }

    /**
     * Register an approved employer user
     */
    protected function registerApprovedEmployer(?string $email = null, ?string $name = null): array
    {
        return $this->registerUser('employer', $email, $name, true);
    }

    /**
     * Register a job seeker (employee) user
     */
    protected function registerSeeker(?string $email = null, ?string $name = null): array
    {
        return $this->registerUser('employee', $email, $name);
    }

    /**
     * Get authorization header for a token
     */
    protected function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer $token"];
    }
}
