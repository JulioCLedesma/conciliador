<?php

namespace Tests\Feature;

use App\Livewire\Reconciliation\Board;
use App\Livewire\Reconciliation\ImportCatalog;
use App\Livewire\Reconciliation\Sessions;
use App\Livewire\Reconciliation\Users;
use App\Models\User;
use App\Services\CatalogWorkbook;
use App\Services\ReconciliationDecisions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $reviewer;

    private int $sessionId;

    private array $catalogIds = [];

    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->role = 'admin';
        $this->admin->save();
        $this->reviewer = User::factory()->create()->refresh();
        $this->sessionId = $this->createSession();
        foreach (['fifty', 'pemex'] as $side) {
            $path = $this->fixture($side);
            $this->catalogIds[$side] = app(CatalogWorkbook::class)->import($this->sessionId, $side, $path, $side.'.xlsx', $side === 'pemex' ? 'PARTIDA 4' : 'Productos', 2, 0, [1], [2], 3, $side === 'pemex' ? 'ID FIFTY' : 'ID PEMEX');
            $this->rows[$side] = DB::table('catalog_rows')->where('catalog_id', $this->catalogIds[$side])->orderBy('original_row')->get()->all();
        }
        $this->actingAs($this->admin);
    }

    private function createSession(): int
    {
        return DB::table('reconciliation_sessions')->insertGetId(['name' => 'Ejemplo', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fixture(string $side): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($side === 'pemex' ? 'PARTIDA 4' : 'Productos');
        $data = [
            ['Importe privado: 987654.32'],
            [$side === 'pemex' ? 'ID PEMEX' : 'ID FIFTY', 'Descripción', 'Costo', 'Resultado', '', 'Descripción'],
            [$side === 'pemex' ? 'P01' : '001', 'Paracetamol marca A', '987654.32', 'Anterior', 'Dato sin encabezado', '=1+2'],
            [$side === 'pemex' ? 'P02' : '001', 'Paracetamol marca B', '987654.32', '', '', 'Detalle'],
            ['', 'Sin referencia', '987654.32', '', '', ''],
        ];
        foreach ($data as $r => $values) {
            foreach ($values as $c => $value) {
                $sheet->setCellValueExplicit([$c + 1, $r + 1], $value, DataType::TYPE_STRING);
            }
        }
        $sheet->setCellValue('F3', '=1+2');
        $book->createSheet()->setTitle('Otra hoja')->setCellValue('A1', 'NO IMPORTAR');
        $path = 'examples/'.$side.'-'.uniqid().'.xlsx';
        Storage::disk('local')->makeDirectory('examples');
        (new Xlsx($book))->save(Storage::disk('local')->path($path));
        $book->disconnectWorksheets();

        return $path;
    }

    private function decide(string $action, int $fiftyIndex = 0, ?int $pemexIndex = null, bool $confirmed = false): void
    {
        $row = DB::table('catalog_rows')->where('id', $this->rows['fifty'][$fiftyIndex]->id)->first();
        $target = $pemexIndex === null ? null : DB::table('catalog_rows')->where('id', $this->rows['pemex'][$pemexIndex]->id)->first();
        app(ReconciliationDecisions::class)->decide($this->sessionId, $action, $row->id, $row->version, $target?->id, $target?->version, $action === 'link' ? true : $confirmed);
    }

    public function test_admin_can_list_and_create_users_with_hashed_password_and_explicit_role(): void
    {
        $this->get(route('users.index'))->assertOk();
        Livewire::test(Users::class)->set('name', 'Persona de prueba')->set('email', 'persona@example.test')->set('role', 'admin')
            ->set('password', 'clave-segura-123')->set('password_confirmation', 'clave-segura-123')->call('create')->assertHasNoErrors();
        $user = User::where('email', 'persona@example.test')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertTrue(Hash::check('clave-segura-123', $user->password));
        $this->assertNotContains('role', $user->getFillable());
        Livewire::test(Users::class)->set('name', 'Otro')->set('email', $user->email)->set('role', 'invalid')->set('password', '12345678')->set('password_confirmation', 'different')->call('create')->assertHasErrors(['email', 'role', 'password']);
    }

    public function test_reviewer_cannot_access_users_imports_or_original_files(): void
    {
        $this->actingAs($this->reviewer);
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('reconciliation.import', $this->sessionId))->assertForbidden();
        $this->get(route('reconciliation.original', $this->catalogIds['pemex']))->assertForbidden();
        Livewire::test(Sessions::class)->set('name', 'Prohibida')->call('create')->assertForbidden();
    }

    public function test_revoked_admin_cannot_create_users_in_an_existing_livewire_component(): void
    {
        $component = Livewire::test(Users::class)->set('name', 'Prohibido')->set('email', 'blocked@example.test')->set('password', '12345678')->set('password_confirmation', '12345678');
        $this->actingAs($this->reviewer);
        $component->call('create')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.test']);
    }

    public function test_public_registration_stays_disabled(): void
    {
        auth()->logout();
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'No', 'email' => 'no@example.test', 'password' => '12345678', 'password_confirmation' => '12345678'])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'no@example.test']);
    }

    public function test_selected_sheet_headers_duplicates_empty_references_and_formula_text_are_preserved(): void
    {
        $catalog = DB::table('catalogs')->where('id', $this->catalogIds['pemex'])->first();
        $this->assertSame('PARTIDA 4', $catalog->sheet);
        $this->assertCount(3, $this->rows['pemex']);
        $this->assertSame('', $this->rows['pemex'][2]->reference);
        $this->assertSame(3, $this->rows['pemex'][0]->original_row);
        $this->assertSame('001', $this->rows['fifty'][0]->reference);
        $this->assertSame('001', $this->rows['fifty'][1]->reference);
        $this->assertNotSame($this->rows['fifty'][0]->id, $this->rows['fifty'][1]->id);
        $this->assertSame('=1+2', json_decode($this->rows['fifty'][0]->values, true)[5]);
        $headers = json_decode($catalog->headers, true);
        $this->assertSame('', $headers[4]);
        $this->assertSame($headers[1], $headers[5]);
        $data = app(CatalogWorkbook::class)->read(Storage::disk('local')->path($this->fixture('fifty')), 'Productos', 2);
        $warnings = app(CatalogWorkbook::class)->inspect($data, 0, [1]);
        $this->assertCount(2, $warnings['duplicates']);
        $this->assertCount(1, $warnings['empty']);
        $this->assertSame(1, $warnings['formulas']);
    }

    public function test_import_ui_requires_review_and_defaults_pemex_to_partida_four(): void
    {
        $path = Storage::disk('local')->path($this->fixture('pemex'));
        $upload = UploadedFile::fake()->createWithContent('ejemplo.xlsx', file_get_contents($path));
        $newSession = $this->createSession();
        $component = Livewire::test(ImportCatalog::class, ['sessionId' => $newSession])->set('side', 'pemex')->set('headerRow', 2)->set('file', $upload)
            ->assertSet('sheet', 'PARTIDA 4')->assertSet('reference', '0')->set('descriptions', ['1'])->set('sensitive', ['2'])->set('result', '3');
        $component->call('import')->assertHasErrors('mapping')->call('review')->assertHasNoErrors()->assertSet('warnings.count', 3)
            ->set('resultName', 'Nuevo resultado')->call('import')->assertHasErrors('mapping')->call('review')->call('import')->assertHasNoErrors();
        $this->assertDatabaseHas('catalogs', ['session_id' => $newSession, 'side' => 'pemex', 'sheet' => 'PARTIDA 4', 'result_name' => 'Nuevo resultado']);
    }

    public function test_existing_catalog_cannot_be_overwritten_and_sensitive_search_columns_are_rejected(): void
    {
        $service = app(CatalogWorkbook::class);
        $path = $this->fixture('fifty');
        try {
            $service->import($this->sessionId, 'fifty', $path, 'new.xlsx', 'Productos', 2, 0, [1], [2], 3, 'ID PEMEX');
            $this->fail('Debió impedir la sustitución.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }
        $this->expectException(ValidationException::class);
        $service->import($this->createSession(), 'fifty', $path, 'new.xlsx', 'Productos', 2, 0, [1, 2], [2], 3, 'ID PEMEX');
    }

    public function test_one_pemex_can_link_to_multiple_fifty_rows_with_duplicate_references(): void
    {
        $this->actingAs($this->reviewer);
        $this->decide('link', 0, 0, confirmed: true);
        $this->decide('link', 1, 0, confirmed: true);
        $this->assertDatabaseCount('catalog_links', 2);
        $this->assertDatabaseHas('catalog_rows', ['id' => $this->rows['pemex'][0]->id, 'status' => 'linked']);
        $book = app(CatalogWorkbook::class)->export($this->sessionId, 'pemex', false);
        $this->assertSame('001;001', $book->getActiveSheet()->getCell('C3')->getValue());
        $report = app(CatalogWorkbook::class)->report($this->sessionId);
        $sheet = $report->getSheetByName('Relaciones múltiples');
        $this->assertSame(3, $sheet->getHighestDataRow());
        $this->assertSame('3', $sheet->getCell('H2')->getValue());
        $this->assertSame('4', $sheet->getCell('H3')->getValue());
    }

    public function test_adding_and_undoing_links_keeps_states_consistent(): void
    {
        $this->decide('link', 0, 0, confirmed: true);
        $this->decide('link', 0, 1, confirmed: true);
        $this->assertDatabaseCount('catalog_links', 2);
        $this->assertDatabaseHas('catalog_rows', ['id' => $this->rows['pemex'][0]->id, 'status' => 'linked']);
        $this->assertDatabaseHas('catalog_rows', ['id' => $this->rows['pemex'][1]->id, 'status' => 'linked']);
        $this->decide('undo', 0, null, true);
        $this->assertDatabaseCount('catalog_links', 0);
        $this->assertDatabaseHas('catalog_rows', ['id' => $this->rows['pemex'][0]->id, 'status' => 'pending']);
        $this->assertDatabaseHas('catalog_rows', ['id' => $this->rows['pemex'][1]->id, 'status' => 'pending']);
        $this->assertDatabaseCount('reconciliation_audits', 3);
        $audit = DB::table('reconciliation_audits')->latest('id')->first();
        $this->assertSame('undo', $audit->action);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertStringNotContainsString('987654.32', $audit->before.$audit->after);
    }

    public function test_pemex_with_links_cannot_be_classified_new_and_stays_linked_until_last_link_is_removed(): void
    {
        $this->decide('link', 0, 0, confirmed: true);
        $this->decide('link', 1, 0, confirmed: true);
        $target = DB::table('catalog_rows')->where('id', $this->rows['pemex'][0]->id)->first();
        try {
            app(ReconciliationDecisions::class)->decide($this->sessionId, 'new', $target->id, $target->version);
            $this->fail('Debió solicitar confirmación para retirar las equivalencias.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmation', $e->errors());
        }
        $this->decide('outside', 0, null, true);
        $this->assertDatabaseHas('catalog_rows', ['id' => $target->id, 'status' => 'linked']);
        $this->decide('undo', 1, null, true);
        $this->assertDatabaseHas('catalog_rows', ['id' => $target->id, 'status' => 'pending']);
    }

    public function test_empty_references_cannot_link_but_can_be_classified(): void
    {
        try {
            $this->decide('link', 2, 0, confirmed: true);
            $this->fail('Debió rechazar la referencia vacía.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('decision', $e->errors());
        }
        try {
            $this->decide('link', 0, 2, confirmed: true);
            $this->fail('Debió rechazar la referencia vacía PEMEX.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('decision', $e->errors());
        }
        $this->decide('outside', 2);
        $this->assertDatabaseHas('catalog_rows', ['id' => $this->rows['fifty'][2]->id, 'status' => 'outside']);
    }

    public function test_previously_classified_rows_can_be_linked_after_confirmation(): void
    {
        $this->decide('outside', 0);
        $target = $this->rows['pemex'][0];
        app(ReconciliationDecisions::class)->decide($this->sessionId, 'new', $target->id, $target->version);
        $this->decide('link', 0, 0, true);
        $this->assertDatabaseHas('catalog_rows', ['id' => $target->id, 'status' => 'linked']);
        $this->assertDatabaseHas('catalog_rows', ['id' => $this->rows['fifty'][0]->id, 'status' => 'linked']);
    }

    public function test_rows_from_other_sessions_and_wrong_sides_are_rejected(): void
    {
        try {
            app(ReconciliationDecisions::class)->decide($this->createSession(), 'link', $this->rows['fifty'][0]->id, 0, $this->rows['pemex'][0]->id, 0, confirmed: true);
            $this->fail('Debió rechazar otra sesión.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('decision', $e->errors());
        }
        $this->expectException(ValidationException::class);
        app(ReconciliationDecisions::class)->decide($this->sessionId, 'link', $this->rows['pemex'][0]->id, 0, $this->rows['fifty'][0]->id, 0, confirmed: true);
    }

    public function test_board_preserves_independent_selection_and_handles_concurrent_changes(): void
    {
        $component = Livewire::test(Board::class, ['sessionId' => $this->sessionId])->call('select', 'fifty', $this->rows['fifty'][0]->id)
            ->call('select', 'pemex', $this->rows['pemex'][0]->id)->set('search.pemex', 'Otro')->call('loadMore', 'pemex', 0, 1)
            ->assertSet('selected.fifty.'.$this->rows['fifty'][0]->id, 0);
        $this->decide('outside', 0);
        $component->call('decide', 'link')->assertHasErrors('conflict')->assertSet('selected.fifty.'.$this->rows['fifty'][0]->id, 1);
        $this->assertDatabaseCount('catalog_links', 0);
        $component->call('decide', 'link')->assertHasNoErrors()->assertSet('confirmation.newCount', 1)->call('confirm')->assertHasNoErrors();
        $this->assertDatabaseCount('catalog_links', 1);
    }

    public function test_pemex_concurrent_changes_are_detected_before_overwriting(): void
    {
        $this->decide('link', 1, 0, confirmed: true);
        $this->expectException(ValidationException::class);
        app(ReconciliationDecisions::class)->decide($this->sessionId, 'link', $this->rows['fifty'][0]->id, 0, $this->rows['pemex'][0]->id, 0, confirmed: true);
    }

    public function test_unique_database_constraint_prevents_duplicate_pairs(): void
    {
        $this->decide('link', 0, 0, confirmed: true);
        $this->expectException(QueryException::class);
        DB::table('catalog_links')->insert(['session_id' => $this->sessionId, 'fifty_id' => $this->rows['fifty'][0]->id, 'pemex_id' => $this->rows['pemex'][0]->id]);
    }

    public function test_reviewer_receives_no_amounts_in_html_livewire_or_excel_and_admin_keeps_columns(): void
    {
        $this->get(route('reconciliation.board', $this->sessionId))->assertOk();
        Livewire::test(Board::class, ['sessionId' => $this->sessionId])->call('select', 'fifty', $this->rows['fifty'][0]->id)->assertSee('987654.32');
        $this->actingAs($this->reviewer);
        $this->get(route('reconciliation.board', $this->sessionId))->assertOk()->assertDontSee('987654.32')->assertDontSee('Costo');
        Livewire::test(Board::class, ['sessionId' => $this->sessionId])->call('select', 'fifty', $this->rows['fifty'][0]->id)
            ->assertDontSee('987654.32')->assertDontSee('Costo');
        foreach (['fifty', 'pemex', 'report'] as $kind) {
            $response = $this->get(route('reconciliation.export', [$this->sessionId, $kind]))->assertOk();
            $content = $response->streamedContent();
            $path = Storage::disk('local')->path('download-'.$kind.'.xlsx');
            file_put_contents($path, $content);
            $book = IOFactory::load($path);
            foreach ($book->getAllSheets() as $sheet) {
                $this->assertStringNotContainsString('987654.32', json_encode($sheet->toArray(null, false, false)));
                $this->assertStringNotContainsString('Costo', json_encode($sheet->toArray(null, false, false)));
            }
            if ($kind !== 'report') {
                $this->assertSame(1, $book->getSheetCount());
                $this->assertSame('=1+2', $book->getActiveSheet()->getCell('E3')->getValue());
                $this->assertSame(DataType::TYPE_STRING, $book->getActiveSheet()->getCell('E3')->getDataType());
            }
        }
        $adminBook = app(CatalogWorkbook::class)->export($this->sessionId, 'fifty', true);
        $this->assertSame('Costo', $adminBook->getActiveSheet()->getCell('C2')->getValue());
        $this->assertSame('987654.32', $adminBook->getActiveSheet()->getCell('C3')->getValue());
        $this->assertSame('001', $adminBook->getActiveSheet()->getCell('A3')->getValue());
    }

    public function test_exports_use_exact_classifications_pending_blanks_and_configured_result_column(): void
    {
        $this->decide('outside', 0);
        $target = $this->rows['pemex'][0];
        app(ReconciliationDecisions::class)->decide($this->sessionId, 'new', $target->id, $target->version);
        $fifty = app(CatalogWorkbook::class)->export($this->sessionId, 'fifty', true)->getActiveSheet();
        $pemex = app(CatalogWorkbook::class)->export($this->sessionId, 'pemex', true)->getActiveSheet();
        $this->assertSame('ID PEMEX', $fifty->getCell('D2')->getValue());
        $this->assertSame('Producto fuera de cuadro de PEMEX', $fifty->getCell('D3')->getValue());
        $this->assertSame('', $fifty->getCell('D4')->getValue());
        $this->assertSame('Producto nuevo', $pemex->getCell('D3')->getValue());
        $this->assertSame('', $pemex->getCell('D4')->getValue());
        $this->assertSame('F', $fifty->getHighestDataColumn());
        DB::table('catalogs')->where('id', $this->catalogIds['fifty'])->update(['result_column' => 6]);
        $appended = app(CatalogWorkbook::class)->export($this->sessionId, 'fifty', true)->getActiveSheet();
        $this->assertSame('ID PEMEX', $appended->getCell('G2')->getValue());
        $this->assertSame('Anterior', $appended->getCell('D3')->getValue());
    }

    public function test_numeric_references_keep_displayed_zero_padding_and_text_references_are_unchanged(): void
    {
        $path = $this->fixture('fifty');
        $absolute = Storage::disk('local')->path($path);
        $book = IOFactory::load($absolute);
        $sheet = $book->getActiveSheet();
        $sheet->setCellValue('A3', 7);
        $sheet->getStyle('A3')->getNumberFormat()->setFormatCode('0000');
        $sheet->setCellValueExplicit('A4', '001.00', DataType::TYPE_STRING);
        (new Xlsx($book))->save($absolute);
        $book->disconnectWorksheets();
        $data = app(CatalogWorkbook::class)->read($absolute, 'Productos', 2);
        $this->assertSame('0007', $data['rows'][3][0]);
        $this->assertSame('001.00', $data['rows'][4][0]);
    }

    public function test_future_files_can_select_a_different_sheet_and_invalid_header_is_rejected(): void
    {
        $path = $this->fixture('pemex');
        $absolute = Storage::disk('local')->path($path);
        $book = IOFactory::load($absolute);
        $sheet = $book->getSheetByName('Otra hoja');
        $sheet->setCellValue('A1', 'Referencia')->setCellValue('B1', 'Descripción');
        $sheet->setCellValueExplicit('A2', '0009', DataType::TYPE_STRING)->setCellValue('B2', 'Producto de otra hoja');
        (new Xlsx($book))->save($absolute);
        $book->disconnectWorksheets();
        $newSession = $this->createSession();
        $id = app(CatalogWorkbook::class)->import($newSession, 'pemex', $path, 'futuro.xlsx', 'Otra hoja', 1, 0, [1], [], null, 'ID FIFTY');
        $this->assertSame(1, DB::table('catalog_rows')->where('catalog_id', $id)->count());
        $export = app(CatalogWorkbook::class)->export($newSession, 'pemex', true);
        $this->assertSame('Otra hoja', $export->getActiveSheet()->getTitle());
        $this->assertSame('0009', $export->getActiveSheet()->getCell('A2')->getValue());
        $this->assertSame('ID FIFTY', $export->getActiveSheet()->getCell('C1')->getValue());
        $this->expectException(ValidationException::class);
        app(CatalogWorkbook::class)->read($absolute, 'Otra hoja', 2);
    }

    public function test_database_foreign_keys_reject_cross_session_and_wrong_side_links(): void
    {
        foreach ([
            ['session_id' => $this->sessionId + 100, 'fifty_id' => $this->rows['fifty'][0]->id, 'pemex_id' => $this->rows['pemex'][0]->id],
            ['session_id' => $this->sessionId, 'fifty_id' => $this->rows['pemex'][0]->id, 'pemex_id' => $this->rows['fifty'][0]->id],
            ['session_id' => $this->sessionId, 'fifty_id' => $this->rows['pemex'][0]->id, 'pemex_id' => $this->rows['fifty'][0]->id, 'fifty_side' => 'pemex', 'pemex_side' => 'fifty'],
        ] as $link) {
            try {
                DB::table('catalog_links')->insert($link);
                $this->fail('La base debe rechazar una relación inválida.');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertDatabaseCount('catalog_links', 0);
    }

    public function test_invalid_excel_and_macro_archives_are_rejected(): void
    {
        Storage::disk('local')->put('invalid.xlsx', 'No es Excel');
        try {
            app(CatalogWorkbook::class)->sheets(Storage::disk('local')->path('invalid.xlsx'));
            $this->fail('Debió rechazar el archivo inválido.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }
        $path = Storage::disk('local')->path($this->fixture('fifty'));
        $zip = new \ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/vbaProject.bin', 'macro simulada');
        $zip->close();
        $this->expectException(ValidationException::class);
        app(CatalogWorkbook::class)->sheets($path);
    }
}
