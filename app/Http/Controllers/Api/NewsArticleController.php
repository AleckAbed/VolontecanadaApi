<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NewsArticle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NewsArticleController extends Controller
{
    // ─── PUBLIC ─────────────────────────────────────────────────────────────

    /** List published articles (public read endpoint for the dashboard). */
    public function publicIndex(Request $request)
    {
        $query = NewsArticle::with(['category', 'source'])
            ->where('is_published', true)
            ->orderByDesc('published_at')
            ->orderByDesc('created_at');

        if ($request->boolean('featured_only')) {
            $query->where('is_featured', true);
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('source_id')) {
            $query->where('source_id', $request->source_id);
        }

        $limit = min($request->integer('limit', 20), 100);
        $articles = $query->limit($limit)->get()->map(fn ($a) => $this->format($a));

        return response()->json(['success' => true, 'data' => $articles]);
    }

    public function publicShow($slug)
    {
        $article = NewsArticle::with(['category', 'source'])
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        // Increment views (best-effort)
        $article->increment('views_count');

        return response()->json(['success' => true, 'data' => $this->format($article, true)]);
    }

    // ─── ADMIN ──────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = NewsArticle::with(['category', 'source', 'creator'])
            ->orderByDesc('created_at');
        if ($request->filled('q')) {
            $query->where('title', 'like', '%' . $request->q . '%');
        }
        $page = $query->paginate($request->integer('per_page', 20));

        $page->getCollection()->transform(fn ($a) => $this->format($a, true));
        return response()->json(['success' => true, 'data' => $page]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $admin = $request->user('admin');
        $data = $validator->validated();
        $data['slug'] = NewsArticle::generateUniqueSlug($data['title']);
        $data['created_by'] = $admin?->id;
        if (($data['is_published'] ?? true) && empty($data['published_at'])) {
            $data['published_at'] = now();
        }
        $article = NewsArticle::create($data);
        return response()->json(['success' => true, 'data' => $this->format($article->load(['category', 'source']), true)], 201);
    }

    public function show($id)
    {
        $article = NewsArticle::with(['category', 'source', 'creator'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $this->format($article, true)]);
    }

    public function update(Request $request, $id)
    {
        $article = NewsArticle::findOrFail($id);
        $validator = Validator::make($request->all(), $this->rules(true));
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        if (isset($data['title']) && $data['title'] !== $article->title) {
            $data['slug'] = NewsArticle::generateUniqueSlug($data['title']);
        }
        $article->update($data);
        return response()->json(['success' => true, 'data' => $this->format($article->fresh(['category', 'source']), true)]);
    }

    public function destroy($id)
    {
        NewsArticle::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function rules(bool $patch = false): array
    {
        $req = $patch ? 'sometimes|' : 'required|';
        return [
            'title' => $req . 'string|max:255',
            'summary' => 'nullable|string',
            'content' => 'nullable|string',
            'thumbnail' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'source_id' => 'nullable|exists:news_sources,id',
            'audio_url' => 'nullable|string',
            'read_time' => 'nullable|string|max:50',
            'is_featured' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'published_at' => 'nullable|date',
        ];
    }

    private function format(NewsArticle $a, bool $full = false): array
    {
        $out = [
            'id' => $a->id,
            'title' => $a->title,
            'slug' => $a->slug,
            'summary' => $a->summary,
            'thumbnail' => $a->thumbnail,
            'read_time' => $a->read_time,
            'is_featured' => $a->is_featured,
            'is_published' => $a->is_published,
            'published_at' => $a->published_at?->format('Y-m-d H:i'),
            'views_count' => $a->views_count,
            'category' => $a->category ? [
                'id' => $a->category->id,
                'name' => $a->category->name,
                'color' => $a->category->color,
                'icon' => $a->category->icon,
            ] : null,
            'source' => $a->source ? [
                'id' => $a->source->id,
                'name' => $a->source->name,
                'avatar' => $a->source->avatar,
            ] : null,
        ];
        if ($full) {
            $out['content'] = $a->content;
            $out['audio_url'] = $a->audio_url;
            $out['created_by'] = $a->creator?->name;
            $out['created_at'] = $a->created_at?->format('Y-m-d H:i');
        }
        return $out;
    }
}
