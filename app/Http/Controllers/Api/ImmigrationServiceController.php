<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImmigrationService;
use Illuminate\Http\Request;

class ImmigrationServiceController extends Controller
{
    public function index(Request $request)
    {
        $q = ImmigrationService::orderBy('sort_order')->orderBy('name');
        if ($request->boolean('active_only')) {
            $q->where('status', 'active');
        }
        return response()->json(['success' => true, 'data' => $q->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:immigration_services,name',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'duration' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:20',
            'status' => 'nullable|in:active,inactive,pending',
            'sort_order' => 'nullable|integer',
        ]);
        $data['status'] = $data['status'] ?? 'active';
        $svc = ImmigrationService::create($data);
        return response()->json(['success' => true, 'message' => 'Service créé', 'data' => $svc], 201);
    }

    public function update(Request $request, $id)
    {
        $svc = ImmigrationService::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255|unique:immigration_services,name,' . $svc->id,
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:100',
            'duration' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:20',
            'status' => 'nullable|in:active,inactive,pending',
            'sort_order' => 'nullable|integer',
        ]);
        $svc->update($data);
        return response()->json(['success' => true, 'message' => 'Service mis à jour', 'data' => $svc->fresh()]);
    }

    public function destroy($id)
    {
        ImmigrationService::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}
