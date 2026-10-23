<?php

use App\Support\DatabaseTriggerAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Article migrations support SQLite, MySQL 8 and verified MariaDB 10.6.28+.');
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            $maria = str_contains($version, 'MariaDB');
            preg_match('/(?:5\.5\.5-)?(\d+\.\d+\.\d+)/', $version, $match);
            $number = $match[1] ?? '0.0.0';
            if ($maria && (version_compare($number, '10.6.28', '<') || version_compare($number, '10.7.0', '>='))) {
                throw new RuntimeException('Only verified MariaDB 10.6.28+ in the 10.6 series is supported.');
            }
            if ($maria) {
                $settings = DB::selectOne('SELECT @@session.explicit_defaults_for_timestamp AS explicit_dates, @@session.check_constraint_checks AS checks_enabled, @@session.foreign_key_checks AS foreign_keys, @@default_storage_engine AS engine');
                if (! $settings->explicit_dates || ! $settings->checks_enabled || ! $settings->foreign_keys || strtoupper($settings->engine) !== 'INNODB') {
                    throw new RuntimeException('MariaDB requires explicit timestamps, CHECK/FK enforcement and InnoDB. Use the mariadb connection; never disable invariants.');
                }
            }
            if (! $maria && version_compare($number, '8.0.16', '<')) {
                throw new RuntimeException('Article migrations require MySQL 8.0.16+.');
            }
            DatabaseTriggerAccess::verify(['article_versions']);
        }
        Schema::create('article_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('slug', 120)->unique();
            $table->unsignedBigInteger('draft_version_id')->nullable();
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'id']);
        });
        Schema::create('article_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('article_categories')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->string('title', 180);
            $table->string('summary', 500);
            $table->text('source');
            $table->json('blocks');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['article_id', 'revision']);
            $table->unique(['article_id', 'id']);
        });
        // Composite foreign keys prevent pointers to another article's private content.
        Schema::table('articles', function (Blueprint $table): void {
            $table->foreign(['id', 'draft_version_id'], 'articles_draft_owner_fk')->references(['article_id', 'id'])->on('article_versions')->restrictOnDelete();
            $table->foreign(['id', 'published_version_id'], 'articles_published_owner_fk')->references(['article_id', 'id'])->on('article_versions')->restrictOnDelete();
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $body = DB::getDriverName() === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Article versions are immutable'); END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Article versions are immutable'";
            DB::unprepared("CREATE TRIGGER article_versions_no_{$event} BEFORE {$event} ON article_versions FOR EACH ROW {$body}");
        }
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['id', 'draft_version_id'] : 'articles_draft_owner_fk');
            $table->dropForeign(DB::getDriverName() === 'sqlite' ? ['id', 'published_version_id'] : 'articles_published_owner_fk');
        });
        Schema::dropIfExists('article_versions');
        Schema::dropIfExists('articles');
        Schema::dropIfExists('article_categories');
    }
};
