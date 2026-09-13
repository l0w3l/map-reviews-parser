<?php

use App\Data\Review\OrganizationData;
use App\Data\Review\ParseResultData;
use App\Data\Review\ReviewData;
use App\Data\Review\ReviewsData;
use App\Data\Review\YandexSessionData;
use App\Services\Review\Browser\YandexBrowser;
use App\Services\Review\YandexHtmlPage;
use App\Services\Review\YandexReviewException;
use App\Services\Review\YandexReviewService;
use Illuminate\Support\Facades\Http;

function reviewPage(int $page, array $ids, int $count = 2, int $pages = 2, int $remaining = 0): array
{
    return ['data' => ['reviews' => array_map(fn ($id) => [
        'reviewId' => $id, 'businessId' => '112125712262', 'author' => ['name' => 'Test'],
        'text' => 'Review', 'rating' => 5, 'updatedTime' => '2024-08-08T11:13:38.527Z',
    ], $ids), 'params' => ['page' => $page, 'count' => $count, 'totalPages' => $pages, 'reviewsRemained' => $remaining]]];
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->service = new YandexReviewService(new YandexSessionData('test', 'test'), pauseMilliseconds: 0);
});

it('combines metadata with all review pages', function () {
    Http::fake([
        'yandex.ru/maps/api/search*' => Http::response(['data' => ['items' => [[
            'type' => 'business', 'id' => '112125712262', 'title' => 'М.Косметик',
            'ratingData' => ['ratingValue' => 4.7, 'ratingCount' => 95, 'reviewCount' => 2],
        ]]]]),
        'yandex.ru/maps/api/business/fetchReviews*' => Http::sequence()
            ->push(reviewPage(1, ['a'], remaining: 1))->push(reviewPage(2, ['b'])),
    ]);
    $result = $this->service->parse('https://yandex.ru/maps/org/m_kosmetik/112125712262/reviews/');
    expect($result->organization->ratings_count)->toBe(95)->and($result->organization->reviews_count)->toBe(2)
        ->and($result->reviews->reviews)->toHaveCount(2)->and($result->reviews->reviews[0]->source_updated_at)->toBe('2024-08-08T11:13:38.527Z');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'fetchReviews') && $request['page'] === 2 && $request['businessId'] === '112125712262');
});

it('rejects duplicate pages that cannot meet the reported count', function () {
    Http::fake(['*' => Http::sequence()->push(reviewPage(1, ['a'], remaining: 1))->push(reviewPage(2, ['a']))]);
    expect(fn () => $this->service->reviews('112125712262'))->toThrow(YandexReviewException::class);
});

it('rejects reviews belonging to another business', function () {
    Http::fake(['*' => Http::response(reviewPage(1, ['a'], 1, 1))]);
    expect(fn () => $this->service->reviews('1553934310'))->toThrow(YandexReviewException::class);
});

it('accepts a confirmed empty result', function () {
    Http::fake(['*' => Http::response(reviewPage(1, [], 0, 0))]);
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

it('requires session context and validates links before sending requests', function () {
    expect(fn () => (new YandexReviewService)->reviews('123'))->toThrow(YandexReviewException::class);
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

it('excludes session secrets from serialization', function () {
    $context = new YandexSessionData('csrf-secret', 'session-secret', 'cookie-secret', 'Browser');
    expect($context->toArray())->toBe(['userAgent' => 'Browser']);
});

it('uses browser transport without manually supplied tokens', function () {
    $browser = Mockery::mock(YandexBrowser::class);
    $browser->shouldReceive('request')->once()->with('search', Mockery::on(fn ($query) => $query['text'] === 'Test'))
        ->andReturn(['items' => []]);
    $service = new YandexReviewService(browser: $browser);
    expect($service->search('Test')->items)->toBe([]);
    Http::assertNothingSent();
});

it('refreshes manual session csrf and signs each request', function () {
    Http::fake(['*' => Http::sequence()->push(['csrfToken' => 'fresh'])->push(['data' => ['items' => []]])]);
    $this->service->search('Магнит');
    Http::assertSentCount(2);
    Http::assertSent(function ($request) {
        $query = $request->data();
        $signature = $query['s'];
        unset($query['s']);
        uksort($query, fn ($a, $b) => strcasecmp($a, $b));
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $hash = 5381;
        foreach (str_split($encoded) as $character) {
            $hash = (($hash * 33) ^ ord($character)) & 0xFFFFFFFF;
        }

        return $query['csrfToken'] === 'fresh' && $signature === (string) $hash;
    });
});

it('recognizes internal api errors even with HTTP 200', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 500, 'message' => 'Internal error in /search']], 200)]);
    try {
        $this->service->search('Магнит');
        test()->fail('Expected exception');
    } catch (YandexReviewException $exception) {
        expect($exception->reason)->toBe('transient');
    }
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
    $result = $service->parse('112125712262');
    expect($result->reviews->reviews)->toHaveCount(2)->and($result->organization->ratings_count)->toBe(10);
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/org/112125712262/reviews/') && $request['page'] === 2);
});

