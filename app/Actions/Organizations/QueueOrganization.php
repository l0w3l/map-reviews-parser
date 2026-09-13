<?php

namespace App\Actions\Organizations;

use App\Data\Review\ParseRequestData;
use App\Jobs\ParseOrganization;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class QueueOrganization
{
    public function handle(User $user, ParseRequestData $reference): Organization
    {
        return DB::transaction(function () use ($user, $reference) {
            // Serialize first-time connections as well as refreshes for this owner.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $organization = $user->organizations()->firstOrCreate(
                ['provider' => $reference->provider, 'source_id' => $reference->sourceId],
                ['url' => $reference->url, 'status' => 'queued'],
            );
            if (! $organization->wasRecentlyCreated && $organization->isParsing()) {
                return $organization;
            }
            $organization->update(['status' => 'queued', 'error' => null]);
            $run = $organization->runs()->create(['status' => 'queued', 'collected' => 0]);
            ParseOrganization::dispatch($run->id)->afterCommit();
            DB::afterCommit(fn () => Log::info('Review parse queued', ['run_id' => $run->id, 'organization_id' => $organization->id, 'connection' => config('queue.default')]));

            return $organization;
        });
    }
}
