<?php

use App\Support\SearchNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional: a run that fails after the column
        // is added leaves it in place without the migration row recorded.
        // Guard creation so `php artisan migrate` can be retried safely.
        if (! Schema::hasColumn('products', 'search_text')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->text('search_text')->nullable()->after('description');
            });
        }

        // Always run — even on retry — so rows left NULL by an incomplete
        // backfill are repaired, while rows already written are left
        // untouched (only NULL rows are updated). chunkById keeps a stable
        // id cursor as repaired rows leave the filtered set; an offset-based
        // chunk() would skip rows. Uses DB::table (not the model) so the
        // migration stays stable even if the Product model changes later.
        DB::table('products')
            ->select('id', 'name', 'summary', 'description')
            ->whereNull('search_text')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('products')
                        ->where('id', $row->id)
                        ->update([
                            'search_text' => SearchNormalizer::forProduct(
                                $row->name,
                                $row->summary,
                                $row->description,
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }
};
