@props(['row', 'side', 'names', 'links', 'classification'])
<div class="space-y-3">
    <p class="whitespace-pre-wrap break-words">{{ $row['description'] }}</p>
    <details><summary class="cursor-pointer">Detalle del producto</summary><dl class="mt-2 space-y-2">@foreach ($row['details'] as $detail)<div><dt class="font-semibold">{{ $detail['label'] }}</dt><dd class="whitespace-pre-wrap break-words">{{ $detail['value'] }}</dd></div>@endforeach</dl></details>
    @php($productLinks = $links->where($side.'_id', $row['id']))
    @if ($productLinks->isNotEmpty())
        <details><summary class="cursor-pointer">Relaciones con {{ $side === 'fifty' ? $names['pemex'] : $names['fifty'] }} ({{ $productLinks->count() }})</summary>
            @foreach ($productLinks as $link)
                <div class="mt-2 border rounded p-2">
                    <p>{{ $side === 'fifty' ? $link->right_reference : $link->left_reference }} · Fila {{ $side === 'fifty' ? $link->right_original_row : $link->left_original_row }}</p>
                    <p class="break-words">{{ $side === 'fifty' ? $link->right_description : $link->left_description }}</p>
                    <button type="button" class="text-red-700 underline" wire:click="requestRemoveLink({{ $link->id }}, {{ $link->left_version }}, {{ $link->right_version }})">Retirar esta equivalencia</button>
                </div>
            @endforeach
        </details>
    @endif
    <div class="flex flex-wrap gap-2">
        <x-secondary-button wire:click="decide('{{ $side === 'fifty' ? 'outside' : 'new' }}', '{{ $side }}', {{ $row['id'] }}, {{ $row['version'] }})">{{ $classification }}</x-secondary-button>
        @if ($row['status'] !== 'pending')<x-secondary-button wire:click="decide('undo', '{{ $side }}', {{ $row['id'] }}, {{ $row['version'] }})">Deshacer decisión del producto</x-secondary-button>@endif
    </div>
</div>
