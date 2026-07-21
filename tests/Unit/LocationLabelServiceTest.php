<?php

namespace Tests\Unit;

use App\Models\LocationLabel;
use App\Services\LocationLabelPrintService;
use App\Services\LocationLabelService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocationLabelServiceTest extends TestCase
{
    /** @var LocationLabelService */
    private $service;

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

        $this->service = app(LocationLabelService::class);
    }

    public function test_paginate_search_and_exclude_deleted(): void
    {
        LocationLabel::query()->create([
            'location' => 'A-01-001',
            'type' => 'Front Bin',
            'is_deleted' => false,
        ]);
        LocationLabel::query()->create([
            'location' => 'B-02-010',
            'type' => 'Back Bin',
            'is_deleted' => false,
        ]);
        LocationLabel::query()->create([
            'location' => 'Z-GONE',
            'type' => 'Deleted',
            'is_deleted' => true,
        ]);

        $page = $this->service->paginate([
            'q' => 'Back',
            'per_page' => 25,
            'sort_by' => 'barcode',
            'sort_dir' => 'asc',
        ]);

        $this->assertSame(1, $page->total());
        $this->assertSame('B-02-010', $page->items()[0]->location);
    }

    public function test_create_rejects_duplicate_active_barcode(): void
    {
        $this->service->create([
            'barcode' => 'DUP-1',
            'display_name' => 'One',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->create([
            'barcode' => 'DUP-1',
            'display_name' => 'Two',
        ]);
    }

    public function test_soft_delete_and_csv_import(): void
    {
        $row = $this->service->create([
            'barcode' => 'KEEP-1',
            'display_name' => 'Keep',
        ]);
        $this->service->softDelete($row);

        // Soft-deleted barcodes are treated as available and can be re-imported.
        $csv = "Barcode,Display Name\nKEEP-1,Restored Name\nNEW-9,Imported Name\n,Bad Row\n";
        $file = UploadedFile::fake()->createWithContent('locations.csv', $csv);
        $summary = $this->service->importCsv($file);

        $this->assertSame(2, $summary['imported']);
        $this->assertGreaterThanOrEqual(1, $summary['skipped']);
        $this->assertDatabaseHas('beta_locations', [
            'location' => 'NEW-9',
            'type' => 'Imported Name',
            'is_deleted' => 0,
        ]);
        $this->assertDatabaseHas('beta_locations', [
            'location' => 'KEEP-1',
            'type' => 'Restored Name',
            'is_deleted' => 0,
        ]);
    }

    public function test_print_service_streams_pdf_bytes(): void
    {
        $row = $this->service->create([
            'barcode' => 'PRINT-1',
            'display_name' => 'Print Me',
        ]);

        $response = app(LocationLabelPrintService::class)->stream([$row], 'large');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $small = app(LocationLabelPrintService::class)->stream([$row], 'small');
        $this->assertStringStartsWith('%PDF', $small->getContent());
    }
}
