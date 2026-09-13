<?php

namespace App\Services\Review\Providers\Yandex;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

trait ValidatesYandexResponse
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $rules
     */
    private function validate(array $data, array $rules): void
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            Log::warning('Yandex schema mismatch', ['fields' => array_keys($validator->failed())]);
            throw new YandexReviewException('source_changed', 'Структура ответа Яндекс.Карт изменилась.');
        }
    }
}
