<?php

namespace Tests\Unit;

use App\Services\LocationLabelSqlImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationLabelSqlImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::connection('sqlite')->dropIfExists('beta_locations');
        Schema::connection('sqlite')->create('beta_locations', function (Blueprint $table) {
            $table->id();
            $table->string('location')->nullable();
            $table->string('type')->nullable();
            $table->string('label')->nullable();
            $table->boolean('is_deleted')->nullable()->default(false);
            $table->timestamps();
        });
    }

    public function test_importer_parses_phpmyadmin_insert_rows(): void
    {
        $path = storage_path('framework/testing/beta_locations_unit.sql');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, <<<'SQL'
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
INSERT INTO `beta_locations` (`id`, `location`, `type`, `label`, `is_deleted`, `created_at`, `updated_at`) VALUES
(1, 'A-01-001', 'Aisle One', NULL, 0, '2025-12-18 13:37:18', '2025-12-18 13:37:18'),
(2, 'O''Brien', 'Quote Test', NULL, 1, '2025-12-18 13:37:18', '2025-12-18 13:37:18');
SQL);

        $result = app(LocationLabelSqlImporter::class)->importFromDump($path);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(2, DB::table('beta_locations')->count());
        $this->assertDatabaseHas('beta_locations', [
            'id' => 1,
            'location' => 'A-01-001',
            'type' => 'Aisle One',
            'is_deleted' => 0,
        ]);
        $this->assertDatabaseHas('beta_locations', [
            'id' => 2,
            'location' => "O'Brien",
            'is_deleted' => 1,
        ]);

        @unlink($path);
    }
}
