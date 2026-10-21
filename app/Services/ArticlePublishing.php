<?php

namespace App\Services;

use App\Enums\AdminAction;
use App\Models\AdminAccount;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Support\AdminHistory;
use App\Support\ArticleContent;
use App\Support\ArticleInput;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class ArticlePublishing
{
    public function execute(User $actor, string $action, array $input, ?int $id = null): int
    {
        Gate::forUser($actor)->authorize(ArticleInput::permission($action));
        if (! str_starts_with($action, 'category.')) {
            Gate::forUser($actor)->authorize('articles.view');
        }
        $data = ArticleInput::validate($action, $input);
        $blocks = in_array($action, ['create', 'save'], true) ? ArticleContent::parse($data['content']) : null;
        try {
            return DB::transaction(function () use ($actor, $action, $data, $blocks, $id): int {
                // Serialize with grant revocation; reauthorize persisted access after the lock.
                $account = AdminAccount::where('user_id', $actor->id)->lockForUpdate()->first();
                abort_unless($account && $account->is_active && ! $account->must_change_password, 403);
                $fresh = User::findOrFail($actor->id);
                Gate::forUser($fresh)->authorize(ArticleInput::permission($action));
                if (! str_starts_with($action, 'category.')) {
                    Gate::forUser($fresh)->authorize('articles.view');
                }
                if (str_starts_with($action, 'category.')) {
                    $category = $action === 'category.create' ? new ArticleCategory : ArticleCategory::lockForUpdate()->findOrFail($id);
                    if ($category->exists) {
                        $this->version($category->version, $data['version']);
                    }
                    if (! $data['is_active'] && $category->exists) {
                        $used = DB::table('articles')->join('article_versions', 'article_versions.id', '=', 'articles.published_version_id')->where('article_versions.category_id', $category->id)->lockForUpdate()->first(['articles.id']);
                        abort_if($used, 409, 'ابتدا انتشار مقاله‌های این دسته را لغو کنید.');
                    }
                    $category->name = $data['name'];
                    $category->slug = $data['slug'];
                    $category->is_active = $data['is_active'];
                    $category->version = $category->exists ? $category->version + 1 : 1;
                    $category->save();
                    AdminHistory::record(AdminAction::ArticleCategoryChanged, 'article_category', $category->id, $actor->id);
                    event('articles.after-write', [$action, $category->id]);

                    return $category->id;
                }
                $article = $action === 'create' ? new Article : Article::lockForUpdate()->findOrFail($id);
                if ($article->exists) {
                    $this->version($article->version, $data['version']);
                }
                if (in_array($action, ['create', 'save'], true)) {
                    $category = ArticleCategory::lockForUpdate()->findOrFail($data['category_id']);
                    abort_unless($category->is_active, 422, 'دستهٔ فعال انتخاب کنید.');
                    if (! $article->exists) {
                        $article->public_id = (string) Str::uuid();
                        $article->slug = $data['slug'];
                        $article->version = 1;
                        $article->save();
                    }
                    $revision = ($article->draftVersion?->revision ?? 0) + 1;
                    $draft = new ArticleVersion;
                    foreach (['article_id' => $article->id, 'category_id' => $category->id, 'revision' => $revision, 'title' => $data['title'], 'summary' => $data['summary'], 'source' => $data['content'], 'blocks' => $blocks, 'created_by' => $actor->id, 'created_at' => now()] as $key => $value) {
                        $draft->$key = $value;
                    }
                    $draft->save();
                    $this->advance($article, ['draft_version_id' => $draft->id]);
                    AdminHistory::record(AdminAction::ArticleDraftSaved, 'article_version', $draft->id, $actor->id);
                } else {
                    $reviewed = $action === 'publish' ? $article->draft_version_id : $article->published_version_id;
                    abort_unless($reviewed && $reviewed === (int) $data['reviewed_version_id'], 409, 'نسخهٔ بازبینی‌شده تغییر کرده است؛ صفحه را تازه کنید.');
                    if ($action === 'publish') {
                        abort_if($article->published_version_id === $reviewed, 409, 'این نسخه قبلاً منتشر شده است.');
                        $draft = ArticleVersion::where('article_id', $article->id)->findOrFail($reviewed);
                        $category = ArticleCategory::lockForUpdate()->findOrFail($draft->category_id);
                        abort_unless($category->is_active, 409, 'دستهٔ پیش‌نویس غیرفعال است.');
                        $this->advance($article, ['published_version_id' => $draft->id, 'published_at' => now(), 'unpublished_at' => null]);
                    } else {
                        $this->advance($article, ['published_version_id' => null, 'published_at' => null, 'unpublished_at' => now()]);
                    }
                    AdminHistory::record($action === 'publish' ? AdminAction::ArticlePublished : AdminAction::ArticleUnpublished, 'article_version', $reviewed, $actor->id);
                }
                event('articles.after-write', [$action, $article->id]);

                return $article->id;
            }, 3);
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? '', ['23000', '23505', '40001'], true) || in_array($exception->errorInfo[1] ?? 0, [5, 6, 19, 1205, 1213], true)) {
                abort(409, 'نامک تکراری یا تغییر هم‌زمان است؛ صفحه را تازه کنید و دوباره بررسی کنید.');
            }
            throw $exception;
        }
    }

    private function version(int $actual, int|string $expected): void
    {
        abort_unless($actual === (int) $expected, 409, 'نسخه تغییر کرده است؛ صفحه را تازه کنید. اطلاعات جدید بازنویسی نشد.');
    }

    private function advance(Article $article, array $changes): void
    {
        $changed = DB::table('articles')->where('id', $article->id)->where('version', $article->version)->update($changes + ['version' => $article->version + 1, 'updated_at' => now()]);
        abort_unless($changed === 1, 409, 'تغییر هم‌زمان؛ صفحه را تازه کنید.');
    }
}
