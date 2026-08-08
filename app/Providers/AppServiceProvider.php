<?php

namespace App\Providers;

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\Employer;
use App\Observers\EmployerObserver;
use App\Observers\NotificationObserver;
use App\Services\CvAnalysisService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CvAnalysisService::class, fn () => new CvAnalysisService());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $observer = new NotificationObserver();

        Application::observe($observer);
        DirectOffer::observe($observer);
        Employer::observe($observer);

        // Separate observer for audit-logging employer approval/rejection
        Employer::observe(EmployerObserver::class);
    }
}
