<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use App\Support\LocalDatabaseGuard;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->debeUsarServeLan()) {
            $this->app->extend(\Illuminate\Foundation\Console\ServeCommand::class, function ($command, $app) {
                return new \App\Console\Commands\ServeWithLanCommand;
            });
        }
    }

    private function debeUsarServeLan(): bool
    {
        if (getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PROJECT_ID') || getenv('RAILWAY_SERVICE_ID')) {
            return false;
        }

        return $this->app->environment('local');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LocalDatabaseGuard::asegurar();

        App::setLocale('es');
        Paginator::useBootstrapFour();

        Blade::directive('superficie', function (string $expression) {
            return "<?php echo \\App\\Support\\SuperficieFormato::etiqueta($expression); ?>";
        });

        if (getenv('RAILWAY_ENVIRONMENT') || getenv('RAILWAY_PROJECT_ID')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }
}
