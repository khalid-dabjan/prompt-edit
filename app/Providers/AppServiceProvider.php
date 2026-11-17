<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn (): PixelforgeClient => new PixelforgeClient(
            apiKey: config()->string('services.pixelforge.key'),
            baseUrl: config()->string('services.pixelforge.base_url'),
            connectTimeout: config()->integer('services.pixelforge.connect_timeout'),
            timeout: config()->integer('services.pixelforge.timeout'),
        ));

        $this->app->singleton(WebhookSignature::class, fn (): WebhookSignature => new WebhookSignature(
            secret: (string) config('services.pixelforge.webhook_secret'),
            toleranceSeconds: config()->integer('services.pixelforge.webhook_tolerance'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // `composer run dev` also runs the scheduler, which polls unfinished generations.
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
