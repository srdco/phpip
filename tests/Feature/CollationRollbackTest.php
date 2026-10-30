<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CollationRollbackTest extends TestCase
{
    public function test_rollback_converts_related_columns_and_preserves_foreign_keys_and_data(): void
    {
        $parent = 'collation_rollback_parent';
        $child = 'collation_rollback_child';
        $manager = DB::getFacadeRoot();
        $database = DB::getDatabaseName();
        $original = DB::selectOne('SELECT DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$database]);

        try {
            Schema::create($parent, function (Blueprint $table) {
                $table->string('region', 2)->collation('utf8mb4_0900_ai_ci');
                $table->string('code', 10)->collation('utf8mb4_0900_ai_ci')->unique();
                $table->primary(['region', 'code']);
            });
            Schema::create($child, function (Blueprint $table) use ($parent) {
                $table->id();
                $table->string('region', 2)->collation('utf8mb4_0900_ai_ci');
                $table->string('code', 10)->collation('utf8mb4_0900_ai_ci');
                $table->string('alternate_code', 10)->nullable()->collation('utf8mb4_0900_ai_ci');
                $table->foreign(['region', 'code'], 'rollback_composite_fk')
                    ->references(['region', 'code'])->on($parent)->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreign('alternate_code', 'rollback_nullable_fk')
                    ->references('code')->on($parent)->cascadeOnUpdate()->nullOnDelete();
            });
            DB::table($parent)->insert(['region' => 'FR', 'code' => 'original']);
            DB::table($child)->insert(['region' => 'FR', 'code' => 'original', 'alternate_code' => 'original']);
            $keys = Schema::getForeignKeys($child);

            // Limit table discovery to real fixture tables so this migration test
            // cannot convert application tables or interfere with the other tests.
            // All SQL conversions and constraint inspection/recreation remain real.
            $proxy = Mockery::mock($manager);
            $proxy->shouldReceive('select')->andReturnUsing(function ($query, ...$arguments) use ($manager, $parent, $child) {
                if (str_contains($query, 'information_schema.TABLES')) {
                    return [(object) ['TABLE_NAME' => $parent], (object) ['TABLE_NAME' => $child]];
                }

                return $manager->select($query, ...$arguments);
            });
            DB::swap($proxy);

            $migration = require database_path('migrations/2025_10_17_175605_convert_database_to_utf8mb4_0900_ai_ci.php');
            $migration->down();
            DB::swap($manager);

            $this->assertSame($keys, Schema::getForeignKeys($child));
            $columns = DB::select('SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN (?, ?) AND COLLATION_NAME IS NOT NULL', [$database, $parent, $child]);
            $this->assertCount(5, $columns);
            foreach ($columns as $column) {
                $this->assertSame('utf8mb4_unicode_ci', $column->COLLATION_NAME);
            }
            $this->assertSame('original', DB::table($child)->value('code'));

            DB::table($parent)->where('code', 'original')->update(['code' => 'updated']);
            $this->assertSame('updated', DB::table($child)->value('code'));
            $this->assertSame('updated', DB::table($child)->value('alternate_code'));

            try {
                DB::table($child)->insert(['region' => 'FR', 'code' => 'missing']);
                $this->fail('The restored foreign key must reject orphaned rows.');
            } catch (QueryException $exception) {
                $this->assertSame(1452, $exception->errorInfo[1]);
            }
        } finally {
            DB::swap($manager);
            Schema::dropIfExists($child);
            Schema::dropIfExists($parent);
            $quotedDatabase = DB::connection()->getQueryGrammar()->wrap($database);
            DB::statement('ALTER DATABASE '.$quotedDatabase.' CHARACTER SET utf8mb4 COLLATE '.$original->collation);
        }
    }
}
