<div class="max-w-7xl mx-auto p-4 sm:p-8">
    @php($names = \App\Services\CatalogConfiguration::names($session))
    <div class="flex flex-wrap justify-between gap-4 mb-6"><h1 class="text-2xl font-semibold">Configurar: {{ $session->name }}</h1><a class="text-indigo-700 underline" href="{{ route('reconciliation.board', $sessionId) }}">Abrir tablero</a></div>
    <x-reconciliation-messages />
    <div class="bg-white p-6 rounded shadow mb-6">
        <h2 class="font-semibold mb-3">Archivos importados</h2>
        @forelse ($catalogs as $catalog)
            <p class="mb-2">{{ $names[$catalog->side] }}: {{ $catalog->filename }} · Hoja {{ $catalog->sheet }} · Resultado: {{ $catalog->result_name }} · Clasificación: {{ $catalog->classification_text }} <a class="text-indigo-700 underline" href="{{ route('reconciliation.original', $catalog->id) }}">Descargar original</a></p>
        @empty<p>Aún no se ha importado ningún catálogo.</p>@endforelse
        <p class="text-sm text-gray-600 mt-3">Cada lado se importa una sola vez. Para otros archivos, crea una nueva sesión.</p>
    </div>
    @if ($catalogs->count() < 2)
    <div class="bg-white p-6 rounded shadow space-y-5">
        <label class="block">Catálogo<select wire:model.live="side" class="block rounded border-gray-300"><option value="fifty">{{ $names['fifty'] }} (izquierda)</option><option value="pemex">{{ $names['pemex'] }} (derecha)</option></select></label>
        @if ($catalogs->contains('side', $side))
            <p>Este lado ya está importado. Selecciona el otro catálogo.</p>
        @else
        <label class="block">Archivo .xlsx (máximo 10 MB, 20,000 filas, 200 columnas y 200,000 celdas)<input type="file" accept=".xlsx" wire:model="file" class="block mt-2" /></label>
        <p wire:loading wire:target="file,loadPreview,review,import" role="status">Procesando archivo…</p>
        @if ($sheets)
            <div class="flex flex-wrap gap-4 items-end">
                <label>Hoja<select wire:model="sheet" class="block rounded border-gray-300">@foreach ($sheets as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select></label>
                <label>Fila de encabezados<x-input type="number" min="1" wire:model="headerRow" class="block w-40" /></label>
                <x-button wire:click="loadPreview" wire:loading.attr="disabled">Actualizar vista previa</x-button>
            </div>
            <p class="text-sm text-gray-600">Se importa y exporta únicamente la hoja seleccionada. @if (mb_strtoupper($names[$side]) === 'PEMEX') Para el archivo PEMEX inicial selecciona PARTIDA 4. @endif</p>
        @endif
        @if ($headers)
            <div class="overflow-x-auto border rounded">
                <table class="text-sm w-full text-left"><thead><tr><th class="p-2">Fila</th>@foreach ($headers as $column => $header)<th class="p-2 whitespace-nowrap">{{ \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) }} · {{ $header ?: '(sin encabezado)' }}</th>@endforeach</tr></thead>
                <tbody>@foreach ($preview as $number => $values)<tr class="border-t"><td class="p-2">{{ $number }}</td>@foreach ($values as $value)<td class="p-2"><div class="max-w-xs max-h-24 overflow-auto">{{ $value }}</div></td>@endforeach</tr>@endforeach</tbody></table>
            </div>
            <div class="grid gap-6 md:grid-cols-2">
                <label>Columna de referencia<select wire:model="reference" class="block w-full rounded border-gray-300"><option value="">Selecciona una columna</option>@foreach ($headers as $column => $header)<option value="{{ $column }}">{{ \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) }} · {{ $header ?: '(sin encabezado)' }}</option>@endforeach</select></label>
                <div><p>Descripciones para buscar (una o varias)</p>@foreach ($headers as $column => $header)<label class="block"><x-checkbox wire:model="descriptions" value="{{ $column }}" /> {{ \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) }} · {{ $header ?: '(sin encabezado)' }}</label>@endforeach</div>
                <div><p>Columnas sensibles: precios y costos</p><p class="text-sm text-gray-600">Marca todas las columnas con importes. Los revisores no recibirán estos valores.</p>@foreach ($headers as $column => $header)<label class="block"><x-checkbox wire:model="sensitive" value="{{ $column }}" /> {{ \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) }} · {{ $header ?: '(sin encabezado)' }}</label>@endforeach</div>
                <div class="space-y-3"><label class="block">Columna de resultado<select wire:model="result" class="block w-full rounded border-gray-300"><option value="">Agregar columna al final</option>@foreach ($headers as $column => $header)<option value="{{ $column }}">{{ \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1) }} · {{ $header ?: '(sin encabezado)' }}</option>@endforeach</select></label><label class="block">Nombre de la columna de resultado<x-input wire:model="resultName" class="block w-full" /></label><p class="text-sm text-gray-600">Recibirá las referencias de {{ $side === 'fifty' ? $names['pemex'] : $names['fifty'] }}.</p><label class="block">Texto de clasificación para productos sin equivalente<x-input wire:model="classificationText" class="block w-full" /></label></div>
            </div>
            <x-button wire:click="review" wire:loading.attr="disabled">Revisar importación</x-button>
            @if ($warnings)
                <div class="bg-amber-50 border border-amber-300 rounded p-4 space-y-3">
                    <h2 class="font-semibold">Revisión antes de importar</h2>
                    <p>{{ $warnings['count'] }} filas · {{ count($warnings['empty']) }} referencias vacías · {{ count($warnings['duplicates']) }} filas con referencia duplicada · {{ $warnings['formulas'] }} fórmulas conservadas como texto.</p>
                    <p>Las referencias vacías se conservan, pero no pueden relacionarse. Los duplicados se revisan individualmente. No se inventan referencias ni se usa el consecutivo.</p>
                    @foreach (['empty' => 'Referencias vacías', 'duplicates' => 'Referencias duplicadas'] as $key => $label)
                        @if ($warnings[$key])<details><summary class="cursor-pointer">{{ $label }}</summary><div class="max-h-64 overflow-auto">@foreach ($warnings[$key] as $row)<p>Fila {{ $row['row'] }} · {{ $row['reference'] ?: '(vacía)' }} · {{ $row['description'] }}</p>@endforeach</div></details>@endif
                    @endforeach
                    <x-button wire:click="import" wire:confirm="¿Confirmas importar este catálogo con la configuración revisada?" wire:loading.attr="disabled">Confirmar importación</x-button>
                </div>
            @endif
        @endif
        @endif
    </div>
    @endif
</div>
