<?php

use App\Http\Controllers\Api\OrganizationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());
    Route::get('/organizations/search', [OrganizationController::class, 'search'])->middleware('throttle:20,1');
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/organizations/{id}', [OrganizationController::class, 'show'])->whereNumber('id');
});
