<?php

namespace App\Http\Controllers\Api;

use App\Actions\Organizations\QueueOrganization;
use App\Http\Controllers\Controller;
use App\Queries\OrganizationDetails;
use App\Services\Review\Exceptions\ReviewProviderException;
use App\Services\Review\ReviewServiceFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['configured' => true, 'data' => $request->user()->organizations()->latest('id')->get()]);
    }

    public function store(Request $request, ReviewServiceFactory $providers, QueueOrganization $queue): JsonResponse
    {
        $input = $request->validate(['url' => 'required|string|max:2048', 'provider' => 'sometimes|string|max:50']);

        try {
            $reference = $providers->get(['provider' => $input['provider'] ?? 'yandex'])->resolve($input['url']);
        } catch (ReviewProviderException $exception) {
            throw ValidationException::withMessages(['url' => $exception->getMessage()]);
        }

        $organization = $queue->handle($request->user(), $reference);

        return response()->json(['id' => $organization->id], 202);
    }

    public function show(Request $request, int $id, OrganizationDetails $details): JsonResponse
    {
        $organization = $request->user()->organizations()->findOrFail($id);
        $request->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($details->get($organization));
    }
}
