<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Models\ContentType;
use App\Models\ContentWorkflow;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Observers\ContentWorkflowObserver;

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
        ContentWorkflow::observe(ContentWorkflowObserver::class);

        // Sidebar sekarang megang tombol + modal "Jobdesk Tambahan" (pindah
        // dari header Content Plan/Produksi supaya bisa diakses dari halaman
        // manapun) - opsi dropdown-nya disuntik lewat composer di sini,
        // bukan lewat tiap controller, karena sidebar dirender di semua
        // halaman internal terlepas dari controller yang lagi aktif.
        View::composer('components.sidebar', function ($view) {
            $user = auth()->user();

            if (! $user || ! $user->hasPermissionTo('content_plan', 'create')) {
                return;
            }

            $view->with([
                'clientOptions' => $user->canSeeAllClients()
                    ? Client::where('status', 'active')->get()
                    : $user->assignedClients()->where('status', 'active')->get(),
                'contentTypeOptions' => ContentType::all(),
                'platformOptions' => Platform::all(),
                'picOptions' => User::query()->where('status', 'active')->with('assignedClients:id')->get(),
            ]);
        });
    }
}
