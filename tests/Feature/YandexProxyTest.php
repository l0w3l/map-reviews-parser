<?php

use App\Services\Review\YandexHtmlPage;
use App\Services\Review\YandexProxy;
use App\Services\Review\YandexReviewException;
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

it('configures Chromium SOCKS5 and refuses unsupported authentication', function () {
    config(['yandex.proxy' => 'socks5h://proxy.test:1080']);
    expect(YandexProxy::browserOptions())->toBe(['proxyServer' => 'socks5://proxy.test:1080']);
    config(['yandex.proxy' => 'socks5h://user:secret@proxy.test:1080']);
    expect(fn () => YandexProxy::browserOptions())->toThrow(YandexReviewException::class);
    config(['yandex.proxy' => '']);
    expect(YandexProxy::browserOptions())->toBe([])->and(YandexProxy::httpOptions())->toBe([]);
});
