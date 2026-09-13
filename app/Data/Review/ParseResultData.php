<?php

declare(strict_types=1);

namespace App\Data\Review;

use Spatie\LaravelData\Data;

final class ParseResultData extends Data
{
    public function __construct(
        public OrganizationData $organization,
        public ReviewsData $reviews,
    ) {}
}
