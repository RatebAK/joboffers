<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LookupController extends Controller
{
    protected string $modelClass;

    public function __construct(string $modelClass)
    {
        $this->modelClass = $modelClass;
    }

    /** Returns the MongoDB collection name for the model (e.g. "categories"). */
    protected function collectionName(): string
    {
        return (new $this->modelClass)->getTable();
    }

    /** List all items ordered by name ASC. */
    public function index()
    {
        $items = ($this->modelClass)::orderBy('name', 'asc')->get();

        return response()->json(['data' => $items]);
    }

    /** Create a new item (admin). */
    public function store(Request $request)
    {
        $collection = $this->collectionName();

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:100',
                "unique:{$collection},name",
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item = ($this->modelClass)::create([
            'name' => trim($request->name),
        ]);

        return response()->json($item, 201);
    }

    /** Update an existing item (admin). */
    public function update(Request $request, string $id)
    {
        $item = ($this->modelClass)::find($id);

        if (! $item) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $collection = $this->collectionName();

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:100',
                "unique:{$collection},name,{$id},_id",
            ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $item->update(['name' => trim($request->name)]);

        return response()->json(($this->modelClass)::find($id));
    }

    /** Delete an item (admin). */
    public function destroy(string $id)
    {
        $item = ($this->modelClass)::find($id);

        if (! $item) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Deleted successfully.']);
    }
}
