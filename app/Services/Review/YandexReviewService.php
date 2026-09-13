<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Data\Review\OrganizationData;
use App\Data\Review\ParseResultData;
use App\Data\Review\RatingData;
use App\Data\Review\ReviewData;
use App\Data\Review\ReviewsData;
use App\Data\Review\SearchItemData;
use App\Data\Review\SearchResultData;
use App\Data\Review\YandexSessionData;
use App\Services\Review\Browser\YandexBrowser;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Lowel\LaravelServiceMaker\Services\AbstractService;

/** HTTP adapter for the observed internal Maps JSON protocol. */
class YandexReviewService extends AbstractService implements ReviewServiceInterface
{
    /**
     * Context must come from a fresh browser session, never from source code.
     * The optional callback supplies dynamic parameters (e.g. s) per request.
     *
     * @param  Closure(string, array<string, mixed>): array<string, string>|null  $requestParameters
     */
    public function __construct(
        private YandexSessionData $context = new YandexSessionData,
        private ?Closure $requestParameters = null,
        private int $pauseMilliseconds = 500,
        private int $maxPages = 100,
        private ?YandexBrowser $browser = null,
        private ?YandexHtmlPage $html = null,
    ) {}

    /**
     * Search text may also be a search_query JSON string from suggest-geo.
     * Returns one search page, not every branch of a chain.
     */
    public function search(string $text, ?string $ll = null, ?string $spn = null): SearchResultData
    {
        if (trim($text) === '') {
            throw new YandexReviewException('invalid_input', 'Поисковый запрос пуст.');
        }
        $query = ['text' => $text, 'results' => 25, 'lang' => 'ru_RU', 'snippets' => 'businessrating/1.x'];
        if ($ll !== null) {
            $query['ll'] = $ll;
        }
        if ($spn !== null) {
            $query['spn'] = $spn;
        }
        $data = $this->request('search', $query);
        $this->validate($data, ['items' => 'present|array', 'items.*.type' => 'required|string']);

        $items = [];
        foreach ($data['items'] as $item) {
            $this->validate($item, [
                'id' => 'sometimes|nullable|string', 'title' => 'sometimes|nullable|string',
                'address' => 'sometimes|nullable|string', 'ratingData' => 'sometimes|nullable|array',
            ]);
            $rating = null;
            if (isset($item['ratingData'])) {
                $this->validate($item['ratingData'], [
                    'ratingValue' => 'present|nullable|numeric|between:0,5',
                    'ratingCount' => 'required|integer|min:0', 'reviewCount' => 'required|integer|min:0',
                ]);
                $rating = RatingData::from($item['ratingData']);
            }
            $items[] = new SearchItemData($item['type'], $item['id'] ?? null, $item['title'] ?? null, $item['address'] ?? null, $rating);
        }
        $this->validate($data, ['totalResultCount' => 'sometimes|integer|min:0', 'pageCount' => 'sometimes|integer|min:0']);

        return new SearchResultData($items, $data['totalResultCount'] ?? null, $data['pageCount'] ?? null);
    }

