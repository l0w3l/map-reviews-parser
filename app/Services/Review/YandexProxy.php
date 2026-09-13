<?php

namespace App\Services\Review;

final class YandexProxy
{
    private static function url(): ?string
    {
        $value = config('yandex.proxy');
        if ($value === null || $value === '') {
            return null;
        }
        $parts = is_string($value) ? parse_url($value) : false;
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['socks5', 'socks5h'], true)
            || empty($parts['host']) || ! isset($parts['port'])
            || $parts['port'] < 1 || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\s\x00-\x1f]/', $value)) {
            throw new YandexReviewException('configuration', 'Некорректный YANDEX_PROXY: нужна SOCKS5-ссылка с хостом и портом.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function httpOptions(): array
    {
        $url = self::url();
        if ($url === null) {
            return [];
        }
        if (! extension_loaded('curl')) {
            throw new YandexReviewException('configuration', 'Для SOCKS5 требуется PHP-расширение curl.');
        }

        // Explicit proxy map also prevents an environment NO_PROXY from bypassing it.
        return ['proxy' => ['http' => $url, 'https' => $url, 'no' => []]];
    }

    /** @return array<string, mixed> */
    public static function browserOptions(): array
    {
        $url = self::url();
        if ($url === null) {
            return [];
        }
        $parts = parse_url($url);
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new YandexReviewException('configuration', 'Chromium не поддерживает SOCKS5 с паролем. Используйте HTML/HTTP-транспорт или SOCKS5 с авторизацией по IP.');
        }

        // Chromium resolves SOCKS5 destination names through the proxy.
        return ['proxyServer' => preg_replace('/^socks5h:/', 'socks5:', $url)];
    }
}
