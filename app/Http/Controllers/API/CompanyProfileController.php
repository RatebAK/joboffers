<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

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
     * company_size accepts either a string ("100-500" / "500+") or an object { min, max?, isPlus }.
     */
    public function upsert(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:150',
            'logo'                  => 'nullable|url',
            'description'           => 'nullable|string',
            'location'              => 'nullable|string|max:150',
            'company_size'          => 'nullable',
            'company_size.min'      => 'required_with:company_size|integer|min:0',
            'company_size.max'      => 'nullable|integer',
            'company_size.isPlus'   => 'required_with:company_size|boolean',
            'industry'              => 'nullable|string|max:100',
            'website'               => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Normalize company_size to a canonical string for storage
        if (isset($validated['company_size']) && is_array($validated['company_size'])) {
            $cs = $validated['company_size'];
            $validated['company_size'] = $cs['isPlus']
                ? "{$cs['min']}+"
                : "{$cs['min']}-{$cs['max']}";
        }

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
        $data['company_size_range'] = $this->parseCompanySize($profile->company_size);
        return $data;
    }

    /**
     * Parse a company_size string like "100-500 employees" or "500+ employees"
     * into a structured { min, max?, isPlus } object for frontend consumption.
     */
    private function parseCompanySize(?string $size): ?array
    {
        if (!$size) return null;

        // Match "500+" or "500+ employees"
        if (preg_match('/^(\d+)\+/', $size, $m)) {
            return ['min' => (int) $m[1], 'isPlus' => true];
        }

        // Match "100-500" or "100-500 employees"
        if (preg_match('/^(\d+)-(\d+)/', $size, $m)) {
            return ['min' => (int) $m[1], 'max' => (int) $m[2], 'isPlus' => false];
        }

        return null;
    }
}
