<?php

use App\Support\SearchNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Recompute every products.search_text value after SearchNormalizer
     * gained ZWNJ removal and whitespace collapsing. Reads the display
     * columns (not the stored haystack) so the result is identical to a
     * fresh save; safe to re-run.
     */
    public function up(): void
    {
        DB::table('products')
            ->select('id', 'name', 'summary', 'description')
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
        // Data-only recompute; nothing to reverse.
    }
};
