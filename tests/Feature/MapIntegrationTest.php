<?php

use App\Actions\Organizations\PublishSnapshot;
use App\Data\Review\OrganizationData;
use App\Data\Review\ParseRequestData;
use App\Data\Review\ParseResultData;
use App\Data\Review\ReviewData;
use App\Data\Review\ReviewsData;
use App\Jobs\ParseOrganization;
use App\Models\ParseRun;
use App\Models\User;
use App\Services\Review\Enums\CollectionOutcome;
use App\Services\Review\Providers\Yandex\YandexOrganizationUrl;
use App\Services\Review\Providers\Yandex\YandexReviewException;
use App\Services\Review\ReviewServiceFactory;
use App\Services\Review\ReviewServiceInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Fixtures/yandex-html.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config(['yandex.pause_milliseconds' => 0]);
});
it('requires authentication and rejects registration', function () {
    $this->getJson('/api/organizations')->assertUnauthorized();
    $this->postJson('/register', [])->assertNotFound();
});
it('normalizes organization links without fetching arbitrary hosts', function () {
    expect(app(YandexOrganizationUrl::class)->organizationId('https://yandex.ru/maps/org/cafe/123/reviews/?foo=bar'))->toBe('123');
    foreach (['https://yandex.ru.evil.test/maps/org/123/', 'http://yandex.ru/maps/org/123/', 'https://user@yandex.ru/maps/org/123/', 'https://yandex.ru:443/maps/org/123/'] as $url) {
        expect(fn () => app(YandexOrganizationUrl::class)->organizationId($url))->toThrow(YandexReviewException::class);
    }
});
it('queues only one active run and separates source counters from pagination', function () {
    Queue::fake();
    Http::fake(function ($request) {
        return Http::response(reviewHtml(['reviews' => array_map(fn ($i) => [
            'reviewId' => (string) $i, 'businessId' => '123', 'author' => ['name' => 'Test'], 'text' => 'Text', 'rating' => 5, 'updatedTime' => '2026-09-01T12:00:00Z',
        ], range(1, 105)), 'params' => ['page' => 1, 'count' => 105, 'totalPages' => 1, 'reviewsRemained' => 0]], '123', 150));
    });
    $user = User::factory()->create();
    $this->actingAs($user);
    $id = $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/'])->assertAccepted()->json('id');
    $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/'])->assertAccepted();
    Queue::assertPushed(ParseOrganization::class, 1);
    $job = new ParseOrganization(DB::table('parse_runs')->value('id'));
    $job->handle(app(ReviewServiceFactory::class), app(PublishSnapshot::class));
    $this->getJson('/api/organizations/'.$id)->assertOk()->assertJsonCount(50, 'reviews.data')->assertJsonPath('reviews.total', 105)->assertJsonPath('organization.ratings_count', 150)->assertJsonPath('organization.reviews_count', 105);
    $this->getJson('/api/organizations/'.$id.'?page=3')->assertJsonCount(5, 'reviews.data');
    $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/']);
    (new ParseOrganization(DB::table('parse_runs')->max('id')))->handle(app(ReviewServiceFactory::class), app(PublishSnapshot::class));
    expect(DB::table('reviews')->count())->toBe(105);
    expect(DB::table('parse_runs')->whereNotNull('snapshot')->count())->toBe(2);
    $this->actingAs(User::factory()->create())->getJson('/api/organizations/'.$id)->assertNotFound();
});
it('does not replace previous data when parsing fails', function () {
    Queue::fake();
    Http::fake(function ($request) {
        return Http::response(reviewHtml(['reviews' => array_map(fn ($i) => [
            'reviewId' => (string) $i, 'businessId' => '123', 'author' => ['name' => 'Test'], 'text' => 'Text', 'rating' => 5, 'updatedTime' => '2026-09-01T12:00:00Z',
        ], range(1, 105)), 'params' => ['page' => 1, 'count' => 105, 'totalPages' => 1, 'reviewsRemained' => 0]], '123', 150));
    });
    $this->actingAs(User::factory()->create());
    $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/']);
    (new ParseOrganization(1))->handle(app(ReviewServiceFactory::class), app(PublishSnapshot::class));
    $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/']);
    Http::swap(new Factory);
    Http::fake(['*' => Http::response([], 503)]);
    $job = new ParseOrganization(2);
    expect(fn () => $job->handle(app(ReviewServiceFactory::class), app(PublishSnapshot::class)))->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('source_changed'));
    expect(DB::table('reviews')->count())->toBe(105);
    expect(DB::table('organizations')->value('status'))->toBe('failed');
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

it('runs a provider through its contract and publishes its collection outcome', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $id = $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/'])->assertAccepted()->json('id');
    $run = ParseRun::firstOrFail();
    $provider = Mockery::mock(ReviewServiceInterface::class);
    $provider->shouldReceive('parse')->once()->with(Mockery::on(fn ($request) => $request instanceof ParseRequestData && $request->sourceId === '123' && $request->provider === 'yandex'), Mockery::type(Closure::class))
        ->andReturnUsing(function (ParseRequestData $request, $progress) {
            $id = $request->sourceId;
            $progress(1, 1000);

            return new ParseResultData(
                new OrganizationData($id, 'Test', 'https://yandex.ru/maps/org/123/', 4.5, 1500, 1000),
                new ReviewsData([
                    new ReviewData('r1', $id, 'Test', 'Text', 5, '2026-09-01T12:00:00Z'),
                ], 1, 1, CollectionOutcome::SourceLimitReached),
            );
        });
    $factory = Mockery::mock(ReviewServiceFactory::class);
    $factory->shouldReceive('get')->with(['provider' => 'yandex'])->once()->andReturn($provider);
    $this->instance(ReviewServiceFactory::class, $factory);
    app()->call([new ParseOrganization($run->id), 'handle']);
    expect($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->collected)->toBe(1)
        ->and($run->fresh()->snapshot['reviews']['outcome'])->toBe('source_limit_reached');
    $this->getJson('/api/organizations/'.$id)->assertOk()->assertJsonPath('statistics.source_limited', true);
    Http::assertNothingSent();
});

