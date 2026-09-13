# YandexReviewService

HTTP-адаптер внутренних запросов, реализованный по предоставленным ответам Яндекса. Живое выполнение не проверено. Теперь сервис подключён к API и очереди. По умолчанию DI добавляет YandexBrowser для автоматического получения контекста; текущие ограничения живой проверки описаны в README. Пример ниже показывает альтернативное ручное создание HTTP-транспорта.

```php
use App\Services\Review\YandexReviewService;
use App\Data\Review\YandexSessionData;

$service = new YandexReviewService(
    context: YandexSessionData::from([
        'csrfToken' => $browserContext['csrfToken'],
        'sessionId' => $browserContext['sessionId'],
        'cookie' => $browserContext['cookie'],
        'userAgent' => $browserContext['userAgent'],
    ]),
    // Если требуется s/reqId: передайте callback, получающий endpoint и query
    // и возвращающий актуальные дополнительные параметры этого запроса.
    // Алгоритм вычисления s пока не установлен.
);

// Одна страница поиска: текст или исходная JSON-строка search_query подсказки.
$search = $service->search('М.Косметик', '31.447737,54.566637', '0.016239,0.007674');

// Метаданные + все доступные страницы отзывов.
$result = $service->parse('https://yandex.ru/maps/org/m_kosmetik/112125712262/');

// Только отзывы, с прогрессом после каждой страницы.
$reviews = $service->reviews('112125712262', function (int $collected, int $total) {
    // Обновить прогресс фонового задания.
});
```

Все DTO наследуют `Spatie\LaravelData\Data` и находятся в `app/Data/Review`. `parse()` возвращает `ParseResultData`: `$result->organization` — `OrganizationData`, `$result->reviews` — `ReviewsData`. У последнего есть `reviews` (список `ReviewData`), `available_count`, `pages`. `search()` возвращает `SearchResultData` с типизированными `items`, `totalResultCount`, `pageCount`; неподдерживаемые дополнительные поля сырого ответа не экспортируются.

```php
$result->organization->ratings_count;
$result->reviews->reviews[0]->source_updated_at;
$payload = $result->toArray(); // Вложенные organization и reviews, не прежний плоский массив.
```

Отзыв содержит source_id (reviewId), business_id, author, text, rating, source_updated_at. Последнее поле — updatedTime источника, не гарантированная дата публикации. Рейтинг сохраняется без округления; форматируйте при отображении. `YandexSessionData` скрывает cookies и токены из `toArray()`/JSON, но не предназначен для логирования или вывода через dump. Фабрика `getYandex()` принимает DTO сессии; общий `get(array $params)` преобразует массив в DTO для совместимости с интерфейсом фабрики.

`reviews_count` берётся из ratingData.reviewCount, `available_count` — из params.count выдачи отзывов. Они могут отличаться из-за ограничения доступности. Успех означает сбор всех уникальных отзывов, заявленных endpoint, а не всех когда-либо опубликованных. Если доступно меньше публичного счётчика, интерфейс должен явно это показать. Если собрано больше публичного счётчика, parse сообщает partial для повторной проверки метаданных.

Пагинация опирается на page, count, totalPages, reviewsRemained. Проверяется неизменность счётчиков, соответствие businessId, количество уникальных reviewId при завершении. Повтор страницы без прогресса, противоречивый конец и лимит страниц (100 по умолчанию) дают partial, а не тихое усечение. По умолчанию между страницами пауза 500 мс; это локальная пауза, а не распределённый rate limiter.

YandexReviewException.reason: invalid_input, session_required, unavailable, blocked, transient, source_changed, partial. Ответы и URL с токенами не включаются в исключения. Повтор transient и cooldown blocked должен организовать вызывающий job. Автоматических повторов в HTTP-адаптере нет. HTML с HTTP 200 классифицируется как неизвестный формат, а не автоматически как CAPTCHA.

Ограничения для следующего этапа:

- Браузерный транспорт получает конфигурацию из script.state-view и вычисляет s из query; ручной HTTP-адаптер по-прежнему требует передачи контекста и параметров запроса извне. Используйте отдельный браузерный контекст; не храните личные cookies в коде.
- Запрос метаданных по organization_id с пустым text и сокращённым snippets=businessrating/1.x — реализация на основе увиденной структуры search_query, но именно эта комбинация ещё не проверена на живом Яндексе. Если источник требует дополнительные параметры, уточните адаптер по перехваченному запросу карточки.
- Поиск возвращает одну страницу; обход филиалов сети не реализован. Короткие ссылки не поддержаны.
- Защита от повторов и лимиты очереди для нескольких workers остаются на уровне приложения.

Проверка: `php artisan test tests/Feature/YandexReviewServiceTest.php`. Тесты используют Http::fake, не доказывают доступность источника или отсутствие блокировки.
