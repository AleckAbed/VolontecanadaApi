<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query()->orderBy('sort_order')->orderBy('name');
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }
        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'type' => 'required|string|max:32',
            'color' => 'nullable|string|max:32',
            'icon' => 'nullable|string|max:64',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $admin = $request->user('admin');
        $category = Category::create(array_merge(
            $validator->validated(),
            ['created_by' => $admin?->id]
        ));

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function update(Request $request, $id)
    {
        $category = Category::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|string|max:32',
            'color' => 'nullable|string|max:32',
            'icon' => 'nullable|string|max:64',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $category->update($validator->validated());

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function destroy($id)
    {
        $category = Category::findOrFail($id);
        // Set children's category_id to null is automatic via FK onDelete('set null')
        $category->delete();
        return response()->json(['success' => true]);
    }
}
