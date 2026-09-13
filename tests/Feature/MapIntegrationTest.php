<?php

use App\Data\Review\SearchResultData;
use App\Jobs\ParseOrganization;
use App\Models\User;
use App\Services\Review\YandexReviewException;
use App\Services\Review\YandexReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['yandex.html_reviews' => false]));
it('requires authentication and rejects registration', function () {
    $this->getJson('/api/organizations')->assertUnauthorized();
    $this->postJson('/register', [])->assertNotFound();
});
it('normalizes organization links without fetching arbitrary hosts', function () {
    expect(app(YandexReviewService::class)->organizationId('https://yandex.ru/maps/org/cafe/123/reviews/?foo=bar'))->toBe('123');
    foreach (['https://yandex.ru.evil.test/maps/org/123/', 'http://yandex.ru/maps/org/123/', 'https://user@yandex.ru/maps/org/123/', 'https://yandex.ru:443/maps/org/123/'] as $url) {
        expect(fn () => app(YandexReviewService::class)->organizationId($url))->toThrow(YandexReviewException::class);
    }
});
it('queues only one active run and separates source counters from pagination', function () {
    Queue::fake();
    config(['yandex.transport' => 'http', 'yandex.csrf_token' => 'test', 'yandex.session_id' => 'test']);
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/search?')) {
            return Http::response(['data' => ['items' => [[
                'id' => '123', 'type' => 'business', 'title' => 'Test', 'ratingData' => ['ratingValue' => 4.5, 'ratingCount' => 150, 'reviewCount' => 105],
            ]]]]);
        }

        return Http::response(['data' => ['reviews' => array_map(fn ($i) => [
            'reviewId' => (string) $i, 'businessId' => '123', 'author' => ['name' => 'Test'], 'text' => 'Text', 'rating' => 5, 'updatedTime' => '2026-09-01T12:00:00Z',
        ], range(1, 105)), 'params' => ['page' => 1, 'count' => 105, 'totalPages' => 1, 'reviewsRemained' => 0]]]);
    });
    $user = User::factory()->create();
    $this->actingAs($user);
    $id = $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/'])->assertAccepted()->json('id');
    $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/'])->assertAccepted();
    Queue::assertPushed(ParseOrganization::class, 1);
    $job = new ParseOrganization(DB::table('parse_runs')->value('id'));
    $job->handle(app(YandexReviewService::class));
    $this->getJson('/api/organizations/'.$id)->assertOk()->assertJsonCount(50, 'reviews.data')->assertJsonPath('reviews.total', 105)->assertJsonPath('organization.ratings_count', 150)->assertJsonPath('organization.reviews_count', 105);
    $this->getJson('/api/organizations/'.$id.'?page=3')->assertJsonCount(5, 'reviews.data');
    $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/']);
    (new ParseOrganization(DB::table('parse_runs')->max('id')))->handle(app(YandexReviewService::class));
    expect(DB::table('reviews')->count())->toBe(105);
    expect(DB::table('parse_runs')->whereNotNull('snapshot')->count())->toBe(2);
    $this->actingAs(User::factory()->create())->getJson('/api/organizations/'.$id)->assertNotFound();
});
it('does not replace previous data when parsing fails', function () {
    Queue::fake();
    config(['yandex.transport' => 'http', 'yandex.csrf_token' => 'test', 'yandex.session_id' => 'test']);
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/search?')) {
            return Http::response(['data' => ['items' => [[
                'id' => '123', 'type' => 'business', 'title' => 'Test', 'ratingData' => ['ratingValue' => 4.5, 'ratingCount' => 150, 'reviewCount' => 105],
            ]]]]);
        }

        return Http::response(['data' => ['reviews' => array_map(fn ($i) => [
            'reviewId' => (string) $i, 'businessId' => '123', 'author' => ['name' => 'Test'], 'text' => 'Text', 'rating' => 5, 'updatedTime' => '2026-09-01T12:00:00Z',
        ], range(1, 105)), 'params' => ['page' => 1, 'count' => 105, 'totalPages' => 1, 'reviewsRemained' => 0]]]);
    });
    $this->actingAs(User::factory()->create());
    $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/']);
    (new ParseOrganization(1))->handle(app(YandexReviewService::class));
    $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/']);
    Http::swap(new Factory);
    Http::fake(['*' => Http::response([], 503)]);
    $job = new ParseOrganization(2);
    expect(fn () => $job->handle(app(YandexReviewService::class)))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('source_changed'));
    expect(DB::table('reviews')->count())->toBe(105);
    expect(DB::table('organizations')->value('status'))->toBe('failed');
});

it('exposes authenticated search and handles upstream errors', function () {
    $this->getJson('/api/organizations/search?query=Test')->assertUnauthorized();
    $this->actingAs(User::factory()->create());
    $this->getJson('/api/organizations/search?query=x')->assertUnprocessable();
    $service = Mockery::mock(YandexReviewService::class);
    $service->shouldReceive('search')->with('Test')->once()->andReturn(new SearchResultData([], 0, 0));
    $service->shouldReceive('search')->with('Blocked')->once()->andThrow(new YandexReviewException('blocked', 'Blocked'));
    $this->instance(YandexReviewService::class, $service);
    $this->getJson('/api/organizations/search?query=Test')->assertOk()->assertJsonPath('items', []);
    $this->getJson('/api/organizations/search?query=Blocked')->assertStatus(503)->assertJsonPath('reason', 'blocked');
});

it('computes statistics only from the saved snapshot and keeps history private', function () {
    $user = User::factory()->create();
    $id = DB::table('organizations')->insertGetId(['user_id' => $user->id, 'source_id' => '123', 'url' => 'https://yandex.ru/maps/org/123/']);
    $run = DB::table('parse_runs')->insertGetId(['organization_id' => $id, 'status' => 'completed', 'snapshot' => json_encode(['reviews' => ['source_limited' => true]])]);
    DB::table('organizations')->where('id', $id)->update(['last_successful_run_id' => $run]);
    foreach ([5, 5, 1, 0] as $key => $rating) {
        DB::table('reviews')->insert(['organization_id' => $id, 'source_id' => (string) $key, 'author' => 'Test', 'text' => 'Review', 'rating' => $rating, 'published_at' => now(), 'last_seen_run_id' => $key === 0 ? null : $run]);
    }
    $this->actingAs($user)->getJson('/api/organizations/'.$id)->assertOk()
        ->assertJsonPath('statistics.distribution.5.count', 1)
        ->assertJsonPath('statistics.distribution.0.count', 1)
        ->assertJsonPath('statistics.source_limited', true)
        ->assertJsonPath('reviews.total', 3)->assertJsonCount(1, 'history');
    $this->actingAs(User::factory()->create())->getJson('/api/organizations/'.$id)->assertNotFound();
});
