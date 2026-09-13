<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $source_id
 * @property string $url
 * @property string|null $name
 * @property numeric|null $rating
 * @property int|null $ratings_count
 * @property-read int|null $reviews_count
 * @property string $status
 * @property string|null $error
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int|null $last_successful_run_id
 * @property-read Collection<int, Review> $currentReviews
 * @property-read int|null $current_reviews_count
 * @property-read ParseRun|null $lastSuccessfulRun
 * @property-read ParseRun|null $latestRun
 * @property-read Collection<int, Review> $reviews
 * @property-read Collection<int, ParseRun> $runs
 * @property-read int|null $runs_count
 * @property-read User $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereLastSuccessfulRunId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereRatingsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereReviewsCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereSourceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereSyncedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Organization extends Model
{
    protected $fillable = ['provider', 'source_id', 'url', 'name', 'rating', 'ratings_count', 'reviews_count', 'status', 'error', 'synced_at', 'last_successful_run_id'];

    protected function casts(): array
    {
        return ['rating' => 'decimal:2', 'ratings_count' => 'integer', 'reviews_count' => 'integer', 'synced_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** @return HasMany<Review, $this> */
    public function currentReviews(): HasMany
    {
        // Before the first successful run, no historical/unassigned rows are current.
        return $this->reviews()->where('last_seen_run_id', $this->last_successful_run_id ?? 0);
    }

    /** @return HasMany<ParseRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ParseRun::class);
    }

    /** @return HasOne<ParseRun, $this> */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ParseRun::class)->latestOfMany();
    }

    /** @return BelongsTo<ParseRun, $this> */
    public function lastSuccessfulRun(): BelongsTo
    {
        return $this->belongsTo(ParseRun::class, 'last_successful_run_id');
    }

    public function isParsing(): bool
    {
        return in_array($this->status, ['queued', 'running', 'retrying'], true);
    }
}
