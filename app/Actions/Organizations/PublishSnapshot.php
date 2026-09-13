<?php

namespace App\Actions\Organizations;

use App\Data\Review\ParseResultData;
use App\Models\ParseRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PublishSnapshot
{
    public function handle(ParseRun $run, ParseResultData $result): void
    {
        DB::transaction(function () use ($run, $result) {
            $organization = $run->organization()->firstOrFail();
            foreach ($result->reviews->reviews as $review) {
                $organization->reviews()->updateOrCreate(
                    ['source_id' => $review->source_id],
                    ['author' => $review->author, 'text' => $review->text, 'rating' => $review->rating,
                        'published_at' => Carbon::parse($review->source_updated_at)->utc(), 'last_seen_run_id' => $run->id],
                );
            }
            $organization->update([
                'name' => $result->organization->name,
                'rating' => $result->organization->rating,
                'ratings_count' => $result->organization->ratings_count,
                'reviews_count' => $result->organization->reviews_count,
                'last_successful_run_id' => $run->id, 'status' => 'completed', 'error' => null, 'synced_at' => now(),
            ]);
            $run->update(['status' => 'completed', 'collected' => count($result->reviews->reviews), 'error' => null, 'snapshot' => $result->toArray()]);
        });
    }
}
