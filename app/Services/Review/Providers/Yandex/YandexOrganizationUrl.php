<?php

namespace App\Services\Review\Providers\Yandex;

use App\Data\Review\ParseRequestData;

final class YandexOrganizationUrl
{
    public function resolve(string $urlOrId): ParseRequestData
    {
        $id = $this->organizationId($urlOrId);

        return new ParseRequestData('yandex', $id, 'https://yandex.ru/maps/org/'.$id.'/');
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
}
