<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Data\Review\YandexSessionData;
use Lowel\LaravelServiceMaker\Services\ServiceFactoryInterface;

class ReviewServiceFactory implements ServiceFactoryInterface
{
    /** @param array<string, string> $params */
    public function get(array $params = []): ReviewServiceInterface
    {
        return $this->getYandex(YandexSessionData::from($params));
    }

    public function getYandex(YandexSessionData $context = new YandexSessionData): YandexReviewService
    {
        return new YandexReviewService($context);
    }
}
