<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Article migrations support SQLite and MySQL 8 only.');
        }
        if (DB::getDriverName() === 'mysql') {
            $version = DB::selectOne('SELECT VERSION() AS version')->version;
            if (str_contains($version, 'MariaDB') || version_compare($version, '8.0.16', '<')) {
                throw new RuntimeException('Article migrations require MySQL 8.0.16+.');
            }
            $policy = DB::selectOne('SELECT @@global.log_bin AS logging, @@global.log_bin_trust_function_creators AS trusted');
            $grants = DB::select('SHOW GRANTS FOR CURRENT_USER');
            $database = DB::connection()->getDatabaseName();
            $trigger = false;
            $super = false;
            foreach ($grants as $row) {
                $grant = array_values((array) $row)[0];
                if (! preg_match('/\AGRANT (.+?) ON (.+?) TO /', $grant, $match)) {
                    continue;
                }
                $privileges = array_map('trim', explode(',', $match[1]));
                // SHOW GRANTS may escape schema wildcard characters. Role-only or
                // wildcard schema grants are deliberately not inferred as authority.
                $scope = str_replace(['\\_', '\\%'], ['_', '%'], $match[2]);
                $schema = '`'.str_replace('`', '``', $database).'`';
                $global = $scope === '*.*';
                $all = in_array('ALL PRIVILEGES', $privileges, true);
                $super = $super || ($global && ($all || in_array('SUPER', $privileges, true)));
                if ($global || in_array($scope, [$schema.'.*', $schema.'.`article_versions`'], true)) {
                    $trigger = $trigger || $all || in_array('TRIGGER', $privileges, true);
                }
            }
            if (! $trigger) {
                throw new RuntimeException('Article history requires a verifiable direct TRIGGER grant on the target schema/table; request a deployment-account/provider preflight.');
            }
            if ($policy->logging && ! $policy->trusted && ! $super) {
                throw new RuntimeException('Article history triggers are restricted by server logging policy; request a deployment-account/provider preflight.');
            }
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
