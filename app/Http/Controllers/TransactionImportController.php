<?php

namespace App\Http\Controllers;

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Services\CsvImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;

class TransactionImportController extends Controller
{
    public function create(Request $request)
    {
        $agent = $request->user()->agentProfile;
        abort_unless($agent, 404, 'Agent profile not found.');
        $other = Provider::firstOrCreate(['slug' => 'other'], ['name' => 'Generic / Other', 'status' => ProviderStatus::Active->value]);

        return Inertia::render('Transactions/Import', ['providers' => Provider::where('status', ProviderStatus::Active->value)->orderBy('name')->get(['id', 'name', 'slug']), 'recentImports' => $agent->importBatches()->with('provider')->latest()->limit(10)->get()]);
    }

    public function preview(Request $request, CsvImportService $imports)
    {
        $validated = $request->validate(['provider_id' => 'required|exists:providers,id', 'file' => 'required|file|mimes:csv,txt|max:5120', 'mapping' => 'nullable|array']);
        $agent = $request->user()->agentProfile;
        abort_unless($agent, 404, 'Agent profile not found.');
        $provider = Provider::findOrFail($validated['provider_id']);
        try {
            $preview = $imports->preview($validated['file'], $agent, $provider, $validated['mapping'] ?? []);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable) {
            Log::warning('Transaction CSV preview failed', ['provider_id' => $provider->id, 'agent_profile_id' => $agent->id]);

            return response()->json(['message' => 'The CSV could not be processed. Check its format and try again.'], 422);
        }
        $token = (string) Str::uuid();
        session()->put('transaction_imports.'.$token, ['expires_at' => now()->addMinutes(15)->toIso8601String(), 'provider_id' => $provider->id, 'filename' => $validated['file']->getClientOriginalName(), 'preview' => $preview]);

        return response()->json([...$preview, 'preview_token' => $token, 'provider' => $provider->only(['id', 'name', 'slug'])]);
    }

    public function confirm(Request $request, CsvImportService $imports)
    {
        $validated = $request->validate(['preview_token' => 'required|string']);
        $stored = session('transaction_imports.'.$validated['preview_token']);
        abort_unless($stored && now()->lessThanOrEqualTo($stored['expires_at']), 422, 'This import preview has expired. Please upload the CSV again.');
        $agent = $request->user()->agentProfile;
        abort_unless($agent, 404, 'Agent profile not found.');
        $provider = Provider::findOrFail($stored['provider_id']);
        $result = $imports->importPreview($agent, $provider, $stored['filename'], $stored['preview']);
        session()->forget('transaction_imports.'.$validated['preview_token']);

        return response()->json($result);
    }
}
