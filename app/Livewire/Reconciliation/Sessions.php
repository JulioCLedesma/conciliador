<?php

namespace App\Livewire\Reconciliation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Sessions extends Component
{
    use WithPagination;

    public string $name = '';

    public string $leftCatalogName = 'Fifty';

    public string $rightCatalogName = 'PEMEX';

    public function boot(): void
    {
        app()->setLocale('es');
    }

    public function create(): void
    {
        Gate::authorize('importar-catalogos');
        $this->validate([
            'name' => 'required|string|max:255',
            'leftCatalogName' => 'required|string|max:100',
            'rightCatalogName' => 'required|string|max:100',
        ], ['name.required' => 'Escribe el nombre de la sesión.'], [
            'leftCatalogName' => 'nombre del catálogo izquierdo',
            'rightCatalogName' => 'nombre del catálogo derecho',
        ]);
        $id = DB::table('reconciliation_sessions')->insertGetId(['name' => trim($this->name), 'left_catalog_name' => trim($this->leftCatalogName), 'right_catalog_name' => trim($this->rightCatalogName), 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        $this->redirectRoute('reconciliation.import', ['sessionId' => $id]);
    }

    public function render(): View
    {
        Gate::authorize('conciliar-catalogos');

        return view('livewire.reconciliation.sessions', ['sessions' => DB::table('reconciliation_sessions')->orderByDesc('id')->paginate(20)]);
    }
}