    public function organization(string $urlOrId): OrganizationData
    {
        $id = $this->organizationId($urlOrId);
        if ($this->html !== null) {
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
        $data = $this->search(json_encode([
            'text' => '', 'what' => [['attr_name' => 'organization_id', 'attr_values' => [$id]]],
        ], JSON_THROW_ON_ERROR));
        foreach ($data->items as $item) {
            if ($item->type !== 'business' || $item->id !== $id) {
                continue;
            }
            if ($item->title === null || $item->title === '' || $item->ratingData === null) {
                throw new YandexReviewException('source_changed', 'Нет названия или счётчиков организации.');
            }
            $rating = $item->ratingData;
            if ($rating->ratingValue === null && $rating->ratingCount > 0) {
                throw new YandexReviewException('source_changed', 'Нет рейтинга при положительном числе оценок.');
            }

            return new OrganizationData($id, $item->title, 'https://yandex.ru/maps/org/'.$id.'/',
                $rating->ratingValue, $rating->ratingCount, $rating->reviewCount);

        }
        throw new YandexReviewException('unavailable', 'Организация отсутствует в ответе поиска.');
    }

    /**
     * @param  Closure(int, int): void|null  $progress  Unique collected / reported total.
     */
    public function reviews(string $urlOrId, ?Closure $progress = null): ReviewsData
    {
        $id = $this->organizationId($urlOrId);
        $reviews = [];
        $expectedCount = null;
        $expectedPages = null;
        for ($page = 1; $page <= $this->maxPages; $page++) {
            if ($page > 1 && $this->pauseMilliseconds > 0) {
                usleep($this->pauseMilliseconds * 1000);
            }
            $data = $this->html !== null ? ($this->html->load($id, $page)['reviewResults'] ?? []) : $this->request('business/fetchReviews', [
                'businessId' => $id, 'page' => $page, 'pageSize' => 50,
                'ranking' => 'by_relevance_org', 'locale' => 'ru_RU',
            ]);
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
            if (count($reviews) >= 600 && $expectedCount > 600) {
                return new ReviewsData(array_slice(array_values($reviews), 0, 600), 600, $page, true);
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

    public function parse(string $urlOrId): ParseResultData
    {
        $organization = $this->organization($urlOrId);
        $result = $this->reviews($organization->source_id);
        if ($organization->reviews_count < $result->available_count) {
            throw new YandexReviewException('partial', 'Счётчики карточки и отзывов расходятся.');
        }

        return new ParseResultData($organization, $result);
    }

    public function organizationId(string $urlOrId): string
    {
        if (preg_match('/^[0-9]+$/D', $urlOrId)) {
            return $urlOrId;
        }
        $url = parse_url(trim($urlOrId));
        if ($url && ($url['scheme'] ?? '') === 'https'
            && in_array(strtolower($url['host'] ?? ''), ['yandex.ru', 'yandex.com'], true)
            && ! isset($url['pass']) && ! isset($url['user']) && ! isset($url['port'])
            && preg_match('~^/maps/org/(?:[^/]+/)?([0-9]+)/(?:reviews/?)?$~D', $url['path'] ?? '', $match)) {
            return $match[1];
        }
        throw new YandexReviewException('invalid_input', 'Нужен ID или полная HTTPS-ссылка на карточку Яндекса.');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(string $endpoint, array $query, bool $refreshed = false): array
    {
        if ($this->browser !== null) {
            return $this->browser->request($endpoint, $query);
        }
        if (empty($this->context->csrfToken) || empty($this->context->sessionId)) {
            throw new YandexReviewException('session_required', 'Передайте свежие csrfToken и sessionId браузерной сессии.');
        }
        $query += ['ajax' => 1, 'csrfToken' => $this->context->csrfToken, 'sessionId' => $this->context->sessionId];
        if ($this->requestParameters !== null) {
            $query += ($this->requestParameters)($endpoint, $query);
        }
        unset($query['s']);
        uksort($query, fn (string $a, string $b): int => strcasecmp($a, $b));
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $hash = 5381;
        for ($i = 0; $i < strlen($encoded); $i++) {
            $hash = (($hash * 33) ^ ord($encoded[$i])) & 0xFFFFFFFF;
        }
        $query['s'] = (string) $hash;
        try {
            $response = Http::acceptJson()->withHeaders([
                'Cookie' => $this->context->cookie,
                'User-Agent' => $this->context->userAgent,
                'Referer' => 'https://yandex.ru/maps/',
                'X-Retpath-Y' => 'https://yandex.ru/maps/',
            ])->withOptions(YandexProxy::httpOptions())->connectTimeout(10)->timeout(30)->withoutRedirecting()
                ->get('https://yandex.ru/maps/api/'.$endpoint, $query);
        } catch (ConnectionException) {
            throw new YandexReviewException('transient', 'Не удалось связаться с Яндекс.Картами.');
        }
        if (in_array($response->status(), [401, 403, 429], true) || $response->redirect()) {
            throw new YandexReviewException('blocked', 'Источник отклонил запрос или требует проверки сессии.');
        }
        if ($response->serverError()) {
            throw new YandexReviewException('transient', 'Временная ошибка Яндекс.Карт.');
        }
        $payload = $response->json();
        if (strtolower(trim($response->body())) === 'limited' || (is_array($payload) && ($payload['type'] ?? null) === 'captcha')) {
            throw new YandexReviewException('blocked', 'Яндекс ограничил доступ переданной сессии (limited/CAPTCHA).');
        }
        if (is_array($payload) && is_string($payload['csrfToken'] ?? null)) {
            if ($refreshed || $payload['csrfToken'] === '') {
                throw new YandexReviewException('session_required', 'Яндекс отклонил сессию. Передайте свежие данные браузера.');
            }
            $this->context->csrfToken = $payload['csrfToken'];
            unset($query['csrfToken'], $query['s']);

            return $this->request($endpoint, $query, true);
        }
        if (is_array($payload) && is_array($payload['error'] ?? null)
            && in_array((string) ($payload['error']['code'] ?? ''), ['500', '502', '503', '504'], true)) {
            Log::warning('Yandex API internal error', ['endpoint' => $endpoint, 'status' => $response->status(), 'code' => $payload['error']['code']]);
            throw new YandexReviewException('transient', 'Внутренняя ошибка API Яндекса при запросе '.$endpoint.'. Задача будет повторена в пределах лимита попыток.');
        }
        if (! $response->successful() || ! is_array($payload) || isset($payload['error']) || ! is_array($payload['data'] ?? null)) {
            $format = is_array($payload) ? 'json' : (str_starts_with(ltrim($response->body()), '<') ? 'html' : 'text');
            Log::warning('Yandex HTTP response rejected', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'format' => $format,
                'bytes' => strlen($response->body()),
                'has_error' => is_array($payload) && isset($payload['error']),
                'has_data' => is_array($payload) && array_key_exists('data', $payload),
                'csrf_refreshed' => $refreshed,
            ]);
            throw new YandexReviewException('source_changed', sprintf(
                'Яндекс отклонил ответ %s: HTTP %d, формат %s. Подробности: Yandex HTTP response rejected в логе.',
                $endpoint, $response->status(), $format,
            ));
        }

        return $payload['data'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $rules
     */
    private function validate(array $data, array $rules): void
    {
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            Log::warning('Yandex schema mismatch', ['fields' => array_keys($validator->failed())]);
            throw new YandexReviewException('source_changed', 'Структура ответа Яндекс.Карт изменилась.');
        }
    }
}
