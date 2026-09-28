<?php

use App\Http\Controllers\AccountSessionController;
use App\Http\Controllers\AgentOperationsController;
use App\Http\Controllers\BusinessInsightController;
use App\Http\Controllers\DailyClosingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\GmailOAuthController;
use App\Http\Controllers\GmailStatementController;
use App\Http\Controllers\MoniepointConnectionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QAIntegrationController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\SettlementImportController;
use App\Http\Controllers\StaffShiftController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamInvitationController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportController;
use App\Http\Middleware\EnsureOnboardingComplete;
use App\Http\Middleware\InternalStatementToolsOnly;
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

Route::get('/privacy', fn () => Inertia::render('Privacy'))->name('privacy');
Route::get('/team/invitations/{token}', [TeamInvitationController::class, 'show'])->name('team.invitations.show');
Route::post('/team/invitations/{token}/accept', [TeamInvitationController::class, 'accept'])->middleware('throttle:10,1')->name('team.invitations.accept');

Route::middleware(['auth', 'auth.session', 'verified'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->middleware('business-role:owner,manager,attendant')->name('dashboard');

    Route::middleware('business-role:owner')->group(function (): void {
        Route::get('/team', [TeamController::class, 'index'])->middleware(EnsureOnboardingComplete::class)->name('team.index');
        Route::get('/api/agent/profile', [AgentOperationsController::class, 'profile'])->name('api.agent.profile');
        Route::patch('/api/agent/profile', [AgentOperationsController::class, 'updateProfile'])->name('api.agent.profile.update');

        Route::middleware(EnsureOnboardingComplete::class)->group(function (): void {
            Route::get('/integrations/gmail/connect', [GmailOAuthController::class, 'connect'])->name('gmail.connect');
            Route::get('/integrations/gmail/callback', [GmailOAuthController::class, 'callback'])->name('gmail.callback');
            Route::delete('/integrations/gmail', [GmailOAuthController::class, 'disconnect'])->name('gmail.disconnect');
            Route::get('/api/gmail/connection', [GmailStatementController::class, 'status'])->name('gmail.connection');
            Route::put('/api/gmail/connection/rules', [GmailStatementController::class, 'saveRules'])->name('gmail.rules.update');
            Route::post('/integrations/gmail/sync', [GmailStatementController::class, 'sync'])->middleware('throttle:3,1')->name('gmail.sync');
            Route::post('/integrations/gmail/import-older', [GmailStatementController::class, 'importOlder'])->middleware('throttle:1,1')->name('gmail.import-older');
            Route::get('/api/gmail/statements', [GmailStatementController::class, 'index'])->name('gmail.statements.index');
            Route::post('/api/gmail/statements/{message}/mapping', [GmailStatementController::class, 'saveMapping'])->name('gmail.statements.mapping');

            Route::get('/api/providers/moniepoint/connection', [MoniepointConnectionController::class, 'show'])->name('api.providers.moniepoint.connection');
            Route::put('/api/providers/moniepoint/connection', [MoniepointConnectionController::class, 'store'])->middleware(['throttle:10,1', 'password.confirm'])->name('api.providers.moniepoint.connection.update');
            Route::post('/api/providers/moniepoint/connection/test', [MoniepointConnectionController::class, 'test'])->middleware(['throttle:10,1', 'password.confirm'])->name('api.providers.moniepoint.connection.test');
            Route::delete('/api/providers/moniepoint/connection', [MoniepointConnectionController::class, 'destroy'])->middleware(['throttle:10,1', 'password.confirm'])->name('api.providers.moniepoint.connection.destroy');
            Route::get('/api/provider-connections', [AgentOperationsController::class, 'connections'])->name('api.provider-connections');
            Route::post('/api/terminals', [AgentOperationsController::class, 'storeTerminal'])->name('api.terminals.store');
            Route::patch('/api/terminals/{terminal}', [AgentOperationsController::class, 'updateTerminal'])->name('api.terminals.update');
            Route::patch('/api/terminals/{terminal}/status', [AgentOperationsController::class, 'toggleTerminal'])->name('api.terminals.status');
            Route::delete('/api/terminals/{terminal}', [AgentOperationsController::class, 'deleteTerminal'])->name('api.terminals.destroy');
            Route::get('/api/charge-rules', [AgentOperationsController::class, 'chargeRules'])->name('api.charge-rules.index');
            Route::post('/api/charge-rules', [AgentOperationsController::class, 'storeChargeRule'])->name('api.charge-rules.store');
            Route::patch('/api/charge-rules/{chargeRule}', [AgentOperationsController::class, 'updateChargeRule'])->name('api.charge-rules.update');
            Route::delete('/api/charge-rules/{chargeRule}', [AgentOperationsController::class, 'deleteChargeRule'])->name('api.charge-rules.destroy');
            Route::get('/api/charge-rules/preview', [AgentOperationsController::class, 'previewCharge'])->middleware('throttle:60,1')->name('api.charge-rules.preview');
            Route::get('/api/team/members', [TeamController::class, 'members'])->name('api.team.members');
            Route::post('/api/team/invitations', [TeamController::class, 'invite'])->middleware('throttle:10,1')->name('api.team.invitations.store');
            Route::delete('/api/team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])->name('api.team.invitations.destroy');
            Route::patch('/api/team/members/{member}', [TeamController::class, 'updateMember'])->name('api.team.members.update');
            Route::put('/api/team/members/{member}/terminals', [TeamController::class, 'assignTerminals'])->name('api.team.members.terminals');
        });
    });

    Route::middleware(['business-role:owner,manager', EnsureOnboardingComplete::class])->group(function (): void {
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
        Route::get('/api/providers', [AgentOperationsController::class, 'providers'])->name('api.providers');
        Route::get('/api/terminals', [AgentOperationsController::class, 'terminals'])->name('api.terminals.index');
        Route::get('/api/transactions', [AgentOperationsController::class, 'transactions'])->name('api.transactions.index');
        Route::get('/api/transactions/{transaction}', [AgentOperationsController::class, 'transaction'])->name('api.transactions.show');
        Route::patch('/transactions/{transaction}/customer-charge', [TransactionController::class, 'updateCharge'])->name('transactions.customer-charge.update');
        Route::patch('/api/transactions/{transaction}/customer-charge', [TransactionController::class, 'updateCharge'])->name('api.transactions.customer-charge.update');
        Route::get('/reconciliation', [ReconciliationController::class, 'index'])->name('reconciliation.index');
        Route::post('/api/business-insight', BusinessInsightController::class)->middleware(['business-role:owner', 'throttle:business-insight'])->name('api.business-insight');
        Route::get('/api/reconciliation/overview', [FinancialReportController::class, 'reconciliationOverview'])->name('api.reconciliation.overview');
        Route::get('/api/reconciliation/issues', [FinancialReportController::class, 'reconciliationIssues'])->name('api.reconciliation.issues');
        Route::get('/api/settlements', [FinancialReportController::class, 'settlements'])->name('api.settlements.index');
        Route::get('/api/settlements/{settlement}', [FinancialReportController::class, 'settlement'])->name('api.settlements.show');
        Route::get('/api/expenses', [AgentOperationsController::class, 'expenses'])->name('api.expenses.index');
        Route::post('/api/expenses', [AgentOperationsController::class, 'storeExpense'])->name('api.expenses.store');
        Route::patch('/api/expenses/{expense}', [AgentOperationsController::class, 'updateExpense'])->name('api.expenses.update');
        Route::delete('/api/expenses/{expense}', [AgentOperationsController::class, 'deleteExpense'])->name('api.expenses.destroy');
        Route::get('/api/daily-closings/preview', [DailyClosingController::class, 'preview'])->name('api.daily-closings.preview');
        Route::get('/api/daily-closings', [DailyClosingController::class, 'index'])->name('api.daily-closings.index');
        Route::post('/api/daily-closings', [DailyClosingController::class, 'store'])->name('api.daily-closings.store');
        Route::get('/api/daily-closings/{dailyClosing}', [DailyClosingController::class, 'show'])->name('api.daily-closings.show');
        Route::post('/api/daily-closings/{dailyClosing}/balances', [DailyClosingController::class, 'balance'])->name('api.daily-closings.balances');
        Route::post('/api/daily-closings/{dailyClosing}/finalize', [DailyClosingController::class, 'finalize'])->middleware('throttle:30,1')->name('api.daily-closings.finalize');
        Route::get('/api/daily-closings/{dailyClosing}/breakdown', [DailyClosingController::class, 'breakdown'])->name('api.daily-closings.breakdown');
        Route::get('/api/team/activity', [TeamController::class, 'activity'])->name('api.team.activity');
        Route::post('/api/team/issues/{issue}/resolve', [TeamController::class, 'resolveIssue'])->name('api.team.issues.resolve');
    });

    Route::middleware(['business-role:owner', EnsureOnboardingComplete::class])->group(function (): void {
        Route::get('/api/financial-summary', [FinancialReportController::class, 'summary'])->name('api.financial-summary');
        Route::get('/api/provider-breakdown', [FinancialReportController::class, 'providerBreakdown'])->name('api.provider-breakdown');
        Route::get('/api/imports', [AgentOperationsController::class, 'importHistory'])->name('api.imports.index');
        Route::get('/api/imports/{importBatch}', [AgentOperationsController::class, 'importBatch'])->name('api.imports.show');
        Route::get('/api/security/sessions', [AccountSessionController::class, 'index'])->name('api.security.sessions.index');
        Route::delete('/api/security/sessions/others', [AccountSessionController::class, 'destroyOthers'])->middleware(['password.confirm', 'throttle:10,1'])->name('api.security.sessions.others.destroy');
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->middleware('password.confirm')->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('password.confirm')->name('profile.destroy');
    });

    Route::middleware(['business-role:attendant', EnsureOnboardingComplete::class])->prefix('api/staff')->name('api.staff.')->group(function (): void {
        Route::get('/dashboard', [StaffShiftController::class, 'dashboard'])->name('dashboard');
        Route::get('/terminals', [StaffShiftController::class, 'terminals'])->name('terminals.index');
        Route::post('/shifts', [StaffShiftController::class, 'start'])->middleware('throttle:10,1')->name('shifts.store');
        Route::post('/shifts/{shift}/close', [StaffShiftController::class, 'close'])->name('shifts.close');
        Route::get('/shifts/{shift}/transactions', [StaffShiftController::class, 'transactions'])->name('shifts.transactions');
        Route::post('/shifts/{shift}/cash-activity', [StaffShiftController::class, 'cashActivity'])->name('shifts.cash-activity.store');
        Route::post('/shifts/{shift}/expenses', [StaffShiftController::class, 'expense'])->name('shifts.expenses.store');
        Route::post('/shifts/{shift}/issues', [StaffShiftController::class, 'reportIssue'])->middleware('throttle:10,1')->name('shifts.issues.store');
    });
});

Route::middleware(['auth', 'auth.session', 'verified', 'business-role:owner', InternalStatementToolsOnly::class, EnsureOnboardingComplete::class])->group(function (): void {
    Route::get('/transactions/import', [TransactionImportController::class, 'create'])->name('transactions.import.create');
    Route::post('/transactions/import/preview', [TransactionImportController::class, 'preview'])->middleware('throttle:20,1')->name('transactions.import.preview');
    Route::post('/transactions/import/confirm', [TransactionImportController::class, 'confirm'])->middleware('throttle:20,1')->name('transactions.import.confirm');
    Route::post('/settlements/import/preview', [SettlementImportController::class, 'preview'])->middleware('throttle:20,1')->name('settlements.import.preview');
    Route::post('/settlements/import/confirm', [SettlementImportController::class, 'confirm'])->middleware('throttle:20,1')->name('settlements.import.confirm');
    Route::get('/qa/integration', QAIntegrationController::class)->name('qa.integration');
});

require __DIR__.'/auth.php';
