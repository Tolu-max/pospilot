<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Services\SettlementCsvImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SettlementImportController extends Controller
{
    public function preview(Request $request, SettlementCsvImportService $service)
    {
        $data = $request->validate(['provider_id' => ['required', 'integer', 'exists:providers,id'], 'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], 'mapping' => ['sometimes', 'array']]);
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);
        $provider = Provider::findOrFail($data['provider_id']);

        try {
            $preview = $service->preview($data['file'], $agent, $provider, $data['mapping'] ?? []);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (\Throwable) {
            Log::warning('Settlement CSV preview failed', ['provider_id' => $provider->id, 'agent_profile_id' => $agent->id]);

            return response()->json(['message' => 'The CSV could not be processed. Check its format and try again.'], 422);
        }
        $token = (string) Str::uuid();
        session()->put('settlement_imports.'.$token, ['expires_at' => now()->addMinutes(15)->toIso8601String(), 'provider_id' => $provider->id, 'filename' => $data['file']->getClientOriginalName(), 'preview' => $preview]);

        return response()->json([...$preview, 'preview_token' => $token, 'provider' => $provider->only(['id', 'name', 'slug'])]);
    }

    public function confirm(Request $request, SettlementCsvImportService $service)
    {
        $data = $request->validate(['preview_token' => ['required', 'string']]);
        $stored = session('settlement_imports.'.$data['preview_token']);
        abort_unless($stored && now()->lessThanOrEqualTo($stored['expires_at']), 422, 'This settlement preview has expired. Please upload the CSV again.');
        $agent = $request->user()->businessAgentProfile();
        abort_unless($agent, 404);

        $result = $service->importPreview($agent, Provider::findOrFail($stored['provider_id']), $stored['filename'], $stored['preview']);
        session()->forget('settlement_imports.'.$data['preview_token']);

        return response()->json($result);
    }
}
