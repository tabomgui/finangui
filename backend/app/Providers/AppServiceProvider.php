<?php

namespace App\Providers;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Support\OpenApi\DomainErrorToResponseExtension;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Testes trocam por FakeBankProvider via $this->app->instance(BankProvider::class, $fake).
        $this->app->singleton(BankProvider::class, PluggyProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');
            $key = (is_string($email) ? mb_strtolower($email) : '').'|'.$request->ip();

            return [
                Limit::perMinute(5)->by($key),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Doc do OpenAPI (dedoc/scramble): App\Domain\Shared\DomainError e
        // subclasses virar HTTP 409 com {code, message} — ver DomainErrorToResponseExtension.
        Scramble::registerExtension(DomainErrorToResponseExtension::class);
    }
}
