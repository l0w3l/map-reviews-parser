<?php

declare(strict_types=1);

namespace App\Services\Review;

use App\Data\Review\ParseRequestData;
use App\Data\Review\ParseResultData;
use Closure;
use Lowel\LaravelServiceMaker\Services\ServiceInterface;

interface ReviewServiceInterface extends ServiceInterface
{
    public function resolve(string $urlOrId): ParseRequestData;

    /** @param Closure(int, int): void|null $onProgress Unique collected / reported total. */
    public function parse(ParseRequestData $request, ?Closure $onProgress = null): ParseResultData;
}
