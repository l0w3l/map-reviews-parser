<?php

namespace App\Queries;

use App\Models\Organization;

final class OrganizationDetails
{
    /** @return array<string, mixed> */
    public function get(Organization $organization): array
    {
        $distribution = $organization->currentReviews()->selectRaw('rating, COUNT(*) as count')->groupBy('rating')->pluck('count', 'rating');
        $snapshot = $organization->lastSuccessfulRun?->snapshot;

        return [
            'organization' => $organization->attributesToArray(),
            'statistics' => [
                'distribution' => collect(range(0, 5))->map(fn ($rating) => ['rating' => $rating, 'count' => (int) ($distribution[$rating] ?? 0)]),
                'source_limited' => (data_get($snapshot, 'reviews.outcome') === 'source_limit_reached' || (bool) data_get($snapshot, 'reviews.source_limited', false)),
            ],
            'history' => $organization->runs()->latest('id')->limit(5)->get(['id', 'status', 'collected', 'error', 'created_at', 'updated_at']),
            'run' => $organization->latestRun()->first(['id', 'status', 'collected', 'error', 'updated_at']),
            'reviews' => $organization->currentReviews()->orderByDesc('published_at')->orderByDesc('id')->paginate(50),
        ];
    }
}
