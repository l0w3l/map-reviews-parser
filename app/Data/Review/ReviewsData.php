<?php

declare(strict_types=1);

namespace App\Data\Review;

use Spatie\LaravelData\Data;

final class ReviewsData extends Data
{
    /** @param list<ReviewData> $reviews */
    public function __construct(
        public array $reviews,
        public int $available_count,
        public int $pages,
        public bool $source_limited = false,
    ) {}
}
