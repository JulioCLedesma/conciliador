<?php

namespace App\Livewire\Reconciliation;

use App\Services\CatalogWorkbook;
use App\Services\ReconciliationDecisions;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class Board extends Component
{
    #[Locked]
    public int $sessionId;

    public array $search = ['fifty' => '', 'pemex' => ''];

    public array $status = ['fifty' => '', 'pemex' => ''];

    public array $perPage = ['fifty' => 10, 'pemex' => 10];

    #[Locked]
    public array $loadedIds = ['fifty' => [], 'pemex' => []];

    #[Locked]
    public array $cursor = ['fifty' => null, 'pemex' => null];

    #[Locked]
    public array $generation = ['fifty' => 0, 'pemex' => 0];

    #[Locked]
    public array $hasMore = ['fifty' => false, 'pemex' => false];

    #[Locked]
    public array $selected = ['fifty' => [], 'pemex' => []];

    #[Locked]
    public ?array $confirmation = null;

    public function boot(): void
    {
        app()->setLocale('es');
    }

    public function mount(int $sessionId): void
    {
        Gate::authorize('conciliar-catalogos');
        DB::table('reconciliation_sessions')->where('id', $sessionId)->firstOrFail();
        $this->sessionId = $sessionId;
        foreach (['fifty', 'pemex'] as $side) {
            $this->appendBlock($side);
        }
    }

    public function updated(string $property): void
    {
        foreach (['fifty', 'pemex'] as $side) {
            if (in_array($property, ['search.'.$side, 'status.'.$side, 'perPage.'.$side], true)) {
                $this->loadedIds[$side] = [];
                $this->cursor[$side] = null;
                $this->generation[$side]++;
                $this->appendBlock($side);
                $this->dispatch('catalog-results-reset', side: $side);
            }
        }
    }

    public function loadMore(string $side, int $expectedCount, int $generation): void
    {
        $this->authorizeSide($side);
        if ($generation !== $this->generation[$side] || $expectedCount !== count($this->loadedIds[$side]) || ! $this->hasMore[$side]) {
            return;
        }
        $this->appendBlock($side);
    }

    public function clearAllSelections(): void
    {
        $this->clearSelection('fifty');
        $this->clearSelection('pemex');
    }

    public function select(string $side, int $id): void
    {
        $this->authorizeSide($side);
        if (array_key_exists($id, $this->selected[$side])) {
            unset($this->selected[$side][$id]);
        } else {
            $row = DB::table('catalog_rows')->where('session_id', $this->sessionId)->where('side', $side)->where('id', $id)->firstOrFail();
            $this->selected[$side][$id] = (int) $row->version;
        }
        $this->confirmation = null;
        $this->resetErrorBag();
    }

    public function selectLoaded(string $side): void
    {
        $this->authorizeSide($side);
        foreach ($this->loadedRows($side) as $row) {
            if (! array_key_exists($row->id, $this->selected[$side])) {
                $this->selected[$side][$row->id] = (int) $row->version;
            }
        }
        $this->confirmation = null;
    }

    public function deselectLoaded(string $side): void
    {
        $this->authorizeSide($side);
        foreach ($this->loadedRows($side) as $row) {
            unset($this->selected[$side][$row->id]);
        }
        $this->confirmation = null;
    }

    public function clearSelection(string $side): void
    {
        $this->authorizeSide($side);
        $this->selected[$side] = [];
        $this->confirmation = null;
    }

    public function decide(string $action, string $side = 'fifty', ?int $id = null, ?int $version = null): void
    {
        $this->authorizeSide($side);
        $this->resetErrorBag();
        $this->confirmation = null;
        try {
            if ($action === 'link') {
                $review = app(ReconciliationDecisions::class)->reviewLinks($this->sessionId, $this->selected['fifty'], $this->selected['pemex']);
                $this->confirmation = array_merge(['action' => 'link', 'selection' => $this->selected], $review);

                return;
            }
            abort_unless(in_array($action, ['outside', 'new', 'undo'], true), 404);
            if ($id === null && count($this->selected[$side]) === 1) {
                $id = array_key_first($this->selected[$side]);
            }
            if ($id === null) {
                $this->addError('decision', 'Elige un producto para clasificar o deshacer su decisión.');

                return;
            }
            $row = DB::table('catalog_rows')->where('session_id', $this->sessionId)->where('side', $side)->where('id', $id)->firstOrFail();
            $expected = $this->selected[$side][$id] ?? $version ?? (int) $row->version;
            if ($expected !== (int) $row->version) {
                throw ValidationException::withMessages(['conflict' => 'Otra persona modificó este producto. Revisa su estado antes de confirmar de nuevo.']);
            }
            $this->confirmation = ['action' => $action, 'side' => $side, 'id' => $id, 'version' => $expected];
        } catch (ValidationException $e) {
            $this->showErrors($e);
        }
    }

    public function requestRemoveLink(int $linkId, int $leftVersion, int $rightVersion): void
    {
        Gate::authorize('conciliar-catalogos');
        $this->resetErrorBag();
        $link = DB::table('catalog_links')->where('session_id', $this->sessionId)->where('id', $linkId)->first();
        if (! $link) {
            $this->confirmation = null;
            $this->refreshSelections();
            $this->addError('conflict', 'Esta equivalencia ya no está disponible. Revisa el estado actualizado del producto.');

            return;
        }
        $this->confirmation = ['action' => 'remove_link', 'linkId' => $linkId, 'versions' => [$link->fifty_id => $leftVersion, $link->pemex_id => $rightVersion]];
    }

    public function confirm(): void
    {
        Gate::authorize('conciliar-catalogos');
        if (! $this->confirmation) {
            return;
        }
        $this->resetErrorBag();
        $request = $this->confirmation;
        try {
            $service = app(ReconciliationDecisions::class);
            if ($request['action'] === 'link') {
                $count = $service->linkMany($this->sessionId, $request['selection']['fifty'], $request['selection']['pemex'], true);
                $this->selected = ['fifty' => [], 'pemex' => []];
                session()->flash('success', 'Equivalencias confirmadas: '.$count.' vínculos nuevos.');
            } elseif ($request['action'] === 'remove_link') {
                $service->removeLink($this->sessionId, $request['linkId'], $request['versions'], true);
                $this->refreshSelections();
                session()->flash('success', 'Equivalencia retirada. Las demás se conservaron.');
            } else {
                $service->decide($this->sessionId, $request['action'], $request['id'], $request['version'], confirmed: true);
                $this->refreshSelections();
                session()->flash('success', 'Decisión guardada.');
            }
            $this->confirmation = null;
        } catch (ValidationException $e) {
            $this->showErrors($e);
        } catch (QueryException $e) {
            $this->confirmation = null;
            $this->addError('decision', 'No se pudo guardar la decisión. La selección se conserva; vuelve a revisar y confirmar.');
        }
    }

    public function cancel(): void
    {
        Gate::authorize('conciliar-catalogos');
        $this->confirmation = null;
        $this->resetErrorBag();
    }

    private function showErrors(ValidationException $e): void
    {
        $this->confirmation = null;
        foreach ($e->errors() as $key => $messages) {
            $this->addError($key, $messages[0]);
        }
        if (isset($e->errors()['conflict'])) {
            $this->refreshSelections();
        }
    }

    private function refreshSelections(): void
    {
        foreach (['fifty', 'pemex'] as $side) {
            $versions = DB::table('catalog_rows')->where('session_id', $this->sessionId)->where('side', $side)->whereIn('id', array_keys($this->selected[$side]))->pluck('version', 'id')->all();
            $this->selected[$side] = $versions;
        }
    }

    private function authorizeSide(string $side): void
    {
        Gate::authorize('conciliar-catalogos');
        abort_unless(in_array($side, ['fifty', 'pemex'], true), 404);
    }

    private function resultQuery(string $side): Builder
    {
        $query = DB::table('catalog_rows')->where('session_id', $this->sessionId)->where('side', $side);
        $search = mb_substr((string) ($this->search[$side] ?? ''), 0, 200);
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'));
        }
        if (in_array($this->status[$side] ?? '', ['pending', 'linked', 'outside', 'new'], true)) {
            $query->where('status', $this->status[$side]);
        }

        return $query;
    }

    private function appendBlock(string $side): void
    {
        $query = $this->resultQuery($side)->whereNotIn('id', $this->loadedIds[$side]);
        if ($cursor = $this->cursor[$side]) {
            $query->where(fn ($q) => $q->where('original_row', '>', $cursor[0])
                ->orWhere(fn ($tie) => $tie->where('original_row', $cursor[0])->where('id', '>', $cursor[1])));
        }
        $size = (int) ($this->perPage[$side] ?? 10);
        $size = in_array($size, [10, 20, 50], true) ? $size : 10;
        $rows = $query->orderBy('original_row')->orderBy('id')->limit($size + 1)->get(['id', 'original_row']);
        $this->hasMore[$side] = $rows->count() > $size;
        $block = $rows->take($size);
        $this->loadedIds[$side] = array_merge($this->loadedIds[$side], $block->pluck('id')->all());
        if ($last = $block->last()) {
            $this->cursor[$side] = [(int) $last->original_row, (int) $last->id];
        }
    }

    private function loadedRows(string $side): Collection
    {
        return DB::table('catalog_rows')->where('session_id', $this->sessionId)->where('side', $side)
            ->whereIn('id', $this->loadedIds[$side])->orderBy('original_row')->orderBy('id')->get();
    }

    public function render(): View
    {
        Gate::authorize('conciliar-catalogos');
        $catalogs = DB::table('catalogs')->where('session_id', $this->sessionId)->get()->keyBy('side');
        $panels = [];
        $workbook = app(CatalogWorkbook::class);
        $visibleIds = [];
        foreach (['fifty', 'pemex'] as $side) {
            $catalog = $catalogs->get($side);
            if (! $catalog) {
                continue;
            }
            $rows = $this->loadedRows($side)->map(fn ($row) => $workbook->safeRow($row, $catalog, Gate::allows('ver-importes')));
            $selections = DB::table('catalog_rows')->where('catalog_id', $catalog->id)->whereIn('id', array_keys($this->selected[$side]))->orderBy('original_row')->get()
                ->map(fn ($row) => $workbook->safeRow($row, $catalog, Gate::allows('ver-importes')))->all();
            $visibleIds = array_merge($visibleIds, array_column($rows->all(), 'id'), array_column($selections, 'id'));
            $panels[$side] = ['classification' => $catalog->classification_text, 'rows' => $rows, 'selections' => $selections,
                'total' => $this->resultQuery($side)->count() + DB::table('catalog_rows')->whereIn('id', $this->loadedIds[$side])->whereNotIn('id', $this->resultQuery($side)->select('id'))->count(),
                'counts' => DB::table('catalog_rows')->where('catalog_id', $catalog->id)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all()];
        }
        if ($this->confirmation) {
            $visibleIds = array_merge($visibleIds, isset($this->confirmation['id']) ? [$this->confirmation['id']] : array_keys($this->confirmation['versions'] ?? []));
        }
        $links = DB::table('catalog_links')->join('catalog_rows as left_row', 'left_row.id', '=', 'catalog_links.fifty_id')->join('catalog_rows as right_row', 'right_row.id', '=', 'catalog_links.pemex_id')
            ->where('catalog_links.session_id', $this->sessionId)->where(fn ($q) => $q->whereIn('fifty_id', $visibleIds)->orWhereIn('pemex_id', $visibleIds))->orderBy('catalog_links.id')
            ->get(['catalog_links.id', 'fifty_id', 'pemex_id', 'left_row.reference as left_reference', 'right_row.reference as right_reference',
                'left_row.description as left_description', 'right_row.description as right_description', 'left_row.original_row as left_original_row', 'right_row.original_row as right_original_row',
                'left_row.version as left_version', 'right_row.version as right_version']);
        $decisionRow = null;
        if ($this->confirmation && isset($this->confirmation['id'])) {
            $side = $this->confirmation['side'];
            $row = DB::table('catalog_rows')->where('session_id', $this->sessionId)->where('side', $side)->where('id', $this->confirmation['id'])->first();
            if ($row && $catalogs->has($side)) {
                $decisionRow = $workbook->safeRow($row, $catalogs[$side], Gate::allows('ver-importes'));
            }
        }

        return view('livewire.reconciliation.board', ['session' => DB::table('reconciliation_sessions')->where('id', $this->sessionId)->firstOrFail(), 'panels' => $panels, 'links' => $links, 'decisionRow' => $decisionRow]);
    }
}
