<?php

use App\Http\Controllers\MoniepointWebhookController;
use App\Http\Controllers\ProviderWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/providers/moniepoint', MoniepointWebhookController::class)->middleware('throttle:30,1')->name('webhooks.providers.moniepoint');
Route::post('/webhooks/providers/{provider:slug}', ProviderWebhookController::class)->middleware('throttle:30,1')->name('webhooks.providers.receive');
