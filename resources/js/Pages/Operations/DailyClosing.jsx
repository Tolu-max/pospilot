import { useCallback, useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import { api, money, today } from '../../lib/api';
import { Button, Card, ErrorNotice, Field, LoadingCard, Notice, StatusPill } from '../../Components/PosPilotUI';
import { trackSafeEvent } from '../../lib/analytics';

const friendlyReason = (reason) => ({ provider_balance_below_expected: 'Provider balance is below the expected amount', provider_balance_above_expected: 'Provider balance is above the expected amount', cash_mismatch: 'Cash count does not match the expected amount', pending_transaction: 'There are transactions still pending', reversal_affecting_expected_position: 'A reversal affects the expected position', unexplained_variance: 'There is a difference that needs checking' }[reason.type] || reason.type?.replaceAll('_', ' ') || 'Review this difference');

export default function DailyClosing() {
    const [date, setDate] = useState(today());
    const [preview, setPreview] = useState(null);
    const [financialSummary, setFinancialSummary] = useState(null);
    const [history, setHistory] = useState([]);
    const [groups, setGroups] = useState([]);
    const [values, setValues] = useState({ opening_cash: '', entered_closing_cash: '', notes: '' });
    const [balances, setBalances] = useState({});
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [success, setSuccess] = useState('');
    const [checked, setChecked] = useState(false);

    const load = useCallback(async (closingDate = date) => {
        setLoading(true); setError(null);
        try {
            const [data, closings, firstPage, summary] = await Promise.all([
                api(`/api/daily-closings/preview?closing_date=${encodeURIComponent(closingDate)}`),
                api('/api/daily-closings?per_page=10'),
                api(`/api/transactions?from=${closingDate}&to=${closingDate}&transaction_status=successful&per_page=100`),
                api(`/api/financial-summary?from=${closingDate}&to=${closingDate}`),
            ]);
            setPreview(data);
            setFinancialSummary(summary);
            setHistory(closings.data || []);
            setValues({ opening_cash: data.closing?.opening_cash || '', entered_closing_cash: data.closing?.entered_closing_cash || '', notes: data.closing?.notes || '' });
            const txRows = [...(firstPage.data || [])];
            if ((firstPage.last_page || 1) > 1) {
                const pages = Array.from({ length: firstPage.last_page - 1 }, (_, index) => index + 2);
                const remainingPages = await Promise.all(pages.map((page) => api(`/api/transactions?from=${closingDate}&to=${closingDate}&transaction_status=successful&per_page=100&page=${page}`)));
                remainingPages.forEach((result) => txRows.push(...(result.data || [])));
            }
            const existingSnapshots = data.closing?.provider_balance_snapshots || [];
            const balanceKey = (providerId, terminalId) => `${providerId}:${terminalId ?? 'all'}`;
            const providerRows = new Map();
            txRows.forEach((row) => {
                if (!providerRows.has(row.provider_id)) providerRows.set(row.provider_id, { provider: row.provider, terminalRows: new Map(), hasUnmappedTerminal: false, hasProviderSnapshot: false });
                const providerRow = providerRows.get(row.provider_id);
                if (row.terminal_id === null || row.terminal_id === undefined) providerRow.hasUnmappedTerminal = true;
                else providerRow.terminalRows.set(row.terminal_id, row.terminal);
            });
            existingSnapshots.forEach((snapshot) => {
                if (!providerRows.has(snapshot.provider_id)) providerRows.set(snapshot.provider_id, { provider: snapshot.provider, terminalRows: new Map(), hasUnmappedTerminal: snapshot.terminal_id === null, hasProviderSnapshot: snapshot.terminal_id === null });
                if (snapshot.terminal_id === null) providerRows.get(snapshot.provider_id).hasProviderSnapshot = true;
                if (snapshot.terminal_id !== null) providerRows.get(snapshot.provider_id).terminalRows.set(snapshot.terminal_id, snapshot.terminal);
            });
            const groupsByBalanceKey = new Map();
            providerRows.forEach((providerRow, providerId) => {
                const providerName = providerRow.provider?.name || 'Provider';
                if (providerRow.hasUnmappedTerminal || providerRow.hasProviderSnapshot || providerRow.terminalRows.size === 0) {
                    const key = balanceKey(providerId, null);
                    const snapshot = existingSnapshots.find((item) => item.provider_id === providerId && item.terminal_id === null);
                    groupsByBalanceKey.set(key, { key, provider_id: providerId, terminal_id: null, name: providerName, actual_balance: snapshot?.actual_balance || '' });
                    return;
                }
                providerRow.terminalRows.forEach((terminal, terminalId) => {
                    const key = balanceKey(providerId, terminalId);
                    const snapshot = existingSnapshots.find((item) => item.provider_id === providerId && item.terminal_id === terminalId);
                    groupsByBalanceKey.set(key, { key, provider_id: providerId, terminal_id: terminalId, name: `${providerName} · ${terminal?.name || 'Terminal'}`, actual_balance: snapshot?.actual_balance || '' });
                });
            });
            setBalances(Object.fromEntries([...groupsByBalanceKey.values()].map((group) => [group.key, group.actual_balance])));
            setGroups([...groupsByBalanceKey.values()]);
        } catch (requestError) { setError(requestError); }
        finally { setLoading(false); }
    }, [date]);

    useEffect(() => { load(); }, [load]);

    async function saveProgress(event, shouldCheck = false) {
        event.preventDefault(); setBusy(true); setError(null); setSuccess('');
        const closingForm = event.currentTarget.tagName === 'FORM' ? event.currentTarget : event.currentTarget.form;
        const submittedValues = closingForm ? Object.fromEntries(new FormData(closingForm).entries()) : {};
        const closingDate = document.querySelector('#closing_date')?.value || date;
        const cashValues = {
            opening_cash: submittedValues.opening_cash ?? values.opening_cash,
            entered_closing_cash: submittedValues.entered_closing_cash ?? values.entered_closing_cash,
            notes: submittedValues.notes ?? values.notes,
        };
        try {
            let current = preview?.closing;
            if (!current) {
                current = await api('/api/daily-closings', { method: 'POST', body: { closing_date: closingDate, ...cashValues } });
            } else if (current.status !== 'finalized') {
                current = await api('/api/daily-closings', { method: 'POST', body: { closing_date: closingDate, ...cashValues } });
            }
            if (current.status === 'finalized') throw new Error('This day has already been finalized and cannot be changed.');
            for (const group of groups) {
                const formBalance = submittedValues[`balance_${group.key}`];
                if (formBalance === undefined && balances[group.key] === undefined && group.actual_balance === '') continue;
                const actual = formBalance ?? balances[group.key] ?? group.actual_balance;
                if (actual === '') continue;
                await api(`/api/daily-closings/${current.id}/balances`, { method: 'POST', body: { provider_id: group.provider_id, terminal_id: group.terminal_id, actual_balance: actual } });
            }
            setSuccess(shouldCheck ? 'Check complete. Review the expected and actual amounts below before finalizing.' : 'Your daily closing draft has been saved.');
            setDate(closingDate);
            await load(closingDate);
            setChecked(shouldCheck);
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }

    async function finalizeDay() {
        if (!preview?.closing?.id || preview.requires_attention) return;
        setBusy(true); setError(null); setSuccess('');
        try {
            const result = await api(`/api/daily-closings/${preview.closing.id}/finalize`, { method: 'POST', body: {} });
            trackSafeEvent('daily_closing_completed');
            setPreview((current) => current ? { ...current, closing: result } : current);
            setSuccess('Your business day has been finalized.');
            setChecked(false);
            await load(date);
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }

    const finalized = preview?.closing?.status === 'finalized';
    return <AppShell title="Daily closing"><div className="ops-page"><header className="ops-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> END-OF-DAY CHECK</span><h1>Close today’s business</h1><p>Compare what POSPilot expected with the real balances you counted. You’ll review the result before finalizing.</p></div></header>
        {error && <div className="mb-5"><ErrorNotice error={error} /></div>}{success && <div className="mb-5"><Notice tone="success">{success}</Notice></div>}
        <div className="mb-5 flex flex-wrap items-end gap-3"><Field label="Business date" type="date" name="closing_date" value={date} onChange={(event) => { setDate(event.target.value); setSuccess(''); }} className="max-w-xs" /><Button type="button" variant="secondary" onClick={() => { const selectedDate = document.querySelector('#closing_date')?.value || date; setDate(selectedDate); load(selectedDate); }}>Refresh</Button></div>
        {loading ? <LoadingCard label="Checking the day’s totals…" /> : preview && <>
            <div className="ops-metrics-grid"><Summary label="Today's processed amount" value={money(preview.transaction_volume)} /><Summary label={financialSummary?.is_final ? 'Estimated earnings' : 'Provisional earnings'} value={money(financialSummary?.estimated_net_earnings)} /><Summary label="Successful transactions" value={String(preview.successful_transaction_count ?? financialSummary?.successful_transaction_count ?? '—')} /></div>
            {financialSummary?.is_final === false && <div className="mt-4"><Notice tone="warning">Earnings are provisional because some transaction fee data is incomplete. This amount is not confirmed final earnings.</Notice></div>}
            <details className="ops-panel mt-4"><summary className="cursor-pointer font-bold">See charges, fees and expenses</summary><dl className="mt-3 grid gap-3 sm:grid-cols-3"><Summary label="Customer charges" value={money(preview.customer_charges)} /><Summary label="Provider fees" value={money(preview.provider_fees)} /><Summary label="Expenses" value={money(preview.expenses)} /></dl></details>
            <div className="mt-5 grid gap-5 xl:grid-cols-3"><Card className="xl:col-span-2"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-lg font-extrabold">Expected and actual position</h2><p className="mt-1 text-sm text-slate-600">These totals are recalculated by POSPilot when you save.</p></div><StatusPill tone={preview.variance_status === 'balanced' ? 'good' : preview.requires_attention ? 'warn' : 'info'}>{preview.variance_status === 'balanced' ? 'Balanced' : preview.variance_status?.replaceAll('_', ' ') || 'Needs review'}</StatusPill></div>
                <dl className="mt-5 grid gap-4 sm:grid-cols-3"><Summary label="Expected cash" value={money(preview.expected_cash)} /><Summary label="Cash counted" value={money(preview.closing?.entered_closing_cash)} /><Summary label="Expected provider position" value={money(preview.expected_electronic_position)} /><Summary label="Provider balances entered" value={money(preview.actual_electronic_position)} /><Summary label="Difference" value={money(preview.total_variance)} /></dl>
                {preview.reasons?.length > 0 && <div className="mt-5 rounded-xl bg-amber-50 p-4"><h3 className="font-bold text-amber-950">Items to review</h3><ul className="mt-2 space-y-1 text-sm text-amber-900">{preview.reasons.map((reason, index) => <li key={`${reason.type}-${index}`}>• {friendlyReason(reason)}{reason.difference ? ` (${money(reason.difference)})` : ''}{reason.count ? ` · ${reason.count}` : ''}</li>)}</ul></div>}
                {(preview.pending_transaction_count > 0 || preview.reversed_transaction_count > 0) && <p className="mt-4 text-sm text-slate-600">{preview.pending_transaction_count || 0} pending and {preview.reversed_transaction_count || 0} reversed transactions are shown separately from successful transaction totals.</p>}
            </Card>
            <Card><h2 className="text-lg font-extrabold">What the totals mean</h2><p className="mt-2 text-sm leading-6 text-slate-600">Transaction principal is not earnings. POSPilot uses the transaction types and verified provider deductions on record to estimate the position.</p><p className="mt-4 text-sm font-semibold text-slate-700">A finalized closing is locked to protect the record.</p></Card></div>
            <Card className="mt-5"><h2 className="text-lg font-extrabold">Enter the balances you can see</h2><p className="mt-1 text-sm text-slate-600">POSPilot will save a draft, compare your balances, and show the result before you finalize.</p><form onSubmit={(event) => saveProgress(event, false)} className="mt-5 space-y-5"><div className="grid gap-4 sm:grid-cols-2"><Field label="Cash at start of day (needed for cash comparison)" name="opening_cash" inputMode="decimal" value={values.opening_cash} disabled={finalized} onChange={(event) => { setValues({ ...values, opening_cash: event.target.value }); setChecked(false); }} /><Field label="Cash in hand at close" name="entered_closing_cash" inputMode="decimal" value={values.entered_closing_cash} disabled={finalized} onChange={(event) => { setValues({ ...values, entered_closing_cash: event.target.value }); setChecked(false); }} /></div>
                {groups.length > 0 ? <div><h3 className="font-bold">Provider balances</h3><p className="mt-1 text-sm text-slate-600">Enter the balance shown in each provider’s official app. When transactions are mapped to terminals, balances are checked per terminal. Providers with successful activity need a balance before the day can be finalized.</p><div className="mt-3 grid gap-3 sm:grid-cols-2">{groups.map((group) => <Field key={group.key} label={`${group.name} balance`} name={`balance_${group.key}`} inputMode="decimal" placeholder="0.00" value={balances[group.key] ?? group.actual_balance} disabled={finalized} onChange={(event) => { setBalances({ ...balances, [group.key]: event.target.value }); setChecked(false); }} />)}</div></div> : <Notice tone="info">There are no successful transactions for this date, so there are no provider balances to enter.</Notice>}
                <label className="block text-sm font-semibold">Note (optional)<textarea name="notes" rows="3" value={values.notes} disabled={finalized} onChange={(event) => { setValues({ ...values, notes: event.target.value }); setChecked(false); }} className="mt-2 w-full rounded-xl border-brand-line px-4 py-3 font-normal focus:border-brand-accent focus:ring-brand-accent" /></label>
                {preview.requires_attention && <p className="text-sm font-medium text-amber-900">Some required amounts are still missing. You can save the information, then come back to finalize when ready.</p>}
                <div className="flex flex-wrap gap-3"><Button type="submit" variant="secondary" disabled={busy || finalized}>{busy ? 'Saving…' : 'Save draft'}</Button>{!finalized && <Button type="button" disabled={busy} onClick={(event) => saveProgress(event, true)}>{busy ? 'Checking…' : 'Check my closing'}</Button>}{checked && !finalized && <Button type="button" variant="secondary" disabled={busy || preview.requires_attention} onClick={finalizeDay}>{busy ? 'Finalizing…' : 'Finalize day'}</Button>}{finalized && <StatusPill tone="good">Finalized</StatusPill>}</div>
            </form></Card>
            <Card className="mt-5"><h2 className="text-lg font-extrabold">Recent closings</h2>{history.length ? <div className="mt-3 divide-y divide-slate-100">{history.map((item) => <button key={item.id} type="button" onClick={() => { setDate(String(item.closing_date).slice(0, 10)); }} className="flex min-h-14 w-full items-center justify-between gap-3 py-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent"><span><span className="block font-bold">{item.closing_date}</span><span className="text-sm text-slate-600">Variance {money(item.total_variance)}</span></span><StatusPill tone={item.variance_status === 'balanced' ? 'good' : 'warn'}>{item.status || item.variance_status}</StatusPill></button>)}</div> : <p className="mt-3 text-sm text-slate-600">No daily closings recorded yet.</p>}</Card>
        </>}
    </div></AppShell>;
}

function Summary({ label, value }) { return <div className="ops-metric"><span>{label}</span><strong>{value}</strong></div>; }
