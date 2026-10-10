<div class="reconciliation-board max-w-7xl mx-auto p-4 sm:p-6" x-data="reconciliationBoard()" wire:ignore.self>
    @php($names = \App\Services\CatalogConfiguration::names($session))
    <div class="flex flex-wrap justify-between gap-4 mb-4"><h1 class="text-2xl font-semibold">{{ $session->name }}</h1><a class="text-indigo-700 underline" href="{{ route('reconciliation.sessions') }}">Todas las sesiones</a></div>
    <x-reconciliation-messages />
    @if (count($panels) < 2)<p class="bg-amber-50 p-4 rounded mb-4">Falta importar uno o ambos catálogos. @can('importar-catalogos')<a class="underline" href="{{ route('reconciliation.import', $sessionId) }}">Configurar archivos</a>@else Solicita a un administrador que complete la importación. @endcan</p>@endif
    <div class="bg-white rounded shadow p-4 mb-6 flex flex-wrap gap-4 items-center">
        @can('exportar-catalogos')
            @foreach (['fifty', 'pemex', 'report'] as $kind)
                @if ($kind === 'report' || isset($panels[$kind]))<a class="text-indigo-700 underline" href="{{ route('reconciliation.export', [$sessionId, $kind]) }}">{{ \App\Services\CatalogConfiguration::downloadName($session, $kind) }}</a>@endif
            @endforeach
        @endcan
    </div>
    @if ($confirmation)
        <div role="alertdialog" aria-modal="true" aria-label="Confirmar decisión" x-init="$nextTick(() => $el.querySelector('button').focus({ preventScroll: true }))" class="fixed inset-4 z-50 overflow-y-auto shadow-xl border border-amber-400 bg-amber-50 p-4 rounded space-y-3">
            @if ($confirmation['action'] === 'link')
                <h2 class="font-semibold text-lg">Confirmar equivalencias</h2>
                <p>Todos los productos seleccionados del catálogo izquierdo se relacionarán con todos los seleccionados del catálogo derecho.</p>
                <p>{{ count($selected['fifty']) }} × {{ count($selected['pemex']) }}: <strong>{{ $confirmation['newCount'] }} vínculos nuevos</strong>. {{ $confirmation['existingCount'] }} vínculos de esta combinación ya existen y no se duplicarán.</p>
                <div class="grid sm:grid-cols-2 gap-4">
                    @foreach ($panels as $side => $panel)
                        <div><h3 class="font-semibold">{{ $names[$side] }}</h3><div class="max-h-64 overflow-auto">
                            @foreach ($panel['selections'] as $row)
                                <p class="py-1">{{ $row['reference'] ?: '(referencia vacía)' }} · Fila {{ $row['original_row'] }} · {{ $row['description'] }}</p>
                                @if (in_array($row['status'], ['outside','new'], true))<p class="text-amber-900">Se sustituirá la clasificación «{{ $panel['classification'] }}» por Verificado.</p>@endif
                            @endforeach
                        </div></div>
                    @endforeach
                </div>
                @php($existingLinks = $links->filter(fn ($link) => array_key_exists($link->fifty_id, $selected['fifty']) || array_key_exists($link->pemex_id, $selected['pemex'])))
                @if ($existingLinks->isNotEmpty())<details open><summary class="cursor-pointer font-semibold">Equivalencias existentes que se conservarán ({{ $existingLinks->count() }})</summary><div class="max-h-64 overflow-auto">@foreach ($existingLinks as $link)<p>{{ $names['fifty'] }}: {{ $link->left_reference }} (fila {{ $link->left_original_row }}) ↔ {{ $names['pemex'] }}: {{ $link->right_reference }} (fila {{ $link->right_original_row }}) · {{ $link->left_description }} / {{ $link->right_description }}</p>@endforeach</div></details>@endif
            @elseif ($confirmation['action'] === 'remove_link')
                @php($link = $links->firstWhere('id', $confirmation['linkId']))
                <h2 class="font-semibold">Retirar una equivalencia</h2>
                @if ($link)<p>{{ $names['fifty'] }}: {{ $link->left_reference }} (fila {{ $link->left_original_row }}) ↔ {{ $names['pemex'] }}: {{ $link->right_reference }} (fila {{ $link->right_original_row }})</p>@endif
                <p>Se retirará únicamente este vínculo. Se conservarán los demás. Un producto que pierda su última equivalencia regresará a Pendiente.</p>
            @elseif ($decisionRow)
                @php($side = $confirmation['side'])
                @php($decisionLinks = $links->where($side.'_id', $decisionRow['id']))
                <h2 class="font-semibold">{{ $confirmation['action'] === 'undo' ? 'Deshacer decisión' : $panels[$side]['classification'] }}</h2>
                <p>{{ $names[$side] }}: {{ $decisionRow['reference'] ?: '(referencia vacía)' }} · Fila {{ $decisionRow['original_row'] }} · {{ $decisionRow['description'] }}</p>
                @if ($decisionLinks->isNotEmpty())<p class="font-semibold text-red-800">Esta acción eliminará todas las equivalencias de este producto ({{ $decisionLinks->count() }}). Confirma explícitamente su eliminación.</p><ul class="list-disc pl-5">@foreach ($decisionLinks as $link)<li>{{ $side === 'fifty' ? $link->right_reference : $link->left_reference }} · Fila {{ $side === 'fifty' ? $link->right_original_row : $link->left_original_row }}</li>@endforeach</ul>@endif
                <p>Se guardará {{ $confirmation['action'] === 'undo' ? 'el estado Pendiente' : 'la clasificación configurada' }}.</p>
            @endif
            <x-button wire:click="confirm" wire:loading.attr="disabled">Confirmar</x-button>
            <x-secondary-button wire:click="cancel">Cancelar</x-secondary-button>
        </div>
    @endif
    <aside aria-label="Leyenda de estados" class="mb-4 rounded bg-white p-4 shadow text-sm text-gray-900">
        <h2 class="font-semibold mb-2">Estados de revisión</h2>
        <div class="flex flex-wrap gap-3">
            <span class="rounded border border-green-300 bg-green-50 px-3 py-2">Verificado: tiene equivalencias confirmadas</span>
            <span class="rounded border border-yellow-300 bg-yellow-50 px-3 py-2">Amarillo: clasificación manual sin equivalente</span>
            <span class="rounded border border-gray-300 bg-white px-3 py-2">Pendiente: falta revisar</span>
            <span class="rounded border-2 border-indigo-600 px-3 py-2">Casilla marcada y borde índigo: seleccionado</span>
        </div>
    </aside>
    <div class="grid lg:grid-cols-2 gap-6">
        @foreach ($panels as $side => $panel)
            @php($labels = ['pending' => 'Pendiente', 'linked' => 'Verificado', ($side === 'fifty' ? 'outside' : 'new') => $panel['classification']])
            <section class="reconciliation-panel bg-white rounded shadow p-4 min-w-0" wire:key="panel-{{ $side }}" wire:ignore.self
                x-data="catalogPanel('{{ $side }}')"
                x-on:resize.window.debounce.100ms="fit()"
                x-on:catalog-results-reset.window="reset($event.detail.side)">
                <div class="reconciliation-controls">
                <h2 class="text-xl font-semibold mb-3">{{ $names[$side] }}</h2>
                <div class="text-sm flex flex-wrap gap-3 mb-4">@foreach ($labels as $state => $label)<span>{{ $label }}: {{ $panel['counts'][$state] ?? 0 }}</span>@endforeach</div>
                <div class="mb-4 space-y-3">
                    <label class="block">Buscar en {{ $names[$side] }} por referencia o descripción<x-input wire:model.live.debounce.300ms="search.{{ $side }}" class="block w-full" placeholder="Escribe para buscar…" /></label>
                    <div class="flex gap-4"><label>Estado<select wire:model.live="status.{{ $side }}" class="block rounded border-gray-300"><option value="">Todos</option>@foreach ($labels as $state => $label)<option value="{{ $state }}">{{ $label }}</option>@endforeach</select></label></div>
                </div>
                <div class="flex flex-wrap gap-3 mb-3 text-sm">
                    <button type="button" class="underline text-indigo-700" wire:click="selectLoaded('{{ $side }}')">Seleccionar cargados</button>
                    <button type="button" class="underline text-indigo-700" wire:click="deselectLoaded('{{ $side }}')">Deseleccionar cargados</button>
                    <button type="button" class="underline text-indigo-700" wire:click="clearSelection('{{ $side }}')">Limpiar selección</button>
                    <button type="button" class="underline text-amber-900" wire:click="decide('{{ $side === 'fifty' ? 'outside' : 'new' }}', '{{ $side }}')">{{ $panel['classification'] }}</button>
                </div>
                <details class="mb-4 rounded border border-indigo-300 bg-indigo-50 p-3">
                    <summary class="cursor-pointer font-semibold">{{ count($selected[$side]) }} seleccionados en {{ $names[$side] }} (incluye productos no visibles)</summary>
                    <div class="max-h-96 overflow-auto space-y-3 mt-3">
                        @forelse ($panel['selections'] as $row)
                            <div @class(['border-2 border-indigo-600 rounded p-3 text-gray-900', 'bg-green-50' => $row['status'] === 'linked', 'bg-yellow-50' => in_array($row['status'], ['outside', 'new'], true), 'bg-white' => $row['status'] === 'pending']) wire:key="selected-{{ $side }}-{{ $row['id'] }}">
                                <div class="flex gap-3 justify-between"><p class="font-semibold">{{ $row['reference'] ?: '(referencia vacía)' }} · Fila {{ $row['original_row'] }}</p><button type="button" class="underline text-red-700" wire:click="select('{{ $side }}', {{ $row['id'] }})">Retirar de la selección</button></div>
                                <x-reconciliation-status :status="$row['status']" :classification="$panel['classification']" />
                                <x-reconciliation-product :row="$row" :side="$side" :names="$names" :links="$links" :classification="$panel['classification']" />
                            </div>
                        @empty<p>No hay productos seleccionados.</p>@endforelse
                    </div>
                </details>
                </div>
                <div x-ref="rows" x-on:scroll.throttle.200ms="check()" tabindex="0" role="region" aria-label="Filas de {{ $names[$side] }}" class="reconciliation-rows overflow-auto overscroll-contain rounded border border-gray-200"><table class="w-full text-left text-sm"><thead><tr><th scope="col" class="sticky top-0 z-10 bg-gray-100 p-2 text-gray-900"><label class="flex items-center gap-2"><input type="checkbox" aria-label="Seleccionar cargados de {{ $names[$side] }}" x-bind:checked="allLoadedSelected()" x-effect="$el.indeterminate = someLoadedSelected() && !allLoadedSelected()" x-on:change="$el.checked ? $wire.selectLoaded('{{ $side }}') : $wire.deselectLoaded('{{ $side }}')" wire:loading.attr="disabled" class="rounded border-gray-300 text-indigo-600" />Seleccionar cargados</label></th><th scope="col" class="sticky top-0 z-10 bg-gray-100 p-2 text-gray-900">Referencia / fila</th><th scope="col" class="sticky top-0 z-10 bg-gray-100 p-2 text-gray-900">Descripción y equivalencias</th><th scope="col" class="sticky top-0 z-10 bg-gray-100 p-2 text-gray-900">Estado</th></tr></thead><tbody>
                    @forelse ($panel['rows'] as $row)
                        <tr wire:key="row-{{ $side }}-{{ $row['id'] }}" @class(['border-t text-gray-900', 'bg-green-50' => $row['status'] === 'linked', 'bg-yellow-50' => in_array($row['status'], ['outside', 'new'], true), 'bg-white' => $row['status'] === 'pending', 'ring-2 ring-inset ring-indigo-600' => array_key_exists($row['id'], $selected[$side])])>
                            <td class="p-2"><input type="checkbox" aria-label="Seleccionar {{ $names[$side] }} fila {{ $row['original_row'] }}" wire:key="checkbox-{{ $side }}-{{ $row['id'] }}" x-bind:checked="isSelected({{ $row['id'] }})" x-on:change.stop="$wire.select('{{ $side }}', {{ $row['id'] }})" wire:loading.attr="disabled" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" /></td>
                            <td class="p-2"><button type="button" class="block w-full text-left rounded p-2 hover:underline focus:ring-2 focus:ring-indigo-500" aria-pressed="{{ array_key_exists($row['id'], $selected[$side]) ? 'true' : 'false' }}" wire:click="select('{{ $side }}', {{ $row['id'] }})"><span class="font-semibold">{{ $row['reference'] ?: '⚠ Referencia vacía' }}</span><span class="block text-gray-500">Fila {{ $row['original_row'] }}</span><span class="block text-xs text-indigo-700">{{ array_key_exists($row['id'], $selected[$side]) ? 'Deseleccionar' : 'Seleccionar' }}</span></button></td>
                            <td class="p-2"><details><summary class="cursor-pointer">{{ \Illuminate\Support\Str::limit($row['description'], 90) }}</summary><div class="mt-3"><x-reconciliation-product :row="$row" :side="$side" :names="$names" :links="$links" :classification="$panel['classification']" /></div></details></td>
                            <td class="p-2"><x-reconciliation-status :status="$row['status']" :classification="$panel['classification']" /></td>
                        </tr>
                    @empty<tr><td colspan="4" class="p-4 text-gray-500">No hay filas con estos filtros.</td></tr>@endforelse
                </tbody></table><div x-ref="sentinel" aria-hidden="true" class="h-1"></div></div>
                <footer x-ref="footer" class="reconciliation-footer border-t pt-3 space-y-3 bg-white" aria-label="Carga de {{ $names[$side] }}">
                    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                        <label class="flex items-center gap-2">Filas por bloque<select wire:model.live="perPage.{{ $side }}" class="rounded border-gray-300">@foreach ([10,20,50] as $size)<option value="{{ $size }}">{{ $size }}</option>@endforeach</select></label>
                        <p role="status">Mostrando {{ $panel['rows']->count() }} de {{ $panel['total'] }}</p>
                    </div>
                    <p x-show="busy" x-cloak role="status">Cargando…</p>
                    <div x-show="failed" x-cloak class="text-sm space-y-2">
                        <p role="alert" class="text-red-800">No se pudo cargar el siguiente bloque.</p>
                        <x-secondary-button x-on:click="more()" x-bind:disabled="busy || catalogBusy" wire:loading.attr="disabled">Reintentar</x-secondary-button>
                    </div>
                    @if (! $hasMore[$side])
                        <p class="text-sm text-gray-700">Fin del catálogo</p>
                    @endif
                </footer>
            </section>
        @endforeach
    </div>
    <div x-show="showSelected" x-cloak x-on:keydown.escape.window="showSelected = false" role="dialog" aria-modal="true" aria-label="Productos seleccionados" class="fixed inset-4 z-40 overflow-y-auto rounded bg-white shadow-xl border p-4">
        <div class="flex justify-between gap-3 mb-4"><h2 class="font-semibold text-xl">Productos seleccionados</h2><button type="button" class="underline text-indigo-700" x-on:click="showSelected = false">Cerrar</button></div>
        <div class="grid lg:grid-cols-2 gap-4">
            @foreach ($panels as $side => $panel)
                <section><h3 class="font-semibold">{{ $names[$side] }}: {{ count($selected[$side]) }} seleccionados</h3>
                    @forelse ($panel['selections'] as $row)
                        <div class="border rounded p-3 my-2" wire:key="summary-{{ $side }}-{{ $row['id'] }}">
                            <p>{{ $row['reference'] }} · Fila {{ $row['original_row'] }} · {{ $row['description'] }}</p>
                            <x-reconciliation-status :status="$row['status']" :classification="$panel['classification']" />
                            <button type="button" class="block underline text-red-700" wire:click="select('{{ $side }}', {{ $row['id'] }})">Retirar de la selección</button>
                        </div>
                    @empty<p>No hay productos seleccionados.</p>@endforelse
                </section>
            @endforeach
        </div>
    </div>
    <div x-ref="actions" role="region" aria-label="Acciones de conciliación" class="fixed bottom-0 inset-x-0 z-30 border-t border-gray-300 bg-white shadow-lg p-3 sm:p-4">
        <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm break-words min-w-0">{{ $names['fifty'] }}: {{ count($selected['fifty']) }} seleccionados · {{ $names['pemex'] }}: {{ count($selected['pemex']) }} seleccionados</p>
            <div class="flex flex-wrap gap-2">
                <x-button wire:click="decide('link')" wire:loading.attr="disabled" :disabled="empty($selected['fifty']) || empty($selected['pemex'])">Es similar</x-button>
                <x-secondary-button wire:click="clearAllSelections" wire:loading.attr="disabled">Limpiar selección</x-secondary-button>
                <x-secondary-button x-on:click="showSelected = true">Ver seleccionados</x-secondary-button>
            </div>
        </div>
    </div>
</div>
