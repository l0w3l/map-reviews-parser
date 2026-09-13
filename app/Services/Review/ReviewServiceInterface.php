<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Data\Review\ParseResultData;
use Lowel\LaravelServiceMaker\Services\ServiceInterface;

interface ReviewServiceInterface extends ServiceInterface
{
    public function parse(string $urlOrId): ParseResultData;
}
