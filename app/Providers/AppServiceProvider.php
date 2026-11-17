<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiting();

        Gate::define('viewAdminReport', fn (User $user): bool => $user->is_admin);

        // `composer run dev` also runs the scheduler, which polls unfinished generations.
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Pixelforge's quota (60 requests/min, 500 generations/day) is shared by every
     * user, so one user can't submit more than 10 generations a minute. Going over
     * sends them back to the form with a message instead of a bare 429 page.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('generations', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->user()?->id)
            ->response(fn () => back()->withErrors([
                'generation' => __('You are generating very quickly. Please wait a minute and try again.'),
            ])));
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
