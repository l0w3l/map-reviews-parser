<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ParseOrganization;
use App\Services\Review\YandexReviewException;
use App\Services\Review\YandexReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class OrganizationController extends Controller
{
    public function search(Request $request, YandexReviewService $service): JsonResponse
    {
        $input = $request->validate(['query' => 'required|string|min:2|max:500']);
        Log::info('Yandex search started', ['execution' => 'http']);
        try {
            return response()->json($service->search($input['query'])->toArray());
        } catch (YandexReviewException $exception) {
            Log::warning('Yandex search failed', ['execution' => 'http', 'reason' => $exception->reason]);

            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason],
                $exception->reason === 'blocked' ? 503 : 502);
        }
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['configured' => config('yandex.html_reviews') || config('yandex.transport') === 'browser' || (filled(config('yandex.csrf_token')) && filled(config('yandex.session_id'))), 'data' => DB::table('organizations')->where('user_id', $request->user()->id)->latest('id')->get()]);
    }

    public function store(Request $request, YandexReviewService $service): JsonResponse
    {
        $input = $request->validate(['url' => 'required|string|max:2048']);
        try {
            $sourceId = $service->organizationId($input['url']);
        } catch (YandexReviewException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }
        $url = ['source_id' => $sourceId, 'url' => 'https://yandex.ru/maps/org/'.$sourceId.'/'];
        $id = DB::transaction(function () use ($request, $url) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $organization = DB::table('organizations')->where('user_id', $request->user()->id)->where('source_id', $url['source_id'])->first();
            if ($organization && in_array($organization->status, ['queued', 'running', 'retrying'])) {
                return $organization->id;
            }
            $id = $organization->id ?? DB::table('organizations')->insertGetId([...$url, 'user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('organizations')->where('id', $id)->update(['status' => 'queued', 'error' => null]);
            $run = DB::table('parse_runs')->insertGetId(['organization_id' => $id, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
            ParseOrganization::dispatch($run)->afterCommit();
            DB::afterCommit(fn () => Log::info('Yandex parse queued', ['run_id' => $run, 'organization_id' => $id, 'connection' => config('queue.default')]));

            return $id;
        });

        return response()->json(['id' => $id], 202);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $organization = DB::table('organizations')->where('user_id', $request->user()->id)->where('id', $id)->first();
        abort_unless($organization !== null, 404);
        $request->validate(['page' => 'sometimes|integer|min:1']);

        $saved = DB::table('reviews')->where('organization_id', $id)->where('last_seen_run_id', $organization->last_successful_run_id);
        $distribution = (clone $saved)->selectRaw('rating, COUNT(*) as count')->groupBy('rating')->pluck('count', 'rating');
        $snapshot = DB::table('parse_runs')->where('id', $organization->last_successful_run_id)->value('snapshot');

        return response()->json([
            'statistics' => [
                'distribution' => collect(range(0, 5))->map(fn ($rating) => ['rating' => $rating, 'count' => (int) ($distribution[$rating] ?? 0)]),
                'source_limited' => (bool) data_get(json_decode($snapshot ?? '{}', true), 'reviews.source_limited', false),
            ],
            'history' => DB::table('parse_runs')->where('organization_id', $id)->latest('id')->limit(5)->get(['id', 'status', 'collected', 'error', 'created_at', 'updated_at']),
            'organization' => $organization,
            'run' => DB::table('parse_runs')->where('organization_id', $id)->latest('id')->first(['id', 'status', 'collected', 'error', 'updated_at']),
            'reviews' => DB::table('reviews')->where('organization_id', $id)->where('last_seen_run_id', $organization->last_successful_run_id)->orderByDesc('published_at')->orderByDesc('id')->paginate(50),
        ]);
    }
}
