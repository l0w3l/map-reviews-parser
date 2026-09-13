<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Services\Review\Exceptions\ReviewProviderException;
use App\Services\Review\Providers\Yandex\YandexHtmlPage;
use App\Services\Review\Providers\Yandex\YandexReviewService;
use Lowel\LaravelServiceMaker\Services\ServiceFactoryInterface;

class ReviewServiceFactory implements ServiceFactoryInterface
{
    /** @param array<string, mixed> $params */
    public function get(array $params = []): ReviewServiceInterface
    {
        return match ($params['provider'] ?? 'yandex') {
            'yandex' => $this->getYandex(),
            default => throw new ReviewProviderException('unsupported_provider', 'Площадка не поддерживается.'),
        };
    }

    public function getYandex(): YandexReviewService
    {
        return new YandexReviewService(
            new YandexHtmlPage,
            pauseMilliseconds: (int) config('yandex.pause_milliseconds', 500),
            maxPages: (int) config('yandex.max_pages', 100),
        );
    }
}
