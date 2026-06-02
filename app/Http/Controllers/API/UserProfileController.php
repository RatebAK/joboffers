<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    /**
     * Get any user's full profile
     *
     * Returns the full profile of any user by their ID. The profile type returned depends on the target user's role.
     * Admins receive all fields. Non-admin callers receive all fields except password.
     *
     * @urlParam userId string required The target user's ID. Example: 664f1a2b3c4d5e6f7a8b9c0e
     *
     * @response 200 {
     *   "user": {
     *     "id": "664f1a2b3c4d5e6f7a8b9c0e",
     *     "name": "Jane Smith",
     *     "email": "jane@example.com",
     *     "roles": ["employee"],
     *     "profile": {}
     *   }
     * }
     * @response 404 { "message": "User not found" }
     */
    public function show(Request $request, string $userId)
    {
        $target = User::find($userId);

        if (!$target) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $caller = $request->user();
        $userData = $target->toArray();
        // password is already hidden via $hidden on the model

        $profile = null;

        if ($target->isJobSeeker()) {
            $profile = JobSeekerProfile::where('user_id', (string) $target->_id)->first();
            if ($profile) {
                $profile = $profile->toArray();
            }
        } elseif ($target->isEmployer()) {
            $companyProfile = CompanyProfile::where('employer_id', (string) $target->_id)->first();
            if ($companyProfile) {
                $profile = $companyProfile->toArray();
                $profile['open_positions_count'] = JobPost::where('employer_id', (string) $target->_id)
                    ->where('is_active', true)
                    ->count();
            }
        }

        return response()->json([
            'user' => array_merge($userData, ['profile' => $profile]),
        ]);
    }

    /**
     * List all users (admin)
     *
     * Paginated list of all users with their roles and basic info. Admin only.
     *
     * @queryParam per_page integer Results per page. Default 15. Example: 15
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "data": [{ "id": "...", "name": "...", "email": "...", "roles": ["employee"] }],
     *   "current_page": 1, "per_page": 15, "total": 10, "total_pages": 1, "next_page": null, "prev_page": null
     * }
     */
    public function adminListAll(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);
        $paginator = User::orderBy('created_at', 'desc')->paginate($perPage);

        $items = collect($paginator->items())->map(function (User $user) {
            $data = $user->toArray();
            $data['id'] = (string) $user->_id;
            return $data;
        });

        return response()->json([
            'data'        => $items,
            'current_page'=> $paginator->currentPage(),
            'per_page'    => $paginator->perPage(),
            'total'       => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
            'next_page'   => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'prev_page'   => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
        ]);
    }

    /**
     * List all job seekers (admin)
     *
     * Paginated list of all users with the employee role, each with their full JobSeekerProfile. Admin only.
     *
     * @queryParam per_page integer Results per page. Default 15. Example: 15
     *
     * @response 200 {
     *   "data": [{ "id": "...", "name": "...", "profile": {} }],
     *   "current_page": 1, "per_page": 15, "total": 5, "total_pages": 1, "next_page": null, "prev_page": null
     * }
     */
    public function adminListSeekers(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);
        $page    = max(1, (int) $request->query('page', 1));

        // Fetch all users and filter in PHP — avoids MongoDB array-field query issues
        $allSeekers = User::get()->filter(fn(User $u) => $u->isJobSeeker())->values();

        $total      = $allSeekers->count();
        $totalPages = (int) ceil($total / $perPage);
        $offset     = ($page - 1) * $perPage;

        $items = $allSeekers->slice($offset, $perPage)->map(function (User $user) {
            $userData        = $user->toArray();
            $userData['id']  = (string) $user->_id;
            $profile         = JobSeekerProfile::where('user_id', (string) $user->_id)->first();
            $userData['profile'] = $profile ? $profile->toArray() : null;
            return $userData;
        })->values();

        return response()->json([
            'data'        => $items,
            'current_page'=> $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
            'next_page'   => $page < $totalPages ? $page + 1 : null,
            'prev_page'   => $page > 1 ? $page - 1 : null,
        ]);
    }

    /**
     * List all employers (admin)
     *
     * Paginated list of all users with the employer role, each with their full CompanyProfile. Admin only.
     *
     * @queryParam per_page integer Results per page. Default 15. Example: 15
     *
     * @response 200 {
     *   "data": [{ "id": "...", "name": "...", "profile": {} }],
     *   "current_page": 1, "per_page": 15, "total": 3, "total_pages": 1, "next_page": null, "prev_page": null
     * }
     */
    public function adminListEmployers(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 15), 100);
        $page    = max(1, (int) $request->query('page', 1));

        $allEmployers = User::get()->filter(fn(User $u) => $u->isEmployer())->values();

        $total      = $allEmployers->count();
        $totalPages = (int) ceil($total / $perPage);
        $offset     = ($page - 1) * $perPage;

        $items = $allEmployers->slice($offset, $perPage)->map(function (User $user) {
            $userData       = $user->toArray();
            $userData['id'] = (string) $user->_id;
            $company        = CompanyProfile::where('employer_id', (string) $user->_id)->first();
            if ($company) {
                $companyData = $company->toArray();
                $companyData['open_positions_count'] = JobPost::where('employer_id', (string) $user->_id)
                    ->where('is_active', true)
                    ->count();
                $userData['profile'] = $companyData;
            } else {
                $userData['profile'] = null;
            }
            return $userData;
        })->values();

        return response()->json([
            'data'        => $items,
            'current_page'=> $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => $totalPages,
            'next_page'   => $page < $totalPages ? $page + 1 : null,
            'prev_page'   => $page > 1 ? $page - 1 : null,
        ]);
    }
}
