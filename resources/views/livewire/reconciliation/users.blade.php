<div class="max-w-7xl mx-auto p-4 sm:p-8">
    <h1 class="text-2xl font-semibold mb-6">Administración de usuarios</h1>
    <x-reconciliation-messages />
    <form wire:submit="create" class="bg-white shadow rounded p-6 grid gap-4 sm:grid-cols-2 mb-6">
        <label>Nombre<x-input class="block w-full" wire:model="name" required /></label>
        <label>Correo<x-input type="email" class="block w-full" wire:model="email" required /></label>
        <label>Rol<select wire:model="role" class="block w-full rounded border-gray-300"><option value="revisor">Revisor</option><option value="admin">Admin</option></select></label>
        <div></div>
        <label>Contraseña<x-input type="password" autocomplete="new-password" class="block w-full" wire:model="password" required /></label>
        <label>Confirmar contraseña<x-input type="password" autocomplete="new-password" class="block w-full" wire:model="password_confirmation" required /></label>
        <div><x-button wire:loading.attr="disabled">Crear usuario</x-button></div>
    </form>
    <div class="overflow-x-auto bg-white rounded shadow p-4">
        <table class="w-full text-left"><thead><tr><th class="p-2">Nombre</th><th class="p-2">Correo</th><th class="p-2">Rol</th></tr></thead>
            <tbody>@foreach ($users as $user)<tr class="border-t"><td class="p-2">{{ $user->name }}</td><td class="p-2">{{ $user->email }}</td><td class="p-2">{{ $user->role === 'admin' ? 'Admin' : 'Revisor' }}</td></tr>@endforeach</tbody>
        </table>{{ $users->links() }}
    </div>
</div>
