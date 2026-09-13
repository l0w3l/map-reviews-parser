<?php

use App\Data\Review\OrganizationData;
use App\Data\Review\ParseRequestData;
use App\Data\Review\ParseResultData;
use App\Data\Review\ReviewData;
use App\Data\Review\ReviewsData;
use App\Services\Review\Enums\CollectionOutcome;
use App\Services\Review\Exceptions\ReviewProviderException;
use App\Services\Review\Providers\Yandex\YandexHtmlPage;
use App\Services\Review\Providers\Yandex\YandexOrganizationUrl;
use App\Services\Review\Providers\Yandex\YandexReviewException;
use App\Services\Review\Providers\Yandex\YandexReviewService;
use App\Services\Review\ReviewServiceInterface;
use Illuminate\Support\Facades\Http;
use Lowel\LaravelServiceMaker\Services\AbstractService;

require_once __DIR__.'/../Fixtures/yandex-html.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->service = new YandexReviewService(new YandexHtmlPage, pauseMilliseconds: 0);
});

it('rejects duplicate pages that cannot meet the reported count', function () {
    Http::fake(['*' => Http::sequence()->push(htmlReviewPage(1, ['a'], remaining: 1))->push(htmlReviewPage(2, ['a']))]);
    expect(fn () => $this->service->reviews('112125712262'))->toThrow(YandexReviewException::class);
});

it('rejects reviews belonging to another business', function () {
    Http::fake(['*' => Http::response(htmlReviewPage(1, ['a'], 1, 1))]);
    expect(fn () => $this->service->reviews('1553934310'))->toThrow(YandexReviewException::class);
});

it('accepts a confirmed empty result', function () {
    Http::fake(['*' => Http::response(htmlReviewPage(1, [], 0, 0))]);
    expect($this->service->reviews('112125712262')->reviews)->toBe([]);
});

it('reports blocked and changed responses without leaking tokens', function (int $status, mixed $body, string $reason) {
    Http::fake(['*' => Http::response($body, $status)]);
    try {
        $this->service->reviews('112125712262');
        test()->fail('Expected exception');
    } catch (YandexReviewException $exception) {
        expect($exception->reason)->toBe($reason)->and($exception->getMessage())->not->toContain('csrfToken');
    }
})->with([[403, [], 'blocked'], [429, [], 'blocked'], [200, '<html>captcha</html>', 'source_changed'], [200, ['data' => []], 'source_changed']]);

it('validates links before sending requests', function () {
    expect(fn () => $this->service->reviews('https://evil.test/maps/org/123/'))->toThrow(YandexReviewException::class);
    Http::assertNothingSent();
});

it('serializes nested DTOs and restores typed review objects', function () {
    $result = new ParseResultData(
        new OrganizationData('123', 'Test', 'https://yandex.ru/maps/org/123/', null, 0, 0),
        new ReviewsData([
            new ReviewData('r1', '123', 'Author', 'Text', 5, '2024-08-08T11:13:38.527Z'),
        ], 1, 1),
    );
    $array = $result->toArray();
    expect($array['organization']['rating'])->toBeNull()
        ->and($array['reviews']['reviews'][0]['source_id'])->toBe('r1');
    $restored = ParseResultData::from($array);
    expect($restored->reviews->reviews[0])->toBeInstanceOf(ReviewData::class);
});

it('parses all HTML pages and reuses metadata page without API requests', function () {
    $html = function ($page, $ids, $remaining) {
        $item = ['type' => 'business', 'id' => '112125712262', 'title' => 'Test',
            'ratingData' => ['ratingValue' => 4.5, 'ratingCount' => 10, 'reviewCount' => 2],
            'reviewResults' => reviewPage($page, $ids, remaining: $remaining)['data']];

        return '<script class="state-view" type="application/json">'.json_encode(['stack' => [['results' => ['items' => [$item]]]]]).'</script>';
    };
    Http::fake(['*' => Http::sequence()->push($html(1, ['a'], 1))->push($html(2, ['b'], 0))]);
    $service = new YandexReviewService(pauseMilliseconds: 0, html: new YandexHtmlPage);
    $result = $service->parse((new YandexOrganizationUrl)->resolve('112125712262'));
    expect($result->reviews->reviews)->toHaveCount(2)->and($result->organization->ratings_count)->toBe(10);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/org/112125712262/reviews/') && $request['page'] === 2);
});

it('rejects missing HTML state instead of reporting zero reviews', function () {
    Http::fake(['*' => Http::response('<html>changed markup</html>')]);
    $service = new YandexReviewService(html: new YandexHtmlPage);
    expect(fn () => $service->parse((new YandexOrganizationUrl)->resolve('112125712262')))->toThrow(YandexReviewException::class);
});

it('keeps reviews with a zero rating after the first hundred reviews', function () {
    $sequence = Http::sequence();
    for ($page = 1; $page <= 3; $page++) {
        $data = reviewPage($page, array_map(fn ($i) => (string) $i, range(($page - 1) * 50 + 1, $page * 50)), 150, 3, 150 - $page * 50);
        if ($page === 3) {
            $data['data']['reviews'][24]['rating'] = 0;
        }
        $sequence->push(reviewHtml($data['data']));
    }
    Http::fake(['*' => $sequence]);
    $result = $this->service->reviews('112125712262');
    expect($result->reviews)->toHaveCount(150)
        ->and($result->reviews[124]->rating)->toBe(0)
        ->and($result->pages)->toBe(3);
});

