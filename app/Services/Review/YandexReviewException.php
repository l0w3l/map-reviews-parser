<?php

declare(strict_types=1);

namespace App\Services\Review;

use RuntimeException;

final class YandexReviewException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