it('keeps identical external IDs separate across providers and dispatches the stored provider', function () {
    Queue::fake();
    $provider = Mockery::mock(ReviewServiceInterface::class);
    $provider->shouldReceive('resolve')->with('https://example.test/places/123')->twice()
        ->andReturn(new ParseRequestData('example', '123', 'https://example.test/places/123'));
    $factory = Mockery::mock(ReviewServiceFactory::class)->makePartial();
    $factory->shouldReceive('get')->with(['provider' => 'example'])->andReturn($provider);
    $this->instance(ReviewServiceFactory::class, $factory);
    $this->actingAs(User::factory()->create());
    $yandexId = $this->postJson('/api/organizations', ['url' => 'https://yandex.ru/maps/org/123/'])->assertAccepted()->json('id');
    $payload = ['provider' => 'example', 'url' => 'https://example.test/places/123'];
    $exampleId = $this->postJson('/api/organizations', $payload)->assertAccepted()->json('id');
    $this->postJson('/api/organizations', $payload)->assertAccepted()->assertJsonPath('id', $exampleId);
    expect($exampleId)->not->toBe($yandexId);
    Queue::assertPushed(ParseOrganization::class, 2);
    $provider->shouldReceive('parse')->once()->andReturnUsing(function (ParseRequestData $request, Closure $progress) {
        expect($request->provider)->toBe('example')
            ->and($request->sourceId)->toBe('123')
            ->and($request->url)->toBe('https://example.test/places/123');
        $progress(0, 0);

        return new ParseResultData(
            new OrganizationData('123', 'Example', $request->url, null, 0, 0),
            new ReviewsData([], 0, 1),
        );
    });
    $run = ParseRun::where('organization_id', $exampleId)->firstOrFail();
    app()->call([new ParseOrganization($run->id), 'handle']);
    expect($run->fresh()->status)->toBe('completed');
    $this->getJson('/api/organizations/'.$exampleId)->assertJsonPath('organization.provider', 'example');
    Http::assertNothingSent();
});

it('rejects unregistered providers without falling back to Yandex', function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
    $this->postJson('/api/organizations', ['provider' => 'unknown', 'url' => 'https://yandex.ru/maps/org/123/'])->assertUnprocessable();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
