<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ArticleRequest;
use App\Models\ArticleCategory;
use App\Services\ArticlePublishing;

final class ArticleCategoryController extends Controller
{
    public function index()
    {
        return view('admin.article-categories.index', ['categories' => ArticleCategory::latest('id')->paginate(20)]);
    }

    public function create()
    {
        return view('admin.article-categories.form', ['category' => null]);
    }

    public function edit(ArticleCategory $category)
    {
        return view('admin.article-categories.form', compact('category'));
    }

    public function store(ArticleRequest $request, ArticlePublishing $publishing, ?ArticleCategory $category = null)
    {
        $publishing->execute($request->user(), $request->operation(), $request->validated(), $category?->id);

        return redirect()->route('admin.article-categories.index')->with('success', 'دسته با کنترل نسخه ذخیره شد.');
    }
}
