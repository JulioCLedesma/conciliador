<?php

namespace Tests\Feature;

use App\Livewire\Reconciliation\ImportCatalog;
use App\Models\User;
use App\Services\CatalogWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\HeaderFooterDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class CatalogVmlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function fixture(): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('PARTIDA 4');
        $sheet->setCellValue('A1', 'Referencia')->setCellValue('B1', 'Descripción')->setCellValue('C1', 'Fecha');
        $sheet->setCellValueExplicit('A2', '000123', DataType::TYPE_STRING)->setCellValue('B2', 'Texto');
        $sheet->setCellValue('A3', 123)->setCellValue('B3', 'Número');
        $sheet->getStyle('A3')->getNumberFormat()->setFormatCode('000000');
        $sheet->setCellValue('C2', 46000);
        $sheet->getStyle('C2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $imagePath = Storage::disk('local')->path('logo.png');
        file_put_contents($imagePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='));
        foreach (['LH', 'RH'] as $location) {
            $drawing = new HeaderFooterDrawing;
            $drawing->setPath($imagePath);
            $sheet->getHeaderFooter()->addImage($drawing, $location);
        }
        $sheet->getHeaderFooter()->setOddHeader('&L&G&R&G');
        $path = Storage::disk('local')->path('vml.xlsx');
        (new XlsxWriter($book))->save($path);
        $book->disconnectWorksheets();
        $zip = new \ZipArchive;
        $zip->open($path);
        foreach (range(0, $zip->numFiles - 1) as $index) {
            $name = $zip->getNameIndex($index);
            if (str_ends_with($name, '.vml')) {
                $xml = $zip->getFromName($name);
                $xml = preg_replace('/margin-top:[^;\"]*/', 'top:0pt', $xml);
                $xml = preg_replace('/margin-left:[^;\"]*/', 'left:0pt', $xml);
                $zip->addFromString($name, $xml);
            }
        }
        $zip->close();

        return $path;
    }

    public function test_stock_reader_reproduces_margin_top_error_even_with_charts_disabled(): void
    {
        $reader = new Xlsx;
        $reader->setIncludeCharts(false);
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Undefined array key "margin-top"');
        $reader->load($this->fixture());
    }

    public function test_service_reads_vml_fixture_without_changing_original_or_losing_zero_padding_and_dates(): void
    {
        $path = $this->fixture();
        $hash = hash_file('sha256', $path);
        $files = Storage::disk('local')->allFiles();
        $service = app(CatalogWorkbook::class);
        $this->assertSame(['PARTIDA 4'], $service->sheets($path));
        $data = $service->read($path, 'PARTIDA 4', 1);
        $this->assertSame('000123', $data['rows'][2][0]);
        $this->assertSame('000123', $data['rows'][3][0]);
        $this->assertSame('2025-12-09', $data['rows'][2][2]);
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($hash, hash_file('sha256', $path));
    }

    public function test_local_uploaded_pemex_reproduces_stock_error_and_is_read_without_modification(): void
    {
        $path = getenv('CATALOG_VML_SAMPLE');
        if (! $path || ! is_file($path)) {
            $this->markTestSkipped('Archivo local opcional; la regresión permanente usa una muestra sintética.');
        }
        $hash = hash_file('sha256', $path);
        $reader = new Xlsx;
        $reader->setLoadSheetsOnly(['PARTIDA 4']);
        try {
            $reader->load($path);
            $this->fail('El lector original debe reproducir el fallo del archivo local.');
        } catch (\ErrorException $e) {
            $this->assertStringContainsString('Undefined array key "margin-top"', $e->getMessage());
        }
        $data = app(CatalogWorkbook::class)->read($path, 'PARTIDA 4', 1);
        $this->assertNotEmpty($data['rows']);
        $this->assertSame($hash, hash_file('sha256', $path));
    }

    public function test_data_only_reading_would_lose_numeric_reference_and_date_formats(): void
    {
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $book = $reader->load($this->fixture());
        try {
            $sheet = $book->getActiveSheet();
            $this->assertSame('000123', $sheet->getCell('A2')->getValue());
            $this->assertSame('123', $sheet->getCell('A3')->getFormattedValue());
            $this->assertSame('46000', $sheet->getCell('C2')->getFormattedValue());
            $this->assertSame('General', $sheet->getStyle('A3')->getNumberFormat()->getFormatCode());
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function adminSession(): int
    {
        $admin = User::factory()->create();
        $admin->role = 'admin';
        $admin->save();
        $this->actingAs($admin);

        return DB::table('reconciliation_sessions')->insertGetId(['name' => 'Regresión VML', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_preview_and_import_share_corrected_reader_and_keep_original_archive(): void
    {
        $sessionId = $this->adminSession();
        $path = $this->fixture();
        $contents = file_get_contents($path);
        $upload = UploadedFile::fake()->createWithContent('vml.xlsx', $contents);
        Livewire::test(ImportCatalog::class, ['sessionId' => $sessionId])->set('file', $upload)
            ->assertHasNoErrors()->assertSet('preview.2.0', '000123')->assertSet('preview.3.0', '000123')
            ->assertSet('preview.2.2', '2025-12-09')->set('reference', '0')->set('descriptions', ['1'])
            ->call('review')->assertHasNoErrors()->call('import')->assertHasNoErrors();
        $rows = DB::table('catalog_rows')->orderBy('original_row')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('000123', $rows[0]->reference);
        $this->assertSame('000123', $rows[1]->reference);
        $catalog = DB::table('catalogs')->where('session_id', $sessionId)->first();
        $this->assertSame(hash('sha256', $contents), hash_file('sha256', Storage::disk('local')->path($catalog->path)));
    }

    private function corruptWorksheet(): string
    {
        $path = $this->fixture();
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet broken>');
        $zip->close();

        return $path;
    }

    public function test_unreadable_worksheet_returns_validation_and_preserves_source(): void
    {
        $path = $this->corruptWorksheet();
        $hash = hash_file('sha256', $path);
        try {
            app(CatalogWorkbook::class)->read($path, 'PARTIDA 4', 1);
            $this->fail('Debe mostrar una validación para un archivo dañado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
            $this->assertStringContainsString('No se pudo leer el archivo Excel', $e->errors()['file'][0]);
        }
        $this->assertSame($hash, hash_file('sha256', $path));
    }

    public function test_uploading_unreadable_worksheet_shows_validation_in_livewire(): void
    {
        $sessionId = $this->adminSession();
        $path = $this->corruptWorksheet();
        $upload = UploadedFile::fake()->createWithContent('dañado.xlsx', file_get_contents($path));
        Livewire::test(ImportCatalog::class, ['sessionId' => $sessionId])->set('file', $upload)
            ->assertHasErrors('file')->assertSee('No se pudo leer el archivo Excel');
        $this->assertDatabaseCount('catalogs', 0);
        $this->assertDatabaseCount('catalog_rows', 0);
    }
}
