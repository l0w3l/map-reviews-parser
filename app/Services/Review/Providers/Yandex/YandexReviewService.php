<?php

namespace App\Services\Review\Providers\Yandex;

use App\Data\Review\OrganizationData;
use App\Data\Review\ParseRequestData;
use App\Data\Review\ParseResultData;
use App\Data\Review\ReviewData;
use App\Data\Review\ReviewsData;
use App\Services\Review\Enums\CollectionOutcome;
use App\Services\Review\ReviewServiceInterface;
use Closure;
use Illuminate\Support\Facades\Log;
use Lowel\LaravelServiceMaker\Services\AbstractService;

class YandexReviewService extends AbstractService implements ReviewServiceInterface
{
    use ValidatesYandexResponse;

    private const SOURCE_REVIEW_LIMIT = 600;

    public function __construct(
        private YandexHtmlPage $html,
        private int $pauseMilliseconds = 500,
        private int $maxPages = 100,
    ) {
        if ($pauseMilliseconds < 0 || $maxPages < 1) {
            throw new YandexReviewException('configuration', 'Пауза должна быть неотрицательной, а предел страниц — положительным.');
        }
    }

    public function resolve(string $urlOrId): ParseRequestData
    {
        return (new YandexOrganizationUrl)->resolve($urlOrId);
    }

    public function organization(string $urlOrId): OrganizationData
    {
        $id = (new YandexOrganizationUrl)->organizationId($urlOrId);

        $item = $this->html->load($id);

        $this->validate($item, [
            'title' => 'required|string', 'ratingData.ratingValue' => 'present|nullable|numeric|between:0,5',
            'ratingData.ratingCount' => 'required|integer|min:0', 'ratingData.reviewCount' => 'required|integer|min:0',
        ]);

        $rating = $item['ratingData'];

        if ($rating['ratingValue'] === null && $rating['ratingCount'] > 0) {
            throw new YandexReviewException('source_changed', 'Нет рейтинга при положительном числе оценок.');
        }

        return new OrganizationData($id, $item['title'], 'https://yandex.ru/maps/org/'.$id.'/',
            $rating['ratingValue'], $rating['ratingCount'], $rating['reviewCount']);
    }

    /**
     * @param  Closure(int, int): void|null  $progress  Unique collected / reported total.
     */
    public function reviews(string $urlOrId, ?Closure $progress = null): ReviewsData
    {
        $id = (new YandexOrganizationUrl)->organizationId($urlOrId);
        $reviews = [];
        $expectedCount = null;
        $expectedPages = null;

        for ($page = 1; $page <= $this->maxPages; $page++) {
            if ($page > 1 && $this->pauseMilliseconds > 0) {
                usleep($this->pauseMilliseconds * 1000);
            }

            $data = $this->html->load($id, $page)['reviewResults'] ?? [];

            $this->validate($data, [
                'reviews' => 'present|array', 'params' => 'required|array',
                'params.page' => 'required|integer|min:1', 'params.count' => 'required|integer|min:0',
                'params.totalPages' => 'required|integer|min:0', 'params.reviewsRemained' => 'required|integer|min:0',
                'reviews.*.reviewId' => 'required|string', 'reviews.*.businessId' => 'required|string',
                'reviews.*.author' => 'sometimes|nullable|array', 'reviews.*.author.name' => 'sometimes|nullable|string', 'reviews.*.text' => 'present|string',
                'reviews.*.rating' => 'required|integer|between:0,5', 'reviews.*.updatedTime' => 'required|date',
            ]);

            $params = $data['params'];

            if ($params['page'] !== $page) {
                throw new YandexReviewException('partial', 'Яндекс вернул другой номер страницы.');
            }

            if ($expectedCount !== null && ($params['count'] !== $expectedCount || $params['totalPages'] !== $expectedPages)) {
                Log::info('Yandex pagination counters changed', [
                    'business_id' => $id, 'page' => $page,
                    'previous_count' => $expectedCount, 'count' => $params['count'],
                    'previous_pages' => $expectedPages, 'pages' => $params['totalPages'],
                ]);
            }

            $expectedCount = $params['count'];
            $expectedPages = $params['totalPages'];
            $before = count($reviews);

            foreach ($data['reviews'] as $review) {
                if ($review['businessId'] !== $id) {
                    throw new YandexReviewException('source_changed', 'Пришли отзывы другой организации.');
                }

                $reviews[$review['reviewId']] = new ReviewData(
                    $review['reviewId'], $id, trim($review['author']['name'] ?? '') ?: 'Автор не указан', $review['text'],
                    $review['rating'], $review['updatedTime'],
                );
            }

            if ($progress !== null) {
                $progress(count($reviews), $expectedCount);
            }

            if (count($reviews) === $before && ! ($page === 1 && $expectedCount === 0 && $params['reviewsRemained'] === 0)) {
                throw new YandexReviewException('partial', 'Страница не добавила новых отзывов.');
            }

            // Maps exposes at most 600 reviews, even when counters describe a larger corpus.
            if (count($reviews) >= self::SOURCE_REVIEW_LIMIT && $expectedCount > self::SOURCE_REVIEW_LIMIT) {
                return new ReviewsData(array_slice(array_values($reviews), 0, self::SOURCE_REVIEW_LIMIT), self::SOURCE_REVIEW_LIMIT, $page, CollectionOutcome::SourceLimitReached);
            }

            if ($params['reviewsRemained'] === 0) {
                if (count($reviews) < $expectedCount || ($expectedPages > 0 && $page !== $expectedPages)) {
                    throw new YandexReviewException('partial', 'Конец выдачи не совпадает со счётчиками.');
                }

                return new ReviewsData(array_values($reviews), count($reviews), $page);
            }

            if ($page >= $expectedPages) {
                throw new YandexReviewException('partial', 'Выдача остановилась до получения всех отзывов.');
            }
        }

        throw new YandexReviewException('partial', 'Достигнут защитный лимит страниц.');
    }

    public function parse(ParseRequestData $request, ?Closure $onProgress = null): ParseResultData
    {
        if ($request->provider !== 'yandex') {
            throw new YandexReviewException('invalid_input', 'Организация относится к другой площадке.');
        }

        $this->html->clearCache();

        try {
            $organization = $this->organization($request->sourceId);
            $result = $this->reviews($organization->source_id, $onProgress);

            if ($organization->reviews_count < $result->available_count) {
                throw new YandexReviewException('partial', 'Счётчики карточки и отзывов расходятся.');
            }

            return new ParseResultData($organization, $result);
        } finally {
            $this->html->clearCache();
        }
    }
}
