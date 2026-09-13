<?php

declare(strict_types=1);

namespace App\Data\Review;

use Spatie\LaravelData\Data;

final class SearchResultData extends Data
{
    /** @param list<SearchItemData> $items */
    public function __construct(
        public array $items,
        public ?int $totalResultCount,
        public ?int $pageCount,
    ) {}
}
