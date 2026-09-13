<?php

use App\Actions\Organizations\PublishSnapshot;
use App\Data\Review\OrganizationData;
use App\Data\Review\ParseResultData;
use App\Data\Review\ReviewData;
use App\Data\Review\ReviewsData;
use App\Models\Review;
use App\Models\User;

it('rolls back the entire snapshot if saving a review fails', function () {
    $organization = User::factory()->create()->organizations()->create(['source_id' => '123', 'url' => 'https://yandex.ru/maps/org/123/', 'name' => 'Old', 'status' => 'completed']);
    $previous = $organization->runs()->create(['status' => 'completed', 'snapshot' => ['old' => true]]);
    $organization->update(['last_successful_run_id' => $previous->id]);
    $saved = $organization->reviews()->create(['source_id' => 'a', 'author' => 'Author', 'text' => 'Old text', 'rating' => 5, 'published_at' => now(), 'last_seen_run_id' => $previous->id]);
    $run = $organization->runs()->create(['status' => 'running']);
    $result = new ParseResultData(new OrganizationData('123', 'New', $organization->url, 4.5, 100, 2), new ReviewsData([
        new ReviewData('a', '123', 'Author', 'New text', 4, '2026-09-01T00:00:00Z'),
        new ReviewData('b', '123', 'Author', 'Another', 5, '2026-09-01T00:00:00Z'),
    ], 2, 1));
    Review::saving(function (Review $review) {
        if ($review->source_id === 'b') {
            throw new RuntimeException('Storage failure');
        }
    });
    try {
        expect(fn () => app(PublishSnapshot::class)->handle($run, $result))->toThrow(RuntimeException::class);
    } finally {
        Review::flushEventListeners();
    }
    expect($saved->fresh()->text)->toBe('Old text')
        ->and($organization->fresh()->last_successful_run_id)->toBe($previous->id)
        ->and($organization->reviews()->count())->toBe(1)
        ->and($run->fresh()->snapshot)->toBeNull();
});

it('does not expose unassigned reviews or large snapshots through the details API', function () {
    $user = User::factory()->create();
    $organization = $user->organizations()->create(['source_id' => '123', 'url' => 'https://yandex.ru/maps/org/123/']);
    $organization->reviews()->create(['source_id' => 'orphan', 'author' => 'A', 'text' => 'Old', 'rating' => 5, 'published_at' => now()]);
    $organization->runs()->create(['status' => 'failed', 'snapshot' => ['private_snapshot' => true]]);
    $this->actingAs($user)->getJson('/api/organizations/'.$organization->id)
        ->assertOk()->assertJsonPath('reviews.total', 0)
        ->assertJsonMissingPath('run.snapshot')->assertJsonMissingPath('history.0.snapshot');
});