it('rejects missing HTML state instead of reporting zero reviews', function () {
    Http::fake(['*' => Http::response('<html>changed markup</html>')]);
    $service = new YandexReviewService(html: new YandexHtmlPage);
    expect(fn () => $service->parse('112125712262'))->toThrow(YandexReviewException::class);
});

it('keeps reviews with a zero rating after the first hundred reviews', function () {
    $sequence = Http::sequence();
    for ($page = 1; $page <= 3; $page++) {
        $data = reviewPage($page, array_map(fn ($i) => (string) $i, range(($page - 1) * 50 + 1, $page * 50)), 150, 3, 150 - $page * 50);
        if ($page === 3) {
            $data['data']['reviews'][24]['rating'] = 0;
        }
        $sequence->push($data);
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
    Http::fake(['*' => Http::response($data)]);
    expect(fn () => $this->service->reviews('112125712262'))->toThrow(YandexReviewException::class);
})->with([-1, 6, 2.5, null]);

it('follows updated counters when the result grows or shrinks', function ($secondCount, $secondPages, $remaining) {
    Http::fake(['*' => Http::sequence()
        ->push(reviewPage(1, ['a'], 2, 2, 1))
        ->push(reviewPage(2, ['b'], $secondCount, $secondPages, $remaining))
        ->push(reviewPage(3, ['c'], 3, 3, 0))]);
    $result = $this->service->reviews('112125712262');
    expect($result->reviews)->toHaveCount($remaining ? 3 : 2)
        ->and($result->available_count)->toBe($remaining ? 3 : 2);
    Http::assertSentCount($remaining ? 3 : 2);
})->with([[3, 3, 1], [1, 2, 0]]);

it('does not accept an incomplete end after counters change', function () {
    Http::fake(['*' => Http::sequence()
        ->push(reviewPage(1, ['a'], 3, 3, 2))
        ->push(reviewPage(2, ['b'], 4, 2, 0))]);
    expect(fn () => $this->service->reviews('112125712262'))->toThrow(YandexReviewException::class);
});

it('rejects the wrong page number even with changing counters', function () {
    Http::fake(['*' => Http::sequence()
        ->push(reviewPage(1, ['a'], 2, 2, 1))
        ->push(reviewPage(1, ['b'], 3, 3, 1))]);
    expect(fn () => $this->service->reviews('112125712262'))->toThrow(YandexReviewException::class);
});

it('finishes the source window after 600 unique reviews without requesting page thirteen', function () {
    $sequence = Http::sequence();
    for ($page = 1; $page <= 12; $page++) {
        $sequence->push(reviewPage($page, array_map('strval', range(($page - 1) * 50 + 1, $page * 50)), 10000, 200, 10000 - $page * 50));
    }
    Http::fake(['*' => $sequence]);
    $result = $this->service->reviews('112125712262');
    expect($result->available_count)->toBe(600)->and($result->source_limited)->toBeTrue()->and($result->pages)->toBe(12);
    Http::assertSentCount(12);
});
