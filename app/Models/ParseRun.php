<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $status
 * @property int $collected
 * @property array<array-key, mixed>|null $snapshot
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Organization $organization
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun whereCollected($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun whereSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParseRun whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ParseRun extends Model
{
    protected $fillable = ['status', 'collected', 'error', 'snapshot'];

    // Large historical snapshots are not part of normal API responses.
    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['collected' => 'integer', 'snapshot' => 'array'];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'blocked'], true);
    }
}
