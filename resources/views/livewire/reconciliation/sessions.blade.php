<div class="max-w-7xl mx-auto p-4 sm:p-8">
    <h1 class="text-2xl font-semibold mb-4">Sesiones de conciliación</h1>
    <p class="mb-6 text-gray-600">El avance se guarda con cada decisión. Abre una sesión para continuar conciliando sus dos catálogos.</p>
    <x-reconciliation-messages />
    @can('importar-catalogos')
        <form wire:submit="create" class="bg-white rounded shadow p-6 mb-6 flex flex-wrap gap-4 items-end">
            <label class="grow">Nombre de la nueva sesión<x-input wire:model="name" class="block w-full" placeholder="Conciliación octubre" required /></label>
            <label class="grow">Nombre del catálogo izquierdo<x-input wire:model="leftCatalogName" class="block w-full" placeholder="Ej. HGO" required /></label>
            <label class="grow">Nombre del catálogo derecho<x-input wire:model="rightCatalogName" class="block w-full" placeholder="Ej. Civil" required /></label>
            <x-button wire:loading.attr="disabled">Crear sesión</x-button>
        </form>
    @endcan
    <div class="space-y-4">
        @forelse ($sessions as $session)
            <div class="bg-white p-6 rounded shadow flex flex-wrap justify-between gap-4">
                <div><h2 class="font-semibold">{{ $session->name }}</h2><p>{{ $session->left_catalog_name }} vs. {{ $session->right_catalog_name }}</p><p class="text-sm text-gray-500">Creada: {{ $session->created_at }}</p></div>
                <div class="flex gap-4 items-center">
                    <a class="text-indigo-700 underline" href="{{ route('reconciliation.board', $session->id) }}">Abrir tablero</a>
                    @can('importar-catalogos')<a class="text-indigo-700 underline" href="{{ route('reconciliation.import', $session->id) }}">Configurar archivos</a>@endcan
                </div>
            </div>
        @empty<p class="bg-white p-6 rounded">Todavía no hay sesiones.</p>@endforelse
    </div>
    <div class="mt-6">{{ $sessions->links() }}</div>
</div>
