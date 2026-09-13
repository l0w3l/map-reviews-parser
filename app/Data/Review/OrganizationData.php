<?php

declare(strict_types=1);

namespace App\Data\Review;

use Spatie\LaravelData\Data;

final class OrganizationData extends Data
{
    public function __construct(
        public string $source_id,
        public string $name,
        public string $url,
        public ?float $rating,
        public int $ratings_count,
        public int $reviews_count,
    ) {}
}
