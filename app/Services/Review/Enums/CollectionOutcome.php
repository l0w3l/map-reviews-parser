<?php

namespace App\Services\Review\Enums;

enum CollectionOutcome: string
{
    case Complete = 'complete';
    case SourceLimitReached = 'source_limit_reached';
}
