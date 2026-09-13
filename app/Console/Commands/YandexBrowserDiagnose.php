<?php

namespace App\Console\Commands;

use App\Services\Review\Browser\YandexBrowser;
use App\Services\Review\YandexReviewException;
use Illuminate\Console\Command;
use Throwable;

final class YandexBrowserDiagnose extends Command
{
    protected $signature = 'yandex:browser-diagnose {--headed : Открыть окно для ручной проверки} {--wait=20 : Время ожидания сессии в секундах (1–300)}';

    protected $description = 'Проверить доступ к Картам с постоянным профилем Chromium';

    public function handle(YandexBrowser $browser): int
    {
        $seconds = filter_var($this->option('wait'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 300]]);
        if ($seconds === false) {
            $this->error('--wait должен быть целым числом от 1 до 300.');

            return self::INVALID;
        }
        $this->info('Профиль: '.config('yandex.profile_dir'));
        if ($this->option('headed')) {
            $this->info('Если появится CAPTCHA, пройдите её в открытом окне. Затем откройте https://yandex.ru/maps/.');
        }
        try {
            $result = $browser->diagnose((bool) $this->option('headed'), $seconds);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return ($result['ready'] ?? false) ? self::SUCCESS : self::FAILURE;
        } catch (YandexReviewException $exception) {
            $this->error($exception->getMessage());
        } catch (Throwable $exception) {
            $this->error('Не удалось запустить диагностику Chromium ('.$exception::class.'). Проверьте бинарник, права на профиль и DISPLAY для режима --headed.');
        }

        return self::FAILURE;
    }
}
