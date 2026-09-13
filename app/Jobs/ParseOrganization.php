<?php

namespace App\Jobs;

use App\Actions\Organizations\PublishSnapshot;
use App\Data\Review\ParseRequestData;
use App\Models\ParseRun;
use App\Services\Review\Exceptions\ReviewProviderException;
use App\Services\Review\ReviewServiceFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    public function handle(ReviewServiceFactory $providers, PublishSnapshot $publish): void
    {
        $run = ParseRun::find($this->runId);

        if (! $run || $run->isFinished()) {
            return;
        }

        $organization = $run->organization()->first();

        if (! $organization) {
            return;
        }

        $run->update(['status' => 'running', 'collected' => 0, 'error' => null]);
        $organization->update(['status' => 'running', 'error' => null]);

        Log::info('Review parse started', ['run_id' => $this->runId, 'organization_id' => $organization->id, 'attempt' => $this->attempts()]);

        try {
            $request = new ParseRequestData($organization->provider, $organization->source_id, $organization->url);
            $result = $providers->get(['provider' => $organization->provider])->parse($request, function (int $collected, int $total) use ($run): void {
                Log::info('Review parse progress', ['run_id' => $this->runId, 'collected' => $collected, 'total' => $total]);
                $run->update(['collected' => $collected, 'updated_at' => now()]);
            });

        } catch (ReviewProviderException $exception) {
            Log::warning('Review parse attempt failed', ['run_id' => $this->runId, 'reason' => $exception->reason, 'will_retry' => $exception->reason === 'transient' && $this->attempts() < $this->tries]);

            if ($exception->reason === 'transient') {
                $run->update(['status' => 'retrying']);
                $organization->update(['status' => 'retrying']);
                throw $exception;
            }

            $this->fail($exception);

            return;
        }

        $publish->handle($run, $result);

        Log::info('Review parse completed', ['run_id' => $this->runId, 'collected' => count($result->reviews->reviews)]);
    }

    public function failed(?Throwable $exception): void
    {
        $run = ParseRun::find($this->runId);

        if (! $run) {
            return;

        }

        Log::error('Review parse failed', ['run_id' => $this->runId, 'reason' => $exception instanceof ReviewProviderException ? $exception->reason : 'internal']);

        report($exception ?? new \RuntimeException('Parse failed'));

        $error = $exception instanceof ReviewProviderException ? $exception->getMessage() : 'Не удалось обновить данные. Повторите попытку позже.';
        $status = $exception instanceof ReviewProviderException && $exception->reason === 'blocked' ? 'blocked' : 'failed';

        $run->update(['status' => $status, 'error' => $error, 'updated_at' => now()]);
        $run->organization()->update(['status' => $status, 'error' => $error]);
    }
}
