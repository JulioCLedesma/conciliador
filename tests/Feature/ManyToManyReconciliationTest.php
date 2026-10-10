<?php

namespace Tests\Feature;

use App\Livewire\Reconciliation\Board;
use App\Models\User;
use App\Services\CatalogWorkbook;
use App\Services\ReconciliationDecisions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ManyToManyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private int $sessionId;

    private array $left;

    private array $right;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        $admin->role = 'admin';
        $admin->save();
        $this->actingAs($admin);
        $this->sessionId = DB::table('reconciliation_sessions')->insertGetId(['name' => 'Hospitales', 'left_catalog_name' => 'HGO', 'right_catalog_name' => 'Civil', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->left = $this->catalog('fifty', 22);
        $this->right = $this->catalog('pemex', 3);
    }

    private function catalog(string $side, int $count): array
    {
        $id = DB::table('catalogs')->insertGetId([
            'session_id' => $this->sessionId, 'side' => $side, 'filename' => $side.'.xlsx', 'path' => 'unused.xlsx', 'sheet' => 'Productos', 'header_row' => 1,
            'headers' => json_encode(['Referencia', 'Descripción', 'Costo']), 'preamble' => '[]', 'reference_column' => 0,
            'description_columns' => '[1]', 'sensitive_columns' => '[2]', 'result_column' => 3, 'result_name' => $side === 'fifty' ? 'ID CIVIL' : 'ID HGO',
            'classification_text' => $side === 'fifty' ? 'Sin equivalente en Civil' : 'Sin equivalente en HGO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 0; $i < $count; $i++) {
            $reference = $side === 'fifty' ? ($i < 2 ? '001' : sprintf('%03d', $i + 1)) : 'R0'.($i + 1);
            DB::table('catalog_rows')->insert(['catalog_id' => $id, 'session_id' => $this->sessionId, 'side' => $side, 'original_row' => $i + 2,
                'reference' => $reference, 'description' => 'Descripción '.($i + 1), 'values' => json_encode([$reference, 'Descripción '.($i + 1), '987654.32'])]);
        }

        return DB::table('catalog_rows')->where('catalog_id', $id)->orderBy('original_row')->pluck('id')->all();
    }

    private function versions(array $ids): array
    {
        return DB::table('catalog_rows')->whereIn('id', $ids)->pluck('version', 'id')->all();
    }

    private function link(array $left, array $right): int
    {
        return app(ReconciliationDecisions::class)->linkMany($this->sessionId, $this->versions($left), $this->versions($right), true);
    }

    private function board(): Testable
    {
        return Livewire::test(Board::class, ['sessionId' => $this->sessionId]);
    }

    private function selectedBoard(): Testable
    {
        $component = $this->board();
        foreach (array_slice($this->left, 0, 2) as $id) {
            $component->call('select', 'fifty', $id);
        }
        foreach ($this->right as $id) {
            $component->call('select', 'pemex', $id);
        }

        return $component;
    }

    private function assertRowAppearance(Testable $component, int $id, string $background, string $label): string
    {
        $this->assertSame(1, preg_match('~<tr\b[^>]*wire:key="row-(?:fifty|pemex)-'.$id.'"[^>]*>.*?</tr>~s', $component->html(), $matches));
        $html = $matches[0];
        $this->assertStringContainsString($background, $html);
        $this->assertStringContainsString('>'.$label.'</span>', $html);
        foreach (array_diff(['bg-white', 'bg-green-50', 'bg-yellow-50'], [$background]) as $other) {
            $this->assertStringNotContainsString($other, strstr($html, '>', true));
        }

        return $html;
    }

    public function test_status_colors_update_in_both_panels_without_selection_overriding_backgrounds(): void
    {
        $component = $this->board()->assertSee('Estados de revisión');
        foreach ([$this->left[0], $this->right[0]] as $id) {
            $this->assertRowAppearance($component, $id, 'bg-white', 'Pendiente');
        }
        $component->call('select', 'fifty', $this->left[0])->call('select', 'pemex', $this->right[0])
            ->call('decide', 'link')->call('confirm')->assertHasNoErrors();
        foreach (['fifty' => $this->left[0], 'pemex' => $this->right[0]] as $side => $id) {
            $this->assertRowAppearance($component, $id, 'bg-green-50', 'Verificado');
            $component->call('select', $side, $id);
            $html = $this->assertRowAppearance($component, $id, 'bg-green-50', 'Verificado');
            $this->assertStringContainsString('ring-2 ring-inset ring-indigo-600', $html);
            $this->assertStringContainsString('x-bind:checked', $html);
            $this->assertStringNotContainsString('bg-indigo-100', $html);
        }
        $component->call('clearSelection', 'fifty')->call('clearSelection', 'pemex');
        $this->link([$this->left[0]], [$this->right[1]]);
        $link = DB::table('catalog_links')->where('pemex_id', $this->right[0])->first();
        $versions = $this->versions([$link->fifty_id, $link->pemex_id]);
        $component->call('requestRemoveLink', $link->id, $versions[$link->fifty_id], $versions[$link->pemex_id])->call('confirm')->assertHasNoErrors();
        $this->assertRowAppearance($component, $this->left[0], 'bg-green-50', 'Verificado');
        $this->assertRowAppearance($component, $this->right[0], 'bg-white', 'Pendiente');
        $link = DB::table('catalog_links')->first();
        $versions = $this->versions([$link->fifty_id, $link->pemex_id]);
        $component->call('requestRemoveLink', $link->id, $versions[$link->fifty_id], $versions[$link->pemex_id])->call('confirm')->assertHasNoErrors();
        foreach ([$this->left[0], $this->right[1]] as $id) {
            $this->assertRowAppearance($component, $id, 'bg-white', 'Pendiente');
        }
    }

    public function test_manual_classification_colors_persist_across_navigation_reload_and_login_and_undo(): void
    {
        $component = $this->board();
        foreach (['fifty' => $this->left[0], 'pemex' => $this->right[0]] as $side => $id) {
            $component->call('decide', $side === 'fifty' ? 'outside' : 'new', $side, $id, 0)->call('confirm')->assertHasNoErrors();
            $html = $this->assertRowAppearance($component, $id, 'bg-yellow-50', $id === $this->left[0] ? 'Sin equivalente en Civil' : 'Sin equivalente en HGO');
            $this->assertStringContainsString($side === 'fifty' ? 'Sin equivalente en Civil' : 'Sin equivalente en HGO', $html);
            $component->call('select', $side, $id);
            $this->assertStringContainsString('ring-2 ring-inset ring-indigo-600', $this->assertRowAppearance($component, $id, 'bg-yellow-50', $id === $this->left[0] ? 'Sin equivalente en Civil' : 'Sin equivalente en HGO'));
        }
        $component->call('loadMore', 'fifty', 10, 0)->set('search.fifty', 'Descripción 1')->set('status.pemex', 'new');
        foreach ([$this->left[0], $this->right[0]] as $id) {
            $this->assertRowAppearance($component, $id, 'bg-yellow-50', $id === $this->left[0] ? 'Sin equivalente en Civil' : 'Sin equivalente en HGO');
        }
        $user = auth()->user();
        auth()->logout();
        $this->actingAs($user);
        $component = $this->board();
        foreach (['fifty' => $this->left[0], 'pemex' => $this->right[0]] as $side => $id) {
            $this->assertRowAppearance($component, $id, 'bg-yellow-50', $id === $this->left[0] ? 'Sin equivalente en Civil' : 'Sin equivalente en HGO');
            $component->call('decide', 'undo', $side, $id, $this->versions([$id])[$id])->call('confirm')->assertHasNoErrors();
            $this->assertRowAppearance($component, $id, 'bg-white', 'Pendiente');
            $this->assertDatabaseHas('catalog_rows', ['id' => $id, 'status' => 'pending']);
        }
    }

    public function test_multi_selection_toggles_independently_and_persists_across_pages_searches_and_filters(): void
    {
        $component = $this->board()->call('select', 'fifty', $this->left[0])->call('select', 'fifty', $this->left[1])->call('select', 'pemex', $this->right[0])
            ->assertSet('selected.fifty', $this->versions(array_slice($this->left, 0, 2)))->assertSet('selected.pemex', $this->versions([$this->right[0]]));
        $component->call('loadMore', 'fifty', 10, 0)->set('search.fifty', 'Descripción 22')->set('status.pemex', 'pending')->set('perPage.pemex', 20)
            ->assertSet('selected.fifty', $this->versions(array_slice($this->left, 0, 2)))->assertSee('2 seleccionados en HGO')
            ->call('select', 'fifty', $this->left[0])->assertSet('selected.fifty', $this->versions([$this->left[1]]))
            ->call('clearSelection', 'fifty')->assertSet('selected.fifty', [])->assertSet('selected.pemex', $this->versions([$this->right[0]]));
    }

    public function test_progressive_loading_preserves_rows_selection_and_independent_results(): void
    {
        $component = $this->board()->assertSet('loadedIds.fifty', array_slice($this->left, 0, 10))
            ->assertSet('loadedIds.pemex', $this->right)->assertSet('hasMore.pemex', false)->assertSee('Mostrando 10 de 22')
            ->call('select', 'fifty', $this->left[0])->call('select', 'pemex', $this->right[0])
            ->call('loadMore', 'fifty', 10, 0)->assertSet('loadedIds.fifty', array_slice($this->left, 0, 20))
            ->assertSee('Mostrando 20 de 22')->assertSet('loadedIds.pemex', $this->right)
            ->call('loadMore', 'fifty', 10, 0)->assertSet('loadedIds.fifty', array_slice($this->left, 0, 20))
            ->call('loadMore', 'fifty', 20, 0)->assertSet('loadedIds.fifty', $this->left)->assertSet('hasMore.fifty', false)
            ->assertSee('Mostrando 22 de 22')->assertSee('Fin del catálogo')
            ->call('loadMore', 'fifty', 22, 0)->assertSet('loadedIds.fifty', $this->left);
        $component->set('search.pemex', 'R01')->assertDispatched('catalog-results-reset', side: 'pemex')
            ->assertSet('loadedIds.fifty', $this->left)->assertSet('loadedIds.pemex', [$this->right[0]])
            ->set('search.fifty', 'Descripción 22')->assertSet('loadedIds.fifty', [$this->left[21]])
            ->assertDispatched('catalog-results-reset', side: 'fifty')->assertSee('Mostrando 1 de 1')
            ->call('loadMore', 'fifty', 1, 0)->assertSet('loadedIds.fifty', [$this->left[21]])
            ->assertSet('selected.fifty', $this->versions([$this->left[0]]))->assertSet('selected.pemex', $this->versions([$this->right[0]]));
        $component->set('perPage.pemex', 20)->assertSet('perPage.fifty', 10)->assertSet('loadedIds.fifty', [$this->left[21]])
            ->assertSee('Filas de HGO')->assertSee('Filas de Civil')->assertSee('Acciones de conciliación')->assertSee('Ver seleccionados')
            ->assertDontSee('scrollIntoView', false)->call('clearAllSelections')->assertSet('selected.fifty', [])->assertSet('selected.pemex', []);
    }

    public function test_select_and_deselect_loaded_affect_only_current_result_and_keep_hidden_selection(): void
    {
        $component = $this->board()->call('selectLoaded', 'fifty')->assertSet('selected.fifty', $this->versions(array_slice($this->left, 0, 10)))
            ->call('loadMore', 'fifty', 10, 0)->call('selectLoaded', 'fifty')
            ->assertSet('selected.fifty', $this->versions(array_slice($this->left, 0, 20)))
            ->set('search.fifty', 'Descripción 22')->call('selectLoaded', 'fifty')->call('selectLoaded', 'fifty')
            ->assertSet('selected.fifty', $this->versions(array_merge(array_slice($this->left, 0, 20), [$this->left[21]])))
            ->call('deselectLoaded', 'fifty')->assertSet('selected.fifty', $this->versions(array_slice($this->left, 0, 20)));
    }

    public function test_block_size_filters_stable_order_and_empty_results(): void
    {
        $component = $this->board()->assertSet('loadedIds.fifty', array_slice($this->left, 0, 10))
            ->call('loadMore', 'fifty', 10, 0)->assertSet('loadedIds.fifty', array_slice($this->left, 0, 20))
            ->set('perPage.fifty', 20)->assertSet('loadedIds.fifty', array_slice($this->left, 0, 20))
            ->call('loadMore', 'fifty', 20, 1)->assertSet('loadedIds.fifty', $this->left)
            ->set('perPage.fifty', 50)->assertSet('loadedIds.fifty', $this->left)->assertSet('hasMore.fifty', false)
            ->set('status.fifty', 'linked')->assertSet('loadedIds.fifty', [])->assertSet('hasMore.fifty', false)->assertSee('Mostrando 0 de 0');
        $component->set('status.fifty', '')->set('search.fifty', 'No existe')->assertSet('loadedIds.fifty', [])->assertSet('hasMore.fifty', false);
    }

    public function test_confirming_equivalence_keeps_loaded_blocks_and_updates_persisted_statuses(): void
    {
        $component = $this->board()->call('loadMore', 'fifty', 10, 0)->call('select', 'fifty', $this->left[15])
            ->call('select', 'pemex', $this->right[0])->call('decide', 'link')->call('confirm')->assertHasNoErrors()
            ->assertSet('loadedIds.fifty', array_slice($this->left, 0, 20))->assertSet('loadedIds.pemex', $this->right)
            ->assertSet('generation.fifty', 0)->assertSet('selected.fifty', [])->assertSet('selected.pemex', []);
        $this->assertRowAppearance($component, $this->left[15], 'bg-green-50', 'Verificado');
        $this->assertRowAppearance($component, $this->right[0], 'bg-green-50', 'Verificado');
    }

    public function test_clearing_selection_preserves_decisions_links_and_colors_and_leaves_later_blocks_unselected(): void
    {
        $this->link([$this->left[0]], [$this->right[0]]);
        app(ReconciliationDecisions::class)->decide($this->sessionId, 'outside', $this->left[1], 0);
        $before = DB::table('catalog_rows')->orderBy('id')->get()->toJson();
        $audits = DB::table('reconciliation_audits')->count();
        $component = $this->board()->call('selectLoaded', 'fifty')->call('selectLoaded', 'pemex')->call('clearAllSelections')
            ->assertSet('selected.fifty', [])->assertSet('selected.pemex', [])->assertSee('0 seleccionados')
            ->call('loadMore', 'fifty', 10, 0)->assertSet('selected.fifty', [])->assertSet('selected.pemex', []);
        $this->assertRowAppearance($component, $this->left[0], 'bg-green-50', 'Verificado');
        $this->assertRowAppearance($component, $this->left[1], 'bg-yellow-50', 'Sin equivalente en Civil');
        $this->assertStringNotContainsString('ring-2 ring-inset ring-indigo-600', $component->html());
        $this->assertSame($before, DB::table('catalog_rows')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('catalog_links', 1);
        $this->assertDatabaseCount('reconciliation_audits', $audits);
    }

    public function test_two_by_three_requires_confirmation_creates_six_links_and_clears_selection(): void
    {
        $component = $this->selectedBoard()->call('decide', 'link')->assertHasNoErrors()->assertSet('confirmation.newCount', 6)
            ->assertSee('Todos los productos seleccionados del catálogo izquierdo')->assertSee('6 vínculos nuevos');
        $this->assertDatabaseCount('catalog_links', 0);
        $component->call('cancel')->assertSet('confirmation', null)->assertSet('selected.fifty', $this->versions(array_slice($this->left, 0, 2)))
            ->call('decide', 'link')->call('confirm')->assertHasNoErrors()->assertSet('selected.fifty', [])->assertSet('selected.pemex', [])->assertSee('Verificado');
        $this->assertDatabaseCount('catalog_links', 6);
        $this->assertSame(5, DB::table('catalog_rows')->where('status', 'linked')->count());
        $this->assertDatabaseCount('reconciliation_audits', 1);
        $this->assertStringNotContainsString('987654.32', DB::table('reconciliation_audits')->value('after'));
    }

    public function test_existing_equivalences_are_shown_preserved_and_never_duplicated(): void
    {
        $this->link([$this->left[0], $this->left[2]], [$this->right[0]]);
        $originalIds = DB::table('catalog_links')->pluck('id')->all();
        $this->selectedBoard()->call('decide', 'link')->assertSet('confirmation.newCount', 5)->assertSet('confirmation.existingCount', 1)
            ->assertSee('Equivalencias existentes que se conservarán')->call('confirm')->assertHasNoErrors();
        $this->assertDatabaseCount('catalog_links', 7);
        $this->assertSame(2, DB::table('catalog_links')->whereIn('id', $originalIds)->count());
        $this->assertSame(0, $this->link(array_slice($this->left, 0, 2), $this->right));
        $this->assertDatabaseCount('catalog_links', 7);
        $this->assertDatabaseCount('reconciliation_audits', 2);
    }

    public function test_duplicate_pair_is_rejected_by_database_but_other_pairs_are_allowed(): void
    {
        $this->link([$this->left[0]], array_slice($this->right, 0, 2));
        $this->assertDatabaseCount('catalog_links', 2);
        $this->expectException(QueryException::class);
        DB::table('catalog_links')->insert(['session_id' => $this->sessionId, 'fifty_id' => $this->left[0], 'pemex_id' => $this->right[0]]);
    }

    public function test_bulk_revalidates_session_side_and_empty_reference_atomically(): void
    {
        $row = DB::table('catalog_rows')->where('id', $this->left[0])->first();
        $otherSession = DB::table('reconciliation_sessions')->insertGetId(['name' => 'Otra', 'created_by' => auth()->id()]);
        foreach ([
            [$otherSession, $this->versions([$this->left[0]]), $this->versions([$this->right[0]])],
            [$this->sessionId, $this->versions([$this->right[0]]), $this->versions([$this->left[0]])],
        ] as [$sessionId, $left, $right]) {
            try {
                app(ReconciliationDecisions::class)->linkMany($sessionId, $left, $right, true);
                $this->fail('Debe revalidar sesión y lado.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('decision', $e->errors());
            }
        }
        DB::table('catalog_rows')->where('id', $this->left[1])->update(['reference' => '']);
        $this->selectedBoard()->call('decide', 'link')->assertHasErrors('decision')->assertSet('selected.fifty', $this->versions(array_slice($this->left, 0, 2)));
        $this->assertDatabaseCount('catalog_links', 0);
    }

    public function test_conflict_after_review_keeps_selection_and_creates_no_partial_links(): void
    {
        $component = $this->selectedBoard()->call('decide', 'link')->assertSet('confirmation.newCount', 6);
        $this->link([$this->left[2]], [$this->right[2]]);
        $component->call('confirm')->assertHasErrors('conflict')->assertSet('confirmation', null)
            ->assertSet('selected.fifty', $this->versions(array_slice($this->left, 0, 2)))->assertSet('selected.pemex', $this->versions($this->right));
        $this->assertDatabaseCount('catalog_links', 1);
        $component->call('decide', 'link')->call('confirm')->assertHasNoErrors();
        $this->assertDatabaseCount('catalog_links', 7);
    }

    public function test_classifications_are_replaced_and_classifying_linked_row_requires_explicit_link_removal(): void
    {
        $service = app(ReconciliationDecisions::class);
        $service->decide($this->sessionId, 'outside', $this->left[0], 0);
        $service->decide($this->sessionId, 'new', $this->right[0], 0);
        $this->selectedBoard()->call('decide', 'link')->assertSee('Se sustituirá la clasificación')->call('confirm')->assertHasNoErrors();
        $row = DB::table('catalog_rows')->where('id', $this->left[0])->first();
        try {
            $service->decide($this->sessionId, 'outside', $row->id, $row->version);
            $this->fail('Debe exigir confirmación para eliminar vínculos.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('confirmation', $e->errors());
        }
        $this->assertDatabaseCount('catalog_links', 6);
        $this->board()->call('decide', 'outside', 'fifty', $row->id, $row->version)->assertSee('eliminará todas las equivalencias')
            ->call('confirm')->assertHasNoErrors();
        $this->assertDatabaseCount('catalog_links', 3);
        $this->assertDatabaseHas('catalog_rows', ['id' => $row->id, 'status' => 'outside']);
        $this->assertSame(3, DB::table('catalog_rows')->whereIn('id', $this->right)->where('status', 'linked')->count());
    }

    public function test_removing_one_link_preserves_others_and_last_link_returns_both_rows_to_pending(): void
    {
        $this->link(array_slice($this->left, 0, 2), array_slice($this->right, 0, 2));
        $service = app(ReconciliationDecisions::class);
        $links = DB::table('catalog_links')->orderBy('id')->get();
        foreach ($links as $index => $link) {
            $versions = $this->versions([$link->fifty_id, $link->pemex_id]);
            $component = $this->board()->call('requestRemoveLink', $link->id, $versions[$link->fifty_id], $versions[$link->pemex_id])->assertSee('únicamente este vínculo');
            $this->assertDatabaseCount('catalog_links', 4 - $index);
            $component->call('confirm')->assertHasNoErrors();
            $this->assertDatabaseCount('catalog_links', 3 - $index);
        }
        $this->assertSame(4, DB::table('catalog_rows')->whereIn('id', array_merge(array_slice($this->left, 0, 2), array_slice($this->right, 0, 2)))->where('status', 'pending')->count());
        $this->assertSame(4, DB::table('reconciliation_audits')->where('action', 'remove_link')->count());
    }

    public function test_specific_link_removal_checks_both_endpoint_versions(): void
    {
        $this->link([$this->left[0]], [$this->right[0]]);
        $link = DB::table('catalog_links')->first();
        $oldVersions = $this->versions([$link->fifty_id, $link->pemex_id]);
        $this->link([$this->left[1]], [$this->right[0]]);
        $this->expectException(ValidationException::class);
        app(ReconciliationDecisions::class)->removeLink($this->sessionId, $link->id, $oldVersions, true);
    }

    public function test_many_to_many_exports_and_reports_keep_duplicate_reference_traceability_and_reviewer_privacy(): void
    {
        $this->link(array_slice($this->left, 0, 2), $this->right);
        $workbook = app(CatalogWorkbook::class);
        $left = $workbook->export($this->sessionId, 'fifty', true)->getActiveSheet();
        $right = $workbook->export($this->sessionId, 'pemex', true)->getActiveSheet();
        $this->assertSame('R01;R02;R03', $left->getCell('D2')->getValue());
        $this->assertSame('001;001', $right->getCell('D2')->getValue());
        $this->assertSame('', $left->getCell('D4')->getValue());
        $report = $workbook->report($this->sessionId);
        $this->assertSame(7, $report->getSheetByName('Múltiples desde izquierda')->getHighestDataRow());
        $this->assertSame(7, $report->getSheetByName('Relaciones múltiples')->getHighestDataRow());
        $this->assertSame('2', $report->getSheetByName('Relaciones múltiples')->getCell('H2')->getValue());
        $this->assertSame('3', $report->getSheetByName('Relaciones múltiples')->getCell('H3')->getValue());
        $reviewer = User::factory()->create()->refresh();
        $this->actingAs($reviewer);
        $this->selectedBoard()->call('decide', 'link')->assertDontSee('987654.32')->assertDontSee('Costo');
        $leftSafe = $workbook->export($this->sessionId, 'fifty', false)->getActiveSheet();
        $this->assertSame('R01;R02;R03', $leftSafe->getCell('C2')->getValue());
        $this->assertStringNotContainsString('987654.32', json_encode($leftSafe->toArray(null, false, false)));
        foreach ($workbook->report($this->sessionId)->getAllSheets() as $sheet) {
            $this->assertStringNotContainsString('987654.32', json_encode($sheet->toArray(null, false, false)));
        }
    }

    public function test_migration_preserves_existing_link_ids_rows_and_audits(): void
    {
        $this->link([$this->left[0]], [$this->right[0]]);
        $link = DB::table('catalog_links')->first();
        $migration = require database_path('migrations/2026_10_10_012437_allow_many_to_many_catalog_links.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('catalog_links', ['id' => $link->id, 'fifty_id' => $this->left[0], 'pemex_id' => $this->right[0]]);
        $this->assertDatabaseHas('catalog_rows', ['id' => $this->left[0], 'status' => 'linked', 'version' => 1]);
        $this->assertDatabaseCount('reconciliation_audits', 1);
        $this->assertSame(1, $this->link([$this->left[0]], [$this->right[1]]));
    }

    public function test_stale_link_removal_shows_conflict_and_preserves_selection(): void
    {
        $this->link([$this->left[0]], [$this->right[0]]);
        $link = DB::table('catalog_links')->first();
        $versions = $this->versions([$link->fifty_id, $link->pemex_id]);
        $component = $this->board()->call('select', 'fifty', $link->fifty_id);
        app(ReconciliationDecisions::class)->removeLink($this->sessionId, $link->id, $versions, true);
        $component->call('requestRemoveLink', $link->id, $versions[$link->fifty_id], $versions[$link->pemex_id])
            ->assertHasErrors('conflict')->assertSet('selected.fifty', $this->versions([$link->fifty_id]));
        $this->assertDatabaseCount('catalog_links', 0);
    }

    public function test_server_requires_explicit_confirmation_for_bulk_equivalence(): void
    {
        $this->expectException(ValidationException::class);
        app(ReconciliationDecisions::class)->linkMany($this->sessionId, $this->versions(array_slice($this->left, 0, 2)), $this->versions($this->right));
    }
}
