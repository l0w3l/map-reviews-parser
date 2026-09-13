<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $source_id
 * @property string $author
 * @property CarbonImmutable $published_at
 * @property string $text
 * @property int $rating
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int|null $last_seen_run_id
 * @property-read ParseRun|null $lastSeenRun
 * @property-read Organization $organization
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereAuthor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereLastSeenRunId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereSourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereText($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Review whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Review extends Model
{
    protected $fillable = ['source_id', 'author', 'text', 'rating', 'published_at', 'last_seen_run_id'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ParseRun, $this> */
    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(ParseRun::class, 'last_seen_run_id');
    }
}
