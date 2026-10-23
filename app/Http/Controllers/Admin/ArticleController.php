<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArticleRequest;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Services\ArticlePublishing;
use App\Support\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ArticleController extends Controller
{
    private function screen(string $name, array $data)
    {
        $account = AdminAccess::account(auth()->user());
        $articleAllows = fn (string $permission): bool => $account && ($account->isSuperAdmin() || in_array($permission, $account->permissions ?? [], true));

        return view($name, $data + ['articleAllows' => $articleAllows]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['draft', 'published', 'changed'])], 'category' => ['nullable', 'integer', 'min:1']]);
        $term = trim($filters['q'] ?? '');
        $articles = Article::query()->with(['draftVersion' => fn ($query) => $query->select('id', 'article_id', 'category_id', 'title', 'revision')->with('category:id,name')])
            ->when($term !== '', function ($q) use ($term) {
                $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                $q->whereHas('draftVersion', fn ($v) => $v->whereRaw("title LIKE ? ESCAPE '!'", [$literal]));
            })
            ->when(($filters['status'] ?? '') === 'draft', fn ($q) => $q->whereNull('published_version_id'))
            ->when(($filters['status'] ?? '') === 'published', fn ($q) => $q->whereNotNull('published_version_id'))
            ->when(($filters['status'] ?? '') === 'changed', fn ($q) => $q->whereNotNull('published_version_id')->whereColumn('draft_version_id', '<>', 'published_version_id'))
            ->when(! empty($filters['category']), fn ($q) => $q->whereHas('draftVersion', fn ($v) => $v->where('category_id', $filters['category'])))
            ->latest('id')->paginate(20)->appends($filters);

        return $this->screen('admin.articles.index', compact('articles', 'filters') + ['categories' => ArticleCategory::orderBy('name')->get(['id', 'name'])]);
    }

    public function create()
    {
        return $this->form();
    }

    public function edit(Article $article)
    {
        return $this->form($article);
    }

    private function form(?Article $article = null)
    {
        $article?->load('draftVersion');

        return $this->screen('admin.articles.form', ['article' => $article, 'draft' => $article?->draftVersion, 'categories' => ArticleCategory::where('is_active', true)->orderBy('name')->get(['id', 'name'])]);
    }

    public function preview(Request $request, Article $article)
    {
        $data = $request->validate(['mode' => ['nullable', Rule::in(['draft', 'published'])]]);
        $mode = $data['mode'] ?? 'draft';
        $article->load($mode === 'published' ? 'publishedVersion.category' : 'draftVersion.category');
        $version = $mode === 'published' ? $article->publishedVersion : $article->draftVersion;
        abort_unless($version, 404);

        return $this->screen('admin.articles.preview', compact('article', 'version', 'mode'));
    }

    public function store(ArticleRequest $request, ArticlePublishing $publishing, ?Article $article = null)
    {
        try {
            $id = $publishing->execute($request->user(), $request->operation(), $request->validated(), $article?->id);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return redirect()->back()->withErrors($exception->errors());
        }

        return redirect()->route('admin.articles.preview', Article::findOrFail($id))->with('success', 'عملیات محتوا ثبت شد؛ فقط نسخهٔ منتشرشده در وب‌سایت عمومی نمایش داده می‌شود.');
    }
}
