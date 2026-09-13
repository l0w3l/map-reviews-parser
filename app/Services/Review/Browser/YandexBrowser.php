<?php

namespace App\Services\Review\Browser;

use App\Services\Review\YandexReviewException;
use HeadlessChromium\Browser\ProcessAwareBrowser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Dedicated persistent profile, exclusively locked while in use. */
class YandexBrowser
{
    private ?ProcessAwareBrowser $browser = null;

    private ?Page $page = null;

    /** @var resource|null */
    private $profileLock = null;

    private function open(bool $headless): void
    {
        $profile = (string) config('yandex.profile_dir');
        if (! is_dir($profile) && ! mkdir($profile, 0700, true) && ! is_dir($profile)) {
            throw new YandexReviewException('session_required', 'Не удалось создать профиль Chromium.');
        }
        $lock = fopen($profile.'.lock', 'c');
        if ($lock === false) {
            throw new YandexReviewException('session_required', 'Не удалось открыть блокировку профиля.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new YandexReviewException('transient', 'Браузер занят другой задачей. Повторите позже.');
        }
        $this->profileLock = $lock;
        $this->browser = (new BrowserFactory(config('yandex.chrome_binary')))->createBrowser([
            'headless' => $headless, 'userDataDir' => $profile,
            'startupTimeout' => 20, 'sendSyncDefaultTimeout' => 65000,
        ]);
        $this->page = $this->browser->createPage();
        $this->page->addPreScript((string) file_get_contents(__DIR__.'/yandex-session.js'));
        $this->page->navigate('https://yandex.ru/maps/')->waitForNavigation(Page::DOM_CONTENT_LOADED, 30000);
    }

    /** @return array<string, mixed> */
    public function diagnose(bool $headed, int $seconds): array
    {
        try {
            $this->open(! $headed);
            $deadline = microtime(true) + $seconds;
            do {
                $result = $this->page?->evaluate(<<<'JS'
(() => {
    const text = document.body?.innerText.trim().toLowerCase() || '';
    return {
        ready: !!window.__yandexSession?.context(),
        limited: text === 'limited',
        captcha: location.pathname.includes('showcaptcha') || !!document.querySelector('form[action*="checkcaptcha"]'),
        host: location.hostname,
        path: location.pathname,
        user_agent: navigator.userAgent
    };
})()
JS)->getReturnValue();
                if (is_array($result) && ($result['ready'] ?? false)) {
                    return $result;
                }
                usleep(500000);
            } while (microtime(true) < $deadline);

            return is_array($result) ? $result : ['ready' => false];
        } finally {
            $this->close();
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function request(string $endpoint, array $query): array
    {
        try {
            $this->boot();
            $arguments = json_encode(['endpoint' => $endpoint, 'query' => $query], JSON_THROW_ON_ERROR);
            $result = $this->page?->evaluate('window.__yandexSession.request('.$arguments.')')->getReturnValue(65000);
            if (! is_array($result)) {
                throw new YandexReviewException('source_changed', 'Браузер вернул неизвестный ответ.');
            }
            if (($result['failure'] ?? null) === 'blocked') {
                $reason = ($result['reason'] ?? null) === 'captcha' ? 'captcha' : 'limited';
                Log::warning('Yandex browser request blocked', [
                    'endpoint' => $endpoint, 'reason' => $reason, 'status' => $result['status'] ?? null,
                ]);
                throw new YandexReviewException('blocked', $reason === 'captcha'
                    ? 'Яндекс вернул CAPTCHA при запросе данных. Автоматическая загрузка остановлена.'
                    : 'Яндекс вернул limited при запросе данных: доступ с браузерной сессии сервера ограничен.');
            }
            if (isset($result['failure'])) {
                throw new YandexReviewException('session_required', 'Яндекс не выдал контекст сессии. Возможно, требуется проверка браузера.');
            }
            if (in_array($result['status'] ?? 0, [401, 403, 429], true) || ($result['redirected'] ?? false)) {
                throw new YandexReviewException('blocked', 'Яндекс заблокировал запрос или запросил проверку браузера.');
            }
            if (($result['status'] ?? 0) >= 500) {
                throw new YandexReviewException('transient', 'Временная ошибка Яндекса.');
            }
            $payload = $result['payload'] ?? null;
            if (($result['status'] ?? 0) !== 200 || ! is_array($payload) || isset($payload['error']) || ! is_array($payload['data'] ?? null)) {
                throw new YandexReviewException('source_changed', 'Яндекс не принял браузерный запрос или изменил формат ответа.');
            }

            return $payload['data'];
        } catch (YandexReviewException $exception) {
            $this->close();
            throw $exception;
        } catch (Throwable) {
            $this->close();
            throw new YandexReviewException('transient', 'Не удалось выполнить запрос в Chromium. Проверьте установку браузера и доступность Яндекса.');
        }
    }

    private function boot(): void
    {
        if ($this->page !== null) {
            return;
        }
        $this->open(true);
        $ready = $this->page->evaluate(<<<'JS'
(async () => {
    for (let i = 0; i < 40; i++) {
        if (window.__yandexSession?.context()) return true;
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    return false;
})()
JS)->getReturnValue(25000);
        if ($ready !== true) {
            $blockReason = $this->page->evaluate(<<<'JS'
(() => {
    const text = document.body?.innerText.trim().toLowerCase() || '';
    if (text === 'limited') return 'limited';
    if (location.pathname.includes('showcaptcha') || document.querySelector('form[action*="checkcaptcha"]')) return 'captcha';
    return 'context_missing';
})()
JS)->getReturnValue();
            Log::warning('Yandex browser bootstrap failed', ['stage' => 'bootstrap', 'reason' => $blockReason]);
            if (in_array($blockReason, ['limited', 'captcha'], true)) {
                throw new YandexReviewException('blocked', $blockReason === 'limited'
                    ? 'Стартовая страница Яндекс.Карт вернула limited. Браузер не смог создать сессию.'
                    : 'Стартовая страница Яндекс.Карт запросила CAPTCHA. Браузер не смог создать сессию.');
            }
            throw new YandexReviewException('session_required', 'Не удалось получить контекст сессии из стартовой страницы Яндекса.');
        }
    }

    public function close(): void
    {
        try {
            $this->browser?->close();
        } catch (Throwable) {
        }
        $this->page = null;
        $this->browser = null;
        if (is_resource($this->profileLock)) {
            flock($this->profileLock, LOCK_UN);
            fclose($this->profileLock);
        }
        $this->profileLock = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
