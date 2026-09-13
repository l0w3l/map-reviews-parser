<?php

namespace App\Data\Review;

use Spatie\LaravelData\Data;

final class ParseRequestData extends Data
{
    public function __construct(
        public readonly string $provider,
        public readonly string $sourceId,
        public readonly string $url,
    ) {}
}
