<?php

namespace Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductionRehearsalProjectionTest extends TestCase
{
    public function test_adding_columns_does_not_hide_changes_to_any_original_row_value(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-rehearsal.php';
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE retained (id INTEGER PRIMARY KEY, amount INTEGER, nullable TEXT)');
        $db->exec("INSERT INTO retained VALUES (1,300000,NULL),(2,200000,'synthetic')");
        $before = pr13ProjectedRowsDigest($db, 'retained', ['id', 'amount', 'nullable'], ['id']);
        $db->exec('ALTER TABLE retained ADD COLUMN new_column TEXT');
        $this->assertSame($before, pr13ProjectedRowsDigest($db, 'retained', ['id', 'amount', 'nullable'], ['id']));
        $db->exec('UPDATE retained SET amount=300001 WHERE id=1');
        $this->assertNotSame($before, pr13ProjectedRowsDigest($db, 'retained', ['id', 'amount', 'nullable'], ['id']));
    }

    public function test_migration_ceiling_retains_original_history_but_detects_modified_batches(): void
    {
        require_once dirname(__DIR__, 2).'/scripts/release/pr13-rehearsal.php';
        $db = new PDO('sqlite::memory:', options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT, batch INTEGER)');
        $db->exec("INSERT INTO migrations VALUES (1,'original',1)");
        $before = pr13ProjectedRowsDigest($db, 'migrations', ['id', 'migration', 'batch'], ['id'], 1);
        $db->exec("INSERT INTO migrations VALUES (2,'extension',2)");
        $this->assertSame($before, pr13ProjectedRowsDigest($db, 'migrations', ['id', 'migration', 'batch'], ['id'], 1));
        $db->exec('UPDATE migrations SET batch=2 WHERE id=1');
        $this->assertNotSame($before, pr13ProjectedRowsDigest($db, 'migrations', ['id', 'migration', 'batch'], ['id'], 1));
        $this->expectException(RuntimeException::class);
        pr13ProjectedRowsDigest($db, 'migrations; DROP TABLE migrations', ['id'], ['id']);
    }
}
