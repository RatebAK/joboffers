<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Models\Major;
use App\Models\University;
use Illuminate\Http\Request;

/**
 * @group Education Lookups
 *
 * Lookup tables for Universities, Faculties, and Majors. These power the
 * autocomplete / dropdown suggestions used across seeker profiles and job posts.
 *
 * Admins seed the recommended values; the public listing endpoints require no
 * authentication. Values are advisory — clients may still submit freeform text
 * where these lookups are consumed.
 */
class EducationLookupController extends Controller
{
    /**
     * Resolves the route segment (universities|faculties|majors) to its model class.
     */
    private const MODELS = [
        'universities' => University::class,
        'faculties'    => Faculty::class,
        'majors'       => Major::class,
    ];

    /** Delegates the actual CRUD work to the generic LookupController. */
    private function lookup(string $resource): LookupController
    {
        return new LookupController(self::MODELS[$resource]);
    }

    /**
     * List education lookup items
     *
     * Returns every item in the given lookup table, sorted by name. Requires no
     * authentication — intended for autocomplete and dropdown population.
     *
     * @unauthenticated
     *
     * @urlParam resource string required The lookup table. One of: universities, faculties, majors. Example: universities
     *
     * @response 200 {
     *   "data": [
     *     { "_id": "664f1a2b3c4d5e6f7a8b9c0d", "name": "Damascus University" },
     *     { "_id": "664f1a2b3c4d5e6f7a8b9c0e", "name": "Tishreen University" }
     *   ]
     * }
     */
    public function index(string $resource)
    {
        return $this->lookup($resource)->index();
    }

    /**
     * Create an education lookup item (admin)
     *
     * Adds a new value to the lookup table. Names must be unique within the table.
     *
     * @authenticated
     *
     * @urlParam resource string required The lookup table. One of: universities, faculties, majors. Example: universities
     *
     * @bodyParam name string required The item name. Max 100 chars, unique per table. Example: Aleppo University
     *
     * @response 201 { "_id": "664f1a2b3c4d5e6f7a8b9c0d", "name": "Aleppo University" }
     * @response 401 { "message": "Unauthenticated." }
     * @response 403 { "message": "This action is unauthorized." }
     * @response 422 { "errors": { "name": ["The name has already been taken."] } }
     */
    public function store(Request $request, string $resource)
    {
        return $this->lookup($resource)->store($request);
    }

    /**
     * Update an education lookup item (admin)
     *
     * Renames an existing item. The new name must be unique within the table.
     *
     * @authenticated
     *
     * @urlParam resource string required The lookup table. One of: universities, faculties, majors. Example: universities
     * @urlParam id string required The item ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @bodyParam name string required The new item name. Max 100 chars, unique per table. Example: Aleppo University
     *
     * @response 200 { "_id": "664f1a2b3c4d5e6f7a8b9c0d", "name": "Aleppo University" }
     * @response 404 { "message": "Not found." }
     * @response 422 { "errors": { "name": ["The name has already been taken."] } }
     */
    public function update(Request $request, string $resource, string $id)
    {
        return $this->lookup($resource)->update($request, $id);
    }

    /**
     * Delete an education lookup item (admin)
     *
     * Removes an item from the lookup table.
     *
     * @authenticated
     *
     * @urlParam resource string required The lookup table. One of: universities, faculties, majors. Example: universities
     * @urlParam id string required The item ID. Example: 664f1a2b3c4d5e6f7a8b9c0d
     *
     * @response 200 { "message": "Deleted successfully." }
     * @response 404 { "message": "Not found." }
     */
    public function destroy(string $resource, string $id)
    {
        return $this->lookup($resource)->destroy($id);
    }
}
