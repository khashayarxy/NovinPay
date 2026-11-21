<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;

final class SitemapController extends Controller
{
    private const PAGE_SIZE = 1000;

    public function index(): Response
    {
        $urls = [$this->absolute(route('sitemap.part', ['type' => 'pages', 'page' => 1], false))];
        foreach (['products', 'articles'] as $type) {
            $pages = (int) ceil($this->query($type)->count() / self::PAGE_SIZE);
            abort_if(count($urls) + $pages > 50000, 503);
            for ($page = 1; $page <= $pages; $page++) {
                $urls[] = $this->absolute(route('sitemap.part', compact('type', 'page'), false));
            }
        }

        return $this->xml($urls, true);
    }

    public function part(string $type, int $page): Response
    {
        if ($type === 'pages') {
            abort_unless($page === 1, 404);
            $paths = ['/', '/products', '/contact'];
            if (Article::published()->exists()) {
                $paths[] = '/articles';
            }

            return $this->xml(array_map($this->absolute(...), $paths));
        }
        $query = $this->query($type);
        abort_unless($page >= 1 && $page <= (int) ceil($query->count() / self::PAGE_SIZE), 404);
        $parameter = $type === 'products' ? 'product' : 'slug';
        $urls = $query->orderBy('id')->forPage($page, self::PAGE_SIZE)->get(['id', 'slug'])
            ->map(fn ($record) => $this->absolute(route($type.'.show', [$parameter => $record->slug], false)))->all();

        return $this->xml($urls);
    }

    private function query(string $type): Builder
    {
        return match ($type) {
            'products' => Product::published()->where('is_sample', false),
            'articles' => Article::published(),
            default => abort(404),
        };
    }

    private function absolute(string $path): string
    {
        return rtrim(config('app.url'), '/').$path;
    }

    private function xml(array $urls, bool $index = false): Response
    {
        return response()->view('sitemap', compact('urls', 'index'), 200,
            ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-store, max-age=0']);
    }
}
