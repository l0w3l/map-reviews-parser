<?php

namespace App\Providers;

use App\Data\Review\YandexSessionData;
use App\Services\Review\Browser\YandexBrowser;
use App\Services\Review\ReviewServiceInterface;
use App\Services\Review\YandexHtmlPage;
use App\Services\Review\YandexReviewService;
use Carbon\CarbonImmutable;
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
        $this->app->bind(YandexReviewService::class, fn () => new YandexReviewService(new YandexSessionData(
            csrfToken: config('yandex.csrf_token'), sessionId: config('yandex.session_id'),
            cookie: config('yandex.cookie'), userAgent: config('yandex.user_agent'),
        ), browser: config('yandex.transport') === 'browser' ? new YandexBrowser : null, html: config('yandex.html_reviews') ? new YandexHtmlPage : null));
        $this->app->bind(ReviewServiceInterface::class, YandexReviewService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
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
