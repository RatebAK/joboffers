<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CompanyProfileController extends Controller
{
    /**
     * Public: paginated list of company profiles.
     * @unauthenticated
     */
    public function index(Request $request)
    {
        $query = CompanyProfile::query();

        if ($search = $request->query('search')) {
            $regex = new \MongoDB\BSON\Regex($search, 'i');
            $query->where(function ($q) use ($regex) {
                $q->where('name', $regex)->orWhere('location', $regex);
            });
        }

        if ($industry = $request->query('industry')) {
            $query->where('industry', new \MongoDB\BSON\Regex($industry, 'i'));
        }

        if ($minRating = $request->query('min_rating')) {
            $query->where('rating', '>=', (float) $minRating);
        }

        if ($companySize = $request->query('company_size')) {
            $query->where('company_size', new \MongoDB\BSON\Regex($companySize, 'i'));
        }

        $perPage = min((int) ($request->query('per_page', 15)), 100);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $paginator->getCollection()->transform(function ($profile) {
            return $this->withOpenPositions($profile);
        });

        return response()->json([
            'data'         => $paginator->items(),
            'current_page' => $paginator->currentPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
            'total_pages'  => $paginator->lastPage(),
            'next_page'    => $paginator->hasMorePages() ? $paginator->currentPage() + 1 : null,
            'prev_page'    => $paginator->currentPage() > 1 ? $paginator->currentPage() - 1 : null,
            'next_page_url'=> $paginator->nextPageUrl(),
            'prev_page_url'=> $paginator->previousPageUrl(),
        ]);
    }

    /**
     * Public: single company profile.
     * @unauthenticated
     * @urlParam id string required The company profile ID.
     */
    public function show(string $id)
    {
        $profile = CompanyProfile::find($id);

        if (!$profile) {
            return response()->json(['message' => 'Company profile not found'], 404);
        }

        return response()->json($this->withOpenPositions($profile));
    }

    /**
     * Employer: create or update own company profile.
     */
    public function upsert(Request $request)
    {
        $validated = $request->validate([
            'name'         => 'required|string|max:150',
            'logo'         => 'nullable|url',
            'description'  => 'nullable|string',
            'location'     => 'nullable|string|max:150',
            'company_size' => 'nullable|string|max:100',
            'industry'     => 'nullable|string|max:100',
            'website'      => 'nullable|url',
        ]);

        $employerId = (string) Auth::user()->_id;

        $profile = CompanyProfile::updateOrCreate(
            ['employer_id' => $employerId],
            $validated
        );

        return response()->json($this->withOpenPositions($profile), 200);
    }

    /**
     * Append live open_positions count to a profile.
     */
    private function withOpenPositions(CompanyProfile $profile): array
    {
        $data = $profile->toArray();
        $data['open_positions'] = JobPost::where('employer_id', $profile->employer_id)
            ->where('is_active', true)
            ->count();
        return $data;
    }
}
