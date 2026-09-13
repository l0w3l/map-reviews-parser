<?php

namespace App\Services\Review\Providers\Yandex;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

final class YandexHtmlPage
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function clearCache(): void
    {
        $this->cache = [];
    }

    /** @return array<string, mixed> */
    public function load(string $id, int $page = 1): array
    {
        if (! preg_match('/^\d+$/D', $id) || $page < 1) {
            throw new YandexReviewException('invalid_input', 'Некорректная организация или страница.');
        }

        $key = $id.':'.$page;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/html',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36',
            ])->withOptions(YandexProxy::httpOptions())->connectTimeout((int) config('yandex.connect_timeout', 10))->timeout((int) config('yandex.request_timeout', 30))->withoutRedirecting()
                ->get('https://yandex.ru/maps/org/'.$id.'/reviews/', ['page' => $page]);
        } catch (ConnectionException) {
            throw new YandexReviewException('transient', 'Не удалось загрузить HTML Яндекс.Карт.');
        }

        $body = $response->body();

        if (in_array($response->status(), [401, 403, 429], true) || strtolower(trim($body)) === 'limited'
            || str_contains($response->header('Location'), 'showcaptcha')) {
            throw new YandexReviewException('blocked', 'Яндекс ограничил загрузку HTML (limited/CAPTCHA).');
        }

        if ($response->serverError()) {
            throw new YandexReviewException('transient', 'Временная ошибка HTML-страницы Яндекса.');
        }

        if (! $response->successful()) {
            throw new YandexReviewException('unavailable', 'HTML-страница организации недоступна.');
        }

        $dom = new DOMDocument;

        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($dom);

        $captcha = $xpath->query('//form[contains(@action,"checkcaptcha")]');

        if ($captcha !== false && $captcha->length > 0) {
            throw new YandexReviewException('blocked', 'Яндекс запросил CAPTCHA.');
        }

        $nodes = $xpath->query('//script[contains(concat(" ",normalize-space(@class)," ")," state-view ")]');

        $script = $nodes !== false ? $nodes->item(0) : null;

        if (! $script instanceof \DOMElement) {
            throw new YandexReviewException('source_changed', 'В HTML отсутствует state-view.');
        }

        try {
            $state = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new YandexReviewException('source_changed', 'В HTML отсутствует корректный state-view.');
        }

        foreach ($state['stack'] ?? [] as $entry) {
            foreach ($entry['results']['items'] ?? [] as $item) {
                if (($item['type'] ?? null) === 'business' && ($item['id'] ?? null) === $id) {
                    // Only keep the first page for metadata + reviews; subsequent pages are consumed once.
                    if ($page === 1) {
                        $this->cache[$key] = $item;
                    }

                    return $item;
                }
            }
        }

        throw new YandexReviewException('source_changed', 'В HTML нет данных запрошенной организации.');
    }
}
