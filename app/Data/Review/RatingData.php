<?php

declare(strict_types=1);

namespace App\Data\Review;

use Spatie\LaravelData\Data;

final class RatingData extends Data
{
    public function __construct(
        public ?float $ratingValue,
        public int $ratingCount,
        public int $reviewCount,
    ) {}
}
