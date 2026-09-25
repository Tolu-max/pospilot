<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use Illuminate\Http\Request;

class ProviderWebhookController extends Controller
{
    public function __invoke(Request $request, Provider $provider)
    {
        abort(501, 'Provider webhook ingestion is disabled until signature verification is configured.');
    }
}
