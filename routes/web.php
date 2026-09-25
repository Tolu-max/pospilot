<?php

use App\Http\Controllers\AccountSessionController;
use App\Http\Controllers\AgentOperationsController;
use App\Http\Controllers\DailyClosingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\MoniepointConnectionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QAIntegrationController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\SettlementImportController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::get('/api/agent/profile', [AgentOperationsController::class, 'profile'])->name('api.agent.profile');
    Route::patch('/api/agent/profile', [AgentOperationsController::class, 'updateProfile'])->name('api.agent.profile.update');

    Route::middleware('verified')->group(function () {
        Route::get('/qa/integration', QAIntegrationController::class)->name('qa.integration');
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/import', [TransactionImportController::class, 'create'])->name('transactions.import.create');
        Route::post('/transactions/import/preview', [TransactionImportController::class, 'preview'])->middleware('throttle:20,1')->name('transactions.import.preview');
        Route::post('/transactions/import/confirm', [TransactionImportController::class, 'confirm'])->middleware('throttle:20,1')->name('transactions.import.confirm');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::patch('/transactions/{transaction}/customer-charge', [TransactionController::class, 'updateCharge'])->name('transactions.customer-charge.update');
        Route::patch('/api/transactions/{transaction}/customer-charge', [TransactionController::class, 'updateCharge'])->name('api.transactions.customer-charge.update');
        Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::get('/api/financial-summary', [FinancialReportController::class, 'summary'])->name('api.financial-summary');
        Route::get('/api/provider-breakdown', [FinancialReportController::class, 'providerBreakdown'])->name('api.provider-breakdown');
        Route::get('/api/reconciliation/overview', [FinancialReportController::class, 'reconciliationOverview'])->name('api.reconciliation.overview');
        Route::get('/api/reconciliation/issues', [FinancialReportController::class, 'reconciliationIssues'])->name('api.reconciliation.issues');
        Route::get('/api/settlements', [FinancialReportController::class, 'settlements'])->name('api.settlements.index');
        Route::get('/api/settlements/{settlement}', [FinancialReportController::class, 'settlement'])->name('api.settlements.show');
        Route::post('/settlements/import/preview', [SettlementImportController::class, 'preview'])->middleware('throttle:20,1')->name('settlements.import.preview');
        Route::post('/settlements/import/confirm', [SettlementImportController::class, 'confirm'])->middleware('throttle:20,1')->name('settlements.import.confirm');
        Route::get('/api/providers', [AgentOperationsController::class, 'providers'])->name('api.providers');
        Route::get('/api/providers/moniepoint/connection', [MoniepointConnectionController::class, 'show'])->name('api.providers.moniepoint.connection');
        Route::put('/api/providers/moniepoint/connection', [MoniepointConnectionController::class, 'store'])->middleware(['throttle:10,1', 'password.confirm'])->name('api.providers.moniepoint.connection.update');
        Route::post('/api/providers/moniepoint/connection/test', [MoniepointConnectionController::class, 'test'])->middleware(['throttle:10,1', 'password.confirm'])->name('api.providers.moniepoint.connection.test');
        Route::delete('/api/providers/moniepoint/connection', [MoniepointConnectionController::class, 'destroy'])->middleware(['throttle:10,1', 'password.confirm'])->name('api.providers.moniepoint.connection.destroy');
        Route::get('/api/provider-connections', [AgentOperationsController::class, 'connections'])->name('api.provider-connections');
        Route::get('/api/terminals', [AgentOperationsController::class, 'terminals'])->name('api.terminals.index');
        Route::post('/api/terminals', [AgentOperationsController::class, 'storeTerminal'])->name('api.terminals.store');
        Route::patch('/api/terminals/{terminal}', [AgentOperationsController::class, 'updateTerminal'])->name('api.terminals.update');
        Route::patch('/api/terminals/{terminal}/status', [AgentOperationsController::class, 'toggleTerminal'])->name('api.terminals.status');
        Route::delete('/api/terminals/{terminal}', [AgentOperationsController::class, 'deleteTerminal'])->name('api.terminals.destroy');
        Route::get('/api/charge-rules', [AgentOperationsController::class, 'chargeRules'])->name('api.charge-rules.index');
        Route::post('/api/charge-rules', [AgentOperationsController::class, 'storeChargeRule'])->name('api.charge-rules.store');
        Route::patch('/api/charge-rules/{chargeRule}', [AgentOperationsController::class, 'updateChargeRule'])->name('api.charge-rules.update');
        Route::delete('/api/charge-rules/{chargeRule}', [AgentOperationsController::class, 'deleteChargeRule'])->name('api.charge-rules.destroy');
        Route::get('/api/charge-rules/preview', [AgentOperationsController::class, 'previewCharge'])->middleware('throttle:60,1')->name('api.charge-rules.preview');
        Route::get('/api/expenses', [AgentOperationsController::class, 'expenses'])->name('api.expenses.index');
        Route::post('/api/expenses', [AgentOperationsController::class, 'storeExpense'])->name('api.expenses.store');
        Route::patch('/api/expenses/{expense}', [AgentOperationsController::class, 'updateExpense'])->name('api.expenses.update');
        Route::delete('/api/expenses/{expense}', [AgentOperationsController::class, 'deleteExpense'])->name('api.expenses.destroy');
        Route::get('/api/transactions', [AgentOperationsController::class, 'transactions'])->name('api.transactions.index');
        Route::get('/api/transactions/{transaction}', [AgentOperationsController::class, 'transaction'])->name('api.transactions.show');
        Route::get('/api/imports', [AgentOperationsController::class, 'importHistory'])->name('api.imports.index');
        Route::get('/api/imports/{importBatch}', [AgentOperationsController::class, 'importBatch'])->name('api.imports.show');
        Route::get('/api/daily-closings/preview', [DailyClosingController::class, 'preview'])->name('api.daily-closings.preview');
        Route::get('/api/daily-closings', [DailyClosingController::class, 'index'])->name('api.daily-closings.index');
        Route::post('/api/daily-closings', [DailyClosingController::class, 'store'])->name('api.daily-closings.store');
        Route::get('/api/daily-closings/{dailyClosing}', [DailyClosingController::class, 'show'])->name('api.daily-closings.show');
        Route::post('/api/daily-closings/{dailyClosing}/balances', [DailyClosingController::class, 'balance'])->name('api.daily-closings.balances');
        Route::post('/api/daily-closings/{dailyClosing}/finalize', [DailyClosingController::class, 'finalize'])->middleware('throttle:30,1')->name('api.daily-closings.finalize');
        Route::get('/api/daily-closings/{dailyClosing}/breakdown', [DailyClosingController::class, 'breakdown'])->name('api.daily-closings.breakdown');
    });

    Route::get('/api/security/sessions', [AccountSessionController::class, 'index'])->name('api.security.sessions.index');
    Route::delete('/api/security/sessions/others', [AccountSessionController::class, 'destroyOthers'])
        ->middleware(['password.confirm', 'throttle:10,1'])
        ->name('api.security.sessions.others.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('password.confirm')->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('password.confirm')->name('profile.destroy');
});

require __DIR__.'/auth.php';
