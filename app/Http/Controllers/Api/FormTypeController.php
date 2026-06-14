<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FormType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FormTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = FormType::query()->with('category')
            ->orderBy('sort_order')->orderBy('name');
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'nullable|string|max:255|unique:form_types,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (empty($data['code'])) {
            $base = Str::slug($data['name'], '_');
            $code = $base;
            $i = 1;
            while (FormType::where('code', $code)->exists()) {
                $code = $base . '_' . (++$i);
            }
            $data['code'] = $code;
        }

        $formType = FormType::create($data);
        return response()->json(['success' => true, 'data' => $formType->load('category')], 201);
    }

    public function update(Request $request, $id)
    {
        $formType = FormType::findOrFail($id);
        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|max:255|unique:form_types,code,' . $id,
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $formType->update($validator->validated());
        return response()->json(['success' => true, 'data' => $formType->load('category')]);
    }

    public function destroy($id)
    {
        $formType = FormType::findOrFail($id);
        $formType->delete();
        return response()->json(['success' => true]);
    }
}
