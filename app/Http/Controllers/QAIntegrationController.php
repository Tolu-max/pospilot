<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class QAIntegrationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('QAIntegration/Index', [
            'user' => $request->user()->only(['id', 'name', 'email']),
        ]);
    }
}