it('still rejects ratings outside the observed range', function ($rating) {
    $data = reviewPage(1, ['a'], 1, 1);
    $data['data']['reviews'][0]['rating'] = $rating;
    Http::fake(['*' => Http::response(reviewHtml($data['data']))]);
    expect(fn () => $this->service->reviews('112125712262'))->toThrow(YandexReviewException::class);
})->with([-1, 6, 2.5, null]);

it('follows updated counters when the result grows or shrinks', function ($secondCount, $secondPages, $remaining) {
    Http::fake(['*' => Http::sequence()
        ->push(htmlReviewPage(1, ['a'], 2, 2, 1))
        ->push(htmlReviewPage(2, ['b'], $secondCount, $secondPages, $remaining))
        ->push(htmlReviewPage(3, ['c'], 3, 3, 0))]);
    $result = $this->service->reviews('112125712262');
    expect($result->reviews)->toHaveCount($remaining ? 3 : 2)
        ->and($result->available_count)->toBe($remaining ? 3 : 2);
    Http::assertSentCount($remaining ? 3 : 2);
})->with([[3, 3, 1], [1, 2, 0]]);

it('does not accept an incomplete end after counters change', function () {
    Http::fake(['*' => Http::sequence()
        ->push(htmlReviewPage(1, ['a'], 3, 3, 2))
        ->push(htmlReviewPage(2, ['b'], 4, 2, 0))]);
    expect(fn () => $this->service->reviews('112125712262'))->toThrow(YandexReviewException::class);
});

it('rejects the wrong page number even with changing counters', function () {
    Http::fake(['*' => Http::sequence()
        ->push(htmlReviewPage(1, ['a'], 2, 2, 1))
        ->push(htmlReviewPage(1, ['b'], 3, 3, 1))]);
    expect(fn () => $this->service->reviews('112125712262'))->toThrow(YandexReviewException::class);
});

it('finishes the source window after 600 unique reviews without requesting page thirteen', function () {
    $sequence = Http::sequence();
    for ($page = 1; $page <= 12; $page++) {
        $sequence->push(htmlReviewPage($page, array_map('strval', range(($page - 1) * 50 + 1, $page * 50)), 10000, 200, 10000 - $page * 50));
    }
    Http::fake(['*' => $sequence]);
    $result = $this->service->reviews('112125712262');
    expect($result->available_count)->toBe(600)->and($result->outcome)->toBe(CollectionOutcome::SourceLimitReached)->and($result->pages)->toBe(12);
    Http::assertSentCount(12);
});

it('reports progress and clears cached metadata between parses', function () {
    Http::fake(['*' => Http::sequence()
        ->push(htmlReviewPage(1, ['a'], 1, 1))
        ->push(htmlReviewPage(1, ['b'], 1, 1))]);
    $progress = [];
    $first = $this->service->parse((new YandexOrganizationUrl)->resolve('112125712262'), function ($collected, $total) use (&$progress) {
        $progress[] = [$collected, $total];
    });
    $second = $this->service->parse((new YandexOrganizationUrl)->resolve('112125712262'));
    expect($progress)->toBe([[1, 1]])
        ->and($first->reviews->outcome)->toBe(CollectionOutcome::Complete)
        ->and($second->reviews->reviews[0]->source_id)->toBe('b');
    Http::assertSentCount(2);
});

it('treats the configurable page safety limit as incomplete collection', function () {
    Http::fake(['*' => Http::response(htmlReviewPage(1, ['a'], 2, 2, 1))]);
    $service = new YandexReviewService(new YandexHtmlPage, pauseMilliseconds: 0, maxPages: 1);
    try {
        $service->parse((new YandexOrganizationUrl)->resolve('112125712262'));
        test()->fail('Expected partial result error');
    } catch (YandexReviewException $exception) {
        expect($exception->reason)->toBe('partial');
    }
    Http::assertSentCount(1);
});

it('rejects a parse request for another provider before making network requests', function () {
    $request = new ParseRequestData('example', '123', 'https://example.test/123');
    expect(fn () => $this->service->parse($request))->toThrow(YandexReviewException::class);
    Http::assertNothingSent();
});

it('resolves the service through the existing service maker factory binding', function () {
    $service = app()->makeWith(ReviewServiceInterface::class, ['provider' => 'yandex']);
    expect($service)->toBeInstanceOf(YandexReviewService::class)
        ->and($service)->toBeInstanceOf(AbstractService::class);
    $request = $service->resolve('https://yandex.ru/maps/org/123/');
    expect($request->provider)->toBe('yandex')->and($request->sourceId)->toBe('123');
    $restored = ParseRequestData::from($request->toArray());
    expect($restored->toArray())->toBe($request->toArray());
    expect(fn () => app()->makeWith(ReviewServiceInterface::class, ['provider' => 'unknown']))
        ->toThrow(ReviewProviderException::class);
    Http::assertNothingSent();
});
