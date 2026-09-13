<?php

declare(strict_types=1);

namespace App\Data\Review;

use Spatie\LaravelData\Data;

final class ReviewData extends Data
{
    public function __construct(
        public string $source_id,
        public string $business_id,
        public string $author,
        public string $text,
        public int $rating,
        public string $source_updated_at,
    ) {}
}
