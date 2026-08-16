<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\ContentItemAssignment;
use App\Models\ContentWorkflow;
use App\Observers\ContentItemAssignmentObserver;
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
        ContentItemAssignment::observe(ContentItemAssignmentObserver::class);
    }
}
