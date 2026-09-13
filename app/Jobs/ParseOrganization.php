<?php

namespace App\Jobs;

use App\Data\Review\ParseResultData;
use App\Services\Review\YandexReviewException;
use App\Services\Review\YandexReviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ParseOrganization implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('parse-run-'.$this->runId))->releaseAfter(15)->expireAfter(660)];
    }

    public function __construct(public int $runId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(YandexReviewService $parser): void
    {
        $run = DB::table('parse_runs')->where('id', $this->runId)->first();
        if (! $run || in_array($run->status, ['completed', 'failed', 'blocked'])) {
            return;
        }
        $organization = DB::table('organizations')->where('id', $run->organization_id)->first();
        if (! $organization) {
            return;
        }
        DB::table('parse_runs')->where('id', $this->runId)->update(['status' => 'running', 'updated_at' => now()]);
        DB::table('organizations')->where('id', $organization->id)->update(['status' => 'running', 'error' => null]);
        Log::info('Yandex parse started', ['run_id' => $this->runId, 'organization_id' => $organization->id, 'attempt' => $this->attempts()]);
        try {
            $metadata = $parser->organization($organization->url);
            Log::info('Yandex metadata loaded', ['run_id' => $this->runId, 'reviews_count' => $metadata->reviews_count]);
            $reviews = $parser->reviews($metadata->source_id, function (int $collected, int $total): void {
                Log::info('Yandex parse progress', ['run_id' => $this->runId, 'collected' => $collected, 'total' => $total]);
                DB::table('parse_runs')->where('id', $this->runId)->update(['collected' => $collected, 'updated_at' => now()]);
            });
            if ($metadata->reviews_count < $reviews->available_count) {
                throw new YandexReviewException('partial', 'Счётчики карточки и отзывов расходятся.');
            }
            $result = new ParseResultData($metadata, $reviews);
        } catch (YandexReviewException $exception) {
            Log::warning('Yandex parse attempt failed', ['run_id' => $this->runId, 'reason' => $exception->reason, 'will_retry' => $exception->reason === 'transient' && $this->attempts() < $this->tries]);
            if ($exception->reason === 'transient') {
                DB::table('organizations')->where('id', $organization->id)->update(['status' => 'retrying']);
                throw $exception;
            }
            $this->fail($exception);

            return;
        }
        DB::transaction(function () use ($result, $organization) {
            foreach ($result->reviews->reviews as $review) {
                DB::table('reviews')->updateOrInsert(
                    ['organization_id' => $organization->id, 'source_id' => $review->source_id],
                    ['author' => $review->author, 'text' => $review->text, 'rating' => $review->rating,
                        'published_at' => Carbon::parse($review->source_updated_at)->utc(),
                        'last_seen_run_id' => $this->runId, 'updated_at' => now()],
                );
            }
            $metadata = $result->organization->toArray();
            unset($metadata['source_id'], $metadata['url']);
            DB::table('organizations')->where('id', $organization->id)->update([
                ...$metadata, 'last_successful_run_id' => $this->runId, 'status' => 'completed', 'error' => null, 'synced_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('parse_runs')->where('id', $this->runId)->update([
                'status' => 'completed', 'collected' => count($result->reviews->reviews),
                'snapshot' => json_encode($result->toArray(), JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        });
        Log::info('Yandex parse completed', ['run_id' => $this->runId, 'collected' => count($result->reviews->reviews)]);
    }

    public function failed(?Throwable $exception): void
    {
        $run = DB::table('parse_runs')->where('id', $this->runId)->first();
        if (! $run) {
            return;
        }
        Log::error('Yandex parse failed', ['run_id' => $this->runId, 'reason' => $exception instanceof YandexReviewException ? $exception->reason : 'internal']);
        report($exception ?? new \RuntimeException('Parse failed'));
        $error = $exception instanceof YandexReviewException ? $exception->getMessage() : 'Не удалось обновить данные. Повторите попытку позже.';
        $status = $exception instanceof YandexReviewException && $exception->reason === 'blocked' ? 'blocked' : 'failed';
        DB::table('parse_runs')->where('id', $this->runId)->update(['status' => $status, 'error' => $error, 'updated_at' => now()]);
        DB::table('organizations')->where('id', $run->organization_id)->update(['status' => $status, 'error' => $error]);
    }
}
