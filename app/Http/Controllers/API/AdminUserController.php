<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employer;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminUserController extends Controller
{
    /**
     * Create user (admin)
     *
     * Creates a new user account and assigns a role. If the role is `employer`,
     * the account is immediately activated (no approval required).
     *
     * @bodyParam name string required Display name. Min 2, max 100 chars. Example: John Doe
     * @bodyParam email string required Valid email address. Example: john@example.com
     * @bodyParam password string required Min 8 chars, must include uppercase, lowercase, digit, and special character. Example: Secret@123
     * @bodyParam role string required Role to assign: admin, employer, or employee. Example: employer
     *
     * @response 201 {
     *   "message": "User created successfully.",
     *   "user": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0d",
     *     "name": "John Doe",
     *     "email": "john@example.com",
     *     "roles": ["employer"],
     *     "is_employer": true
     *   }
     * }
     * @response 422 { "email": ["The email has already been taken."] }
     */
    public function createUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|min:2|max:100',
            'email'    => 'required|string|email:rfc|max:100|unique:users,email',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
            ],
            'role'     => 'required|string|in:admin,employer,employee',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $data = $validator->validated();
        $role = $data['role'];

        $isEmployer = $role === 'employer';

        $user = User::create([
            'name'        => $data['name'],
            'email'       => $data['email'],
            'password'    => Hash::make($data['password']),
            'roles'       => [$role],
            'is_employer' => $isEmployer,
        ]);

        // If employer, create a pre-approved Employer record — no application needed
        if ($isEmployer) {
            Employer::create([
                '_id'         => (string) $user->_id,
                'user_id'     => (string) $user->_id,
                'status'      => Employer::STATUS_APPROVED,
                'reviewed_by' => (string) $request->user()->_id,
                'reviewed_at' => now(),
                'review_notes'=> 'Created directly by admin.',
            ]);
        }

        // If employee, create an empty job seeker profile
        if ($role === 'employee') {
            JobSeekerProfile::firstOrCreate(['user_id' => (string) $user->_id]);
        }

        return response()->json([
            'message' => 'User created successfully.',
            'user'    => $user,
        ], 201);
    }

    /**
     * Delete user (admin)
     *
     * Permanently deletes a user account and all associated data (profile, employer record).
     * Cannot delete your own account.
     *
     * @urlParam id string required The user ID to delete. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 { "message": "User deleted successfully." }
     * @response 403 { "message": "You cannot delete your own account." }
     * @response 404 { "message": "User not found." }
     */
    public function deleteUser(Request $request, string $id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ((string) $user->_id === (string) $request->user()->_id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 403);
        }

        // Delete associated records
        JobSeekerProfile::where('user_id', (string) $user->_id)->delete();
        Employer::where('user_id', (string) $user->_id)->delete();

        $user->delete();

        return response()->json(['message' => 'User deleted successfully.']);
    }

    /**
     * Change user password (admin)
     *
     * Sets a new password for any user account. No knowledge of the current password required.
     *
     * @urlParam id string required The user ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @bodyParam password string required New password. Min 8 chars, must include uppercase, lowercase, digit, and special character. Example: NewPass@456
     * @bodyParam password_confirmation string required Must match password. Example: NewPass@456
     *
     * @response 200 { "message": "Password updated successfully." }
     * @response 404 { "message": "User not found." }
     * @response 422 { "password": ["The password confirmation does not match."] }
     */
    public function changePassword(Request $request, string $id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'password' => [
                'required',
                'string',
                'confirmed',
                'min:8',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&]/',
            ],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        return response()->json(['message' => 'Password updated successfully.']);
    }
}
