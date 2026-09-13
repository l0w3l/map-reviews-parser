<?php

use App\Services\Review\Providers\Yandex\YandexHtmlPage;
use App\Services\Review\Providers\Yandex\YandexProxy;
use App\Services\Review\Providers\Yandex\YandexReviewException;
use Illuminate\Support\Facades\Http;

it('routes HTML through the configured proxy without a bypass list', function () {
    config(['yandex.proxy' => 'socks5h://user:secret@proxy.test:1080']);
    $options = null;
    Http::fake(function ($request, $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response('limited');
    });
    expect(fn () => (new YandexHtmlPage)->load('123'))->toThrow(YandexReviewException::class);
    expect($options['proxy'])->toBe(['http' => 'socks5h://user:secret@proxy.test:1080', 'https' => 'socks5h://user:secret@proxy.test:1080', 'no' => []]);
    Http::assertSentCount(1);
});

it('rejects malformed proxies without disclosing credentials', function () {
    config(['yandex.proxy' => 'http://user:secret@proxy.test:1080']);
    try {
        YandexProxy::httpOptions();
        $this->fail('Expected invalid proxy to be rejected');
    } catch (YandexReviewException $exception) {
        expect($exception->reason)->toBe('configuration')->and($exception->getMessage())->not->toContain('secret');
    }
});

it('uses direct HTTP when no proxy is configured', function () {
    config(['yandex.proxy' => '']);
    expect(YandexProxy::httpOptions())->toBe([]);
});
