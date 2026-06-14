<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NewsSourceController extends Controller
{
    public function publicIndex(Request $request)
    {
        $sources = NewsSource::where('is_active', true)
            ->orderBy('sort_order')->orderByDesc('followers_count')
            ->get();

        $admin = $request->user('admin');
        $followedIds = $admin
            ? $admin->followedSources()->pluck('news_sources.id')->all()
            : [];

        return response()->json([
            'success' => true,
            'data' => $sources->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'avatar' => $s->avatar,
                'description' => $s->description,
                'website' => $s->website,
                'followers_count' => $s->followers_count,
                'is_following' => in_array($s->id, $followedIds, true),
            ]),
        ]);
    }

    public function index(Request $request)
    {
        return $this->publicIndex($request);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $admin = $request->user('admin');
        $data = $validator->validated();
        $data['created_by'] = $admin?->id;
        $source = NewsSource::create($data);
        return response()->json(['success' => true, 'data' => $source], 201);
    }

    public function update(Request $request, $id)
    {
        $source = NewsSource::findOrFail($id);
        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $source->update($validator->validated());
        return response()->json(['success' => true, 'data' => $source]);
    }

    public function destroy($id)
    {
        NewsSource::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    public function follow(Request $request, $id)
    {
        $source = NewsSource::findOrFail($id);
        $admin = $request->user('admin');
        if (!$admin) return response()->json(['success' => false], 401);

        if (!$admin->followedSources()->where('news_sources.id', $id)->exists()) {
            $admin->followedSources()->attach($id);
            $source->increment('followers_count');
        }
        return response()->json(['success' => true]);
    }

    public function unfollow(Request $request, $id)
    {
        $source = NewsSource::findOrFail($id);
        $admin = $request->user('admin');
        if (!$admin) return response()->json(['success' => false], 401);

        if ($admin->followedSources()->where('news_sources.id', $id)->exists()) {
            $admin->followedSources()->detach($id);
            $source->decrement('followers_count');
        }
        return response()->json(['success' => true]);
    }

    private function rules(bool $patch = false): array
    {
        $req = $patch ? 'sometimes|' : 'required|';
        return [
            'name' => $req . 'string|max:255',
            'avatar' => 'nullable|string',
            'description' => 'nullable|string',
            'website' => 'nullable|string|max:255',
            'followers_count' => 'nullable|integer|min:0',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ];
    }
}
