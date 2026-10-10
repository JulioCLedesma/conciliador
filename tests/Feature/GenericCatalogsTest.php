<?php

namespace Tests\Feature;

use App\Livewire\Reconciliation\Board;
use App\Livewire\Reconciliation\ImportCatalog;
use App\Livewire\Reconciliation\Sessions;
use App\Models\User;
use App\Services\CatalogConfiguration;
use App\Services\CatalogWorkbook;
use App\Services\ReconciliationDecisions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class GenericCatalogsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->role = 'admin';
        $this->admin->save();
        $this->actingAs($this->admin);
    }

    private function createSession(string $left = 'HGO', string $right = 'Civil'): int
    {
        return DB::table('reconciliation_sessions')->insertGetId([
            'name' => 'Prueba '.$left.' vs '.$right, 'left_catalog_name' => $left, 'right_catalog_name' => $right,
            'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function file(string $name): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('General');
        $values = [
            ['ID '.mb_strtoupper($name), 'Descripción', 'Costo'],
            ['001', 'Producto A', '987654.32'],
            ['001', 'Producto B', '987654.32'],
            ['003', 'Producto C', '987654.32'],
        ];
        foreach ($values as $row => $cells) {
            foreach ($cells as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $row + 1], $value, DataType::TYPE_STRING);
            }
        }
        $book->createSheet()->setTitle('PARTIDA 4')->setCellValue('A1', 'Referencia')->setCellValue('A2', 'Otra');
        $path = 'examples/'.uniqid().'.xlsx';
        Storage::disk('local')->makeDirectory('examples');
        (new Xlsx($book))->save(Storage::disk('local')->path($path));
        $book->disconnectWorksheets();

        return $path;
    }

    private function import(int $sessionId, string $side, string $name, string $result, string $classification): array
    {
        $id = app(CatalogWorkbook::class)->import($sessionId, $side, $this->file($name), $name.'.xlsx', 'General', 1, 0, [1], [2], null, $result, $classification);

        return DB::table('catalog_rows')->where('catalog_id', $id)->orderBy('original_row')->get()->all();
    }

    public function test_creation_requires_names_and_stores_them_per_session_without_changing_existing_sessions(): void
    {
        $oldId = $this->createSession('Fifty', 'PEMEX');
        Livewire::test(Sessions::class)->set('name', 'Hospitales')->set('leftCatalogName', '')->set('rightCatalogName', '')->call('create')
            ->assertHasErrors(['leftCatalogName', 'rightCatalogName']);
        Livewire::test(Sessions::class)->set('name', 'Hospitales')->set('leftCatalogName', 'HGO')->set('rightCatalogName', 'Civil')->call('create')->assertHasNoErrors();
        $this->assertDatabaseHas('reconciliation_sessions', ['name' => 'Hospitales', 'left_catalog_name' => 'HGO', 'right_catalog_name' => 'Civil']);
        $this->assertDatabaseHas('reconciliation_sessions', ['id' => $oldId, 'left_catalog_name' => 'Fifty', 'right_catalog_name' => 'PEMEX']);
        $this->get(route('reconciliation.sessions'))->assertOk()->assertSee('HGO vs. Civil')->assertSee('Fifty vs. PEMEX');
    }

    public function test_import_proposes_generic_columns_and_preserves_edited_classification_and_result_name(): void
    {
        $sessionId = $this->createSession();
        $upload = UploadedFile::fake()->createWithContent('ejemplo.xlsx', Storage::disk('local')->get($this->file('HGO')));
        $component = Livewire::test(ImportCatalog::class, ['sessionId' => $sessionId])
            ->assertSet('resultName', 'ID CIVIL')->assertSet('classificationText', 'Producto sin equivalente en Civil')
            ->set('file', $upload)->assertSet('sheet', 'General')->assertSet('reference', '0')
            ->set('descriptions', ['1'])->set('sensitive', ['2'])->set('resultName', 'Referencia Civil')
            ->set('classificationText', 'No disponible en Civil')->call('review')->assertHasNoErrors()
            ->set('classificationText', 'Fuera del catálogo Civil')->call('import')->assertHasErrors('mapping')
            ->call('review')->call('import')->assertHasNoErrors();
        $this->assertDatabaseHas('catalogs', ['session_id' => $sessionId, 'side' => 'fifty', 'result_name' => 'Referencia Civil', 'classification_text' => 'Fuera del catálogo Civil']);
        $component->set('side', 'pemex')->assertSet('resultName', 'ID HGO')->assertSet('classificationText', 'Producto sin equivalente en HGO');
        $rightUpload = UploadedFile::fake()->createWithContent('derecho.xlsx', Storage::disk('local')->get($this->file('Civil')));
        $component->set('file', $rightUpload)->assertSet('sheet', 'General')->assertSet('reference', '0')->set('classificationText', '')->call('review')->assertHasErrors('classificationText');
    }

    public function test_generic_board_messages_exports_and_reports_use_session_configuration_with_many_to_one_links(): void
    {
        $sessionId = $this->createSession();
        $left = $this->import($sessionId, 'fifty', 'HGO', 'Código Civil', 'Sin equivalente en Civil');
        $right = $this->import($sessionId, 'pemex', 'Civil', 'Códigos HGO', 'Alta para HGO');
        $service = app(ReconciliationDecisions::class);
        $service->decide($sessionId, 'link', $left[0]->id, 0, $right[0]->id, 0, confirmed: true);
        $service->decide($sessionId, 'link', $left[1]->id, 0, $right[0]->id, 1, confirmed: true);
        $service->decide($sessionId, 'outside', $left[2]->id, 0);
        $service->decide($sessionId, 'new', $right[1]->id, 0);
        try {
            $service->decide($sessionId, 'new', $right[0]->id, 2);
            $this->fail('Debe rechazar una clasificación con relaciones activas.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmation', $e->errors());
            $this->assertStringContainsString('eliminará todas las equivalencias', $e->getMessage());
            $this->assertStringNotContainsString('PEMEX', $e->getMessage());
        }
        $response = $this->get(route('reconciliation.board', $sessionId))->assertOk();
        $response->assertSee('HGO')->assertSee('Civil')->assertSee('Sin equivalente en Civil')->assertSee('Alta para HGO')
            ->assertDontSee('Fifty')->assertDontSee('PEMEX')->assertSee('HGO conciliado.xlsx');
        Livewire::test(Board::class, ['sessionId' => $sessionId])->call('select', 'pemex', $right[0]->id)->assertSee('Relaciones con HGO');
        $workbook = app(CatalogWorkbook::class);
        $leftSheet = $workbook->export($sessionId, 'fifty', true)->getActiveSheet();
        $rightSheet = $workbook->export($sessionId, 'pemex', true)->getActiveSheet();
        $this->assertSame('Código Civil', $leftSheet->getCell('D1')->getValue());
        $this->assertSame('001', $leftSheet->getCell('D2')->getValue());
        $this->assertSame('Sin equivalente en Civil', $leftSheet->getCell('D4')->getValue());
        $this->assertSame('001;001', $rightSheet->getCell('D2')->getValue());
        $this->assertSame('Alta para HGO', $rightSheet->getCell('D3')->getValue());
        $this->assertSame('', $rightSheet->getCell('D4')->getValue());
        $report = $workbook->report($sessionId);
        $multiple = $report->getSheetByName('Relaciones múltiples');
        $this->assertSame('Archivo HGO', $multiple->getCell('F1')->getValue());
        $this->assertSame('Civil', $multiple->getCell('L2')->getValue());
        $this->assertSame('HGO', $multiple->getCell('M2')->getValue());
        $this->assertSame('Civil', $report->getSheetByName('Pendientes')->getCell('F2')->getValue());
        foreach (['fifty' => 'HGO conciliado.xlsx', 'pemex' => 'Civil conciliado.xlsx', 'report' => 'Informe de revisión - HGO vs Civil.xlsx'] as $kind => $name) {
            $response = $this->get(route('reconciliation.export', [$sessionId, $kind]))->assertOk()->assertDownload(Str::ascii($name));
            if ($kind === 'report') {
                $this->assertStringContainsString(rawurlencode($name), $response->headers->get('Content-Disposition'));
            }
        }
        $reviewer = User::factory()->create()->refresh();
        $this->actingAs($reviewer);
        Livewire::test(Board::class, ['sessionId' => $sessionId])->call('select', 'fifty', $left[0]->id)->assertDontSee('987654.32');
        $this->assertSame('Código Civil', $workbook->export($sessionId, 'fifty', false)->getActiveSheet()->getCell('C1')->getValue());
    }

    public function test_new_configuration_does_not_change_existing_results_or_decisions(): void
    {
        $oldId = $this->createSession('Fifty', 'PEMEX');
        $left = $this->import($oldId, 'fifty', 'Fifty', 'ID PEMEX', 'Producto fuera de cuadro de PEMEX');
        $right = $this->import($oldId, 'pemex', 'PEMEX', 'ID FIFTY', 'Producto nuevo');
        app(ReconciliationDecisions::class)->decide($oldId, 'link', $left[0]->id, 0, $right[0]->id, 0, confirmed: true);
        app(ReconciliationDecisions::class)->decide($oldId, 'outside', $left[1]->id, 0);
        $this->createSession('HGO', 'Civil');
        $workbook = app(CatalogWorkbook::class);
        $sheet = $workbook->export($oldId, 'fifty', true)->getActiveSheet();
        $this->assertSame('ID PEMEX', $sheet->getCell('D1')->getValue());
        $this->assertSame('001', $sheet->getCell('D2')->getValue());
        $this->assertSame('Producto fuera de cuadro de PEMEX', $sheet->getCell('D3')->getValue());
        $this->assertDatabaseCount('catalog_links', 1);
        $legacyDefaults = CatalogConfiguration::defaults(DB::table('reconciliation_sessions')->where('id', $oldId)->first(), 'pemex');
        $this->assertSame('ID FIFTY', $legacyDefaults['result_name']);
        $this->assertSame('Producto nuevo', $legacyDefaults['classification_text']);
    }

    public function test_migration_backfills_old_sessions_and_keeps_rows_links_and_result_configuration(): void
    {
        $sessionId = $this->createSession('Fifty', 'PEMEX');
        $left = $this->import($sessionId, 'fifty', 'Fifty', 'Resultado conservado', 'Producto fuera de cuadro de PEMEX');
        $right = $this->import($sessionId, 'pemex', 'PEMEX', 'ID FIFTY', 'Producto nuevo');
        app(ReconciliationDecisions::class)->decide($sessionId, 'link', $left[0]->id, 0, $right[0]->id, 0, confirmed: true);
        $migration = require database_path('migrations/2026_10_10_010001_add_catalog_configuration_to_reconciliation_sessions.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('reconciliation_sessions', ['id' => $sessionId, 'left_catalog_name' => 'Fifty', 'right_catalog_name' => 'PEMEX']);
        $this->assertDatabaseHas('catalogs', ['session_id' => $sessionId, 'side' => 'fifty', 'result_name' => 'Resultado conservado', 'classification_text' => 'Producto fuera de cuadro de PEMEX']);
        $this->assertDatabaseHas('catalogs', ['session_id' => $sessionId, 'side' => 'pemex', 'classification_text' => 'Producto nuevo']);
        $this->assertDatabaseHas('catalog_links', ['fifty_id' => $left[0]->id, 'pemex_id' => $right[0]->id]);
        $this->assertDatabaseHas('catalog_rows', ['id' => $left[0]->id, 'status' => 'linked', 'version' => 1]);
        $this->assertDatabaseCount('catalog_rows', 6);
    }

    public function test_download_names_are_safe_for_user_supplied_catalog_names(): void
    {
        $session = (object) ['left_catalog_name' => '../HGO/archivo', 'right_catalog_name' => "Civil\r\narchivo"];
        $name = CatalogConfiguration::downloadName($session, 'report');
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString("\r", $name);
        $this->assertStringNotContainsString("\n", $name);
        $this->assertStringEndsWith('.xlsx', $name);
    }
}
