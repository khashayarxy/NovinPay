<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleVersion;
use App\Support\FaNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        return $this->listing($request);
    }

    public function category(Request $request, string $slug): View
    {
        return $this->listing($request, ArticleCategory::where('is_active', true)->where('slug', $slug)->firstOrFail());
    }

    private function listing(Request $request, ?ArticleCategory $category = null): View
    {
        $page = $request->query('page', '1');
        abort_unless(is_string($page) && preg_match('/\A[1-9][0-9]{0,5}\z/', $page) && (int) $page <= 100000, 404);
        $path = $category ? route('articles.category', ['slug' => $category->slug], false) : route('articles.index', [], false);
        $url = rtrim(config('app.url'), '/').$path;
        // Never load the draft relation, historical revisions, source or author identities.
        $articles = Article::published()->select('id', 'slug', 'published_version_id', 'published_at')
            ->when($category, fn (Builder $query) => $query->whereHas('publishedVersion', fn (Builder $version) => $version->where('category_id', $category->id)))
            ->with(['publishedVersion:id,article_id,category_id,title,summary', 'publishedVersion.category:id,name,slug'])
            ->orderByDesc('published_at')->orderByDesc('id')->paginate(12, ['*'], 'page', (int) $page)->withPath($url);
        abort_if((int) $page > 1 && $articles->isEmpty(), 404);
        $categories = ArticleCategory::where('is_active', true)->whereIn('id', ArticleVersion::select('category_id')
            ->whereIn('id', Article::published()->select('published_version_id')))->orderBy('name')->limit(30)->get(['id', 'name', 'slug']);
        $title = $category ? 'مقاله‌های '.$category->name : 'مقاله‌ها و راهنمای کارتخوان';
        if ((int) $page > 1) {
            $title .= ' — صفحهٔ '.FaNumber::digits($page);
        }
        $contentSeo = [
            'title' => $title,
            'description' => $category ? 'مقاله‌ها و راهنماهای منتشرشدهٔ نوین‌پی در دستهٔ '.$category->name : 'راهنماها و مقاله‌های نوین‌پی دربارهٔ انتخاب، استفاده و نگهداری دستگاه کارتخوان.',
            'canonical' => $url.((int) $page > 1 ? '?page='.(int) $page : ''),
            'robots' => $articles->isNotEmpty() && ! array_diff(array_keys($request->query()), ['page']) ? 'index, follow' : 'noindex, follow',
        ];
        $request->attributes->set('public_article_robots', $contentSeo['robots']);

        return view('articles.index', compact('articles', 'category', 'categories', 'contentSeo'));
    }

    public function show(Request $request, string $slug): View
    {
        $article = Article::published()->select('id', 'slug', 'published_version_id', 'published_at')->where('slug', $slug)
            ->with(['publishedVersion:id,article_id,category_id,title,summary,blocks', 'publishedVersion.category:id,name,slug'])->firstOrFail();
        $version = $article->publishedVersion;
        $contentSeo = [
            'title' => $version->title,
            'description' => $version->summary,
            'canonical' => rtrim(config('app.url'), '/').route('articles.show', ['slug' => $article->slug], false),
            'robots' => $request->query() ? 'noindex, follow' : 'index, follow',
        ];
        $request->attributes->set('public_article_robots', $contentSeo['robots']);

        return view('articles.show', compact('article', 'version', 'contentSeo'));
    }
}
