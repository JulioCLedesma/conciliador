<?php

namespace App\Livewire\Reconciliation;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Users extends Component
{
    use WithPagination;

    public string $name = '';

    public string $email = '';

    public string $role = 'revisor';

    public string $password = '';

    public string $password_confirmation = '';

    public function boot(): void
    {
        app()->setLocale('es');
    }

    public function mount(): void
    {
        Gate::authorize('administrar-usuarios');
    }

    public function create(): void
    {
        Gate::authorize('administrar-usuarios');
        $this->validate([
            'name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email',
            'role' => 'required|in:admin,revisor', 'password' => ['required', 'confirmed', Password::min(8)],
        ], [], ['name' => 'nombre', 'email' => 'correo', 'role' => 'rol', 'password' => 'contraseña']);
        $user = new User;
        $user->name = $this->name;
        $user->email = $this->email;
        $user->role = $this->role;
        $user->password = Hash::make($this->password);
        $user->save();
        $this->reset('name', 'email', 'role', 'password', 'password_confirmation');
        session()->flash('success', 'Usuario creado.');
    }

    public function render(): View
    {
        Gate::authorize('administrar-usuarios');

        return view('livewire.reconciliation.users', ['users' => User::query()->select('id', 'name', 'email', 'role')->orderBy('name')->paginate(20)]);
    }
}
