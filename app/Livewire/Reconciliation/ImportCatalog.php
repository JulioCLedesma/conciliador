<?php

namespace App\Livewire\Reconciliation;

use App\Services\CatalogConfiguration;
use App\Services\CatalogWorkbook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class ImportCatalog extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $sessionId;

    public string $side = 'fifty';

    public $file;

    public string $sheet = '';

    public int $headerRow = 1;

    public string $reference = '';

    public array $descriptions = [];

    public array $sensitive = [];

    public string $result = '';

    public string $resultName = '';

    public string $classificationText = '';

    #[Locked]
    public array $sheets = [];

    #[Locked]
    public array $headers = [];

    #[Locked]
    public array $preview = [];

    #[Locked]
    public array $warnings = [];

    #[Locked]
    public ?string $reviewSignature = null;

    public function boot(): void
    {
        app()->setLocale('es');
    }

    public function mount(int $sessionId): void
    {
        Gate::authorize('importar-catalogos');
        DB::table('reconciliation_sessions')->where('id', $sessionId)->firstOrFail();
        $this->sessionId = $sessionId;
        $this->setDefaults();
    }

    public function updatedSide(): void
    {
        $this->reset('file', 'sheet', 'sheets', 'headers', 'preview', 'warnings', 'reviewSignature', 'reference', 'descriptions', 'sensitive', 'result');
        $this->setDefaults();
    }

    private function setDefaults(): void
    {
        $this->validate(['side' => 'in:fifty,pemex']);
        $session = DB::table('reconciliation_sessions')->where('id', $this->sessionId)->firstOrFail();
        $defaults = CatalogConfiguration::defaults($session, $this->side);
        $this->resultName = $defaults['result_name'];
        $this->classificationText = $defaults['classification_text'];
    }

    public function updatedFile(): void
    {
        Gate::authorize('importar-catalogos');
        $this->reset('headers', 'preview', 'warnings', 'reviewSignature', 'reference', 'descriptions', 'sensitive', 'result');
        $this->validate(['file' => 'required|file|extensions:xlsx|max:10240'], ['file.extensions' => 'Carga un archivo .xlsx.', 'file.max' => 'El máximo es 10 MB.']);
        $this->sheets = app(CatalogWorkbook::class)->sheets($this->file->getRealPath());
        $session = DB::table('reconciliation_sessions')->where('id', $this->sessionId)->firstOrFail();
        $names = CatalogConfiguration::names($session);
        $this->sheet = mb_strtoupper($names[$this->side]) === 'PEMEX' && in_array('PARTIDA 4', $this->sheets, true) ? 'PARTIDA 4' : $this->sheets[0];
        $this->loadPreview();
    }

    public function loadPreview(): void
    {
        Gate::authorize('importar-catalogos');
        $this->validate(['file' => 'required|file|extensions:xlsx|max:10240', 'headerRow' => 'required|integer|min:1|max:20000']);
        $data = app(CatalogWorkbook::class)->read($this->file->getRealPath(), $this->sheet, $this->headerRow);
        $this->headers = $data['headers'];
        $this->preview = array_slice($data['rows'], 0, 5, true);
        $this->reset('warnings', 'reviewSignature', 'reference', 'descriptions', 'sensitive', 'result');
        $session = DB::table('reconciliation_sessions')->where('id', $this->sessionId)->firstOrFail();
        $defaults = CatalogConfiguration::defaults($session, $this->side);
        foreach ($this->headers as $column => $header) {
            if (mb_strtoupper(trim($header)) === $defaults['reference_name']) {
                $this->reference = (string) $column;
            }
            if (trim($header) === $this->resultName) {
                $this->result = (string) $column;
            }
        }
    }

    private function configuration(): array
    {
        $this->validate([
            'file' => 'required|file|extensions:xlsx|max:10240', 'side' => 'in:fifty,pemex', 'headerRow' => 'integer|min:1|max:20000',
            'reference' => 'required|integer|min:0', 'descriptions' => 'required|array|min:1', 'descriptions.*' => 'integer|min:0',
            'sensitive' => 'array', 'sensitive.*' => 'integer|min:0', 'result' => 'nullable|integer|min:0', 'resultName' => 'required|string|max:255', 'classificationText' => 'required|string|max:255',
        ], [], ['reference' => 'columna de referencia', 'descriptions' => 'columnas de descripción', 'resultName' => 'nombre de resultado', 'classificationText' => 'texto de clasificación']);
        $data = app(CatalogWorkbook::class)->read($this->file->getRealPath(), $this->sheet, $this->headerRow);
        $columns = range(0, count($data['headers']) - 1);
        $reference = (int) $this->reference;
        $descriptions = array_map('intval', $this->descriptions);
        $sensitive = array_map('intval', $this->sensitive);
        $result = $this->result === '' ? null : (int) $this->result;
        if (array_diff(array_merge([$reference], $descriptions, $sensitive, $result === null ? [] : [$result]), $columns)
            || array_intersect(array_merge([$reference], $descriptions, $result === null ? [] : [$result]), $sensitive)
            || ($result !== null && in_array($result, array_merge([$reference], $descriptions), true))) {
            throw ValidationException::withMessages(['mapping' => 'Revisa las columnas. No uses columnas sensibles como referencia, descripción o resultado, ni sobrescribas referencia o descripción.']);
        }

        return [$data, $reference, $descriptions, $sensitive, $result];
    }

    private function signature(): string
    {
        return hash('sha256', json_encode([$this->file->getFilename(), $this->side, $this->sheet, $this->headerRow, $this->reference, $this->descriptions, $this->sensitive, $this->result, $this->resultName, $this->classificationText]));
    }

    public function review(): void
    {
        Gate::authorize('importar-catalogos');
        [$data, $reference, $descriptions] = $this->configuration();
        $this->warnings = app(CatalogWorkbook::class)->inspect($data, $reference, $descriptions);
        $this->reviewSignature = $this->signature();
    }

    public function import(): void
    {
        Gate::authorize('importar-catalogos');
        [, $reference, $descriptions, $sensitive, $result] = $this->configuration();
        if ($this->reviewSignature !== $this->signature()) {
            $this->addError('mapping', 'Revisa los conteos y advertencias de esta configuración antes de confirmar.');

            return;
        }
        $path = $this->file->store('catalogs/'.$this->sessionId, 'local');
        try {
            app(CatalogWorkbook::class)->import($this->sessionId, $this->side, $path, $this->file->getClientOriginalName(), $this->sheet, $this->headerRow, $reference, $descriptions, $sensitive, $result, $this->resultName, $this->classificationText);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        $this->updatedSide();
        session()->flash('success', 'Catálogo importado. El archivo y sus filas se conservarán en esta sesión.');
    }

    public function render(): View
    {
        Gate::authorize('importar-catalogos');

        return view('livewire.reconciliation.import-catalog', ['session' => DB::table('reconciliation_sessions')->where('id', $this->sessionId)->firstOrFail(), 'catalogs' => DB::table('catalogs')->where('session_id', $this->sessionId)->get(['id', 'side', 'filename', 'sheet', 'result_name', 'classification_text'])]);
    }
}
