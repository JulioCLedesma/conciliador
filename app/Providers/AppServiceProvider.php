<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('administrar-usuarios', fn (User $user): bool =>
            $user->isAdmin()
        );

        Gate::define('importar-catalogos', fn (User $user): bool =>
            $user->isAdmin()
        );

        Gate::define('ver-importes', fn (User $user): bool =>
            $user->isAdmin()
        );

        Gate::define('conciliar-catalogos', fn (User $user): bool =>
            in_array($user->role, ['admin', 'revisor'], true)
        );

        Gate::define('exportar-catalogos', fn (User $user): bool =>
            in_array($user->role, ['admin', 'revisor'], true)
        );
    }
}
