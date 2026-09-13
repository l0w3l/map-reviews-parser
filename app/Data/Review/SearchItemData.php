<?php

declare(strict_types=1);

namespace App\Data\Review;

use Spatie\LaravelData\Data;

final class SearchItemData extends Data
{
    public function __construct(
        public string $type,
        public ?string $id,
        public ?string $title,
        public ?string $address,
        public ?RatingData $ratingData,
    ) {}
}
