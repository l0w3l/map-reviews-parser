<?php

declare(strict_types=1);

namespace App\Data\Review;

use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;

final class YandexSessionData extends Data
{
    public function __construct(
        #[Hidden] public string $csrfToken = '',
        #[Hidden] public string $sessionId = '',
        #[Hidden] public string $cookie = '',
        public string $userAgent = 'Mozilla/5.0',
    ) {}
}
