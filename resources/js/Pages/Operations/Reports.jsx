import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import { api, money, today } from '../../lib/api';
import { Button, ErrorNotice, LoadingCard, Notice } from '../../Components/PosPilotUI';

function dateDaysAgo(days) {
    const date = new Date(`${today()}T00:00:00`);
    date.setDate(date.getDate() - days);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function feeKnown(row) {
    return !(row.provisional_reasons || []).some((reason) => ['provider_fee_missing', 'provider_fee_components_incomplete'].includes(reason.code));
}

export default function Reports() {
    const [from, setFrom] = useState(dateDaysAgo(6));
    const [to, setTo] = useState(today());
    const [data, setData] = useState({ summary: null, providers: [], terminals: [], statusCounts: {}, unallocatedExpenses: '0.00', expenseAttributionComplete: true });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    async function load(start = from, end = to) {
        setLoading(true);
        setError(null);
        try {
            const [summary, providers, terminals] = await Promise.all([
                api(`/api/financial-summary?from=${encodeURIComponent(start)}&to=${encodeURIComponent(end)}`),
                api(`/api/provider-breakdown?from=${encodeURIComponent(start)}&to=${encodeURIComponent(end)}`),
                api(`/api/terminal-profitability?from=${encodeURIComponent(start)}&to=${encodeURIComponent(end)}`),
            ]);
            setData({ summary, providers: providers.data || [], terminals: terminals.data || [], statusCounts: summary.status_counts || {}, unallocatedExpenses: terminals.unallocated_expenses ?? '0.00', expenseAttributionComplete: terminals.expense_attribution_complete === true });
        } catch (requestError) {
            setError(requestError);
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => { load(); }, []);

    function submit(event) {
        event.preventDefault();
        if (from <= to) load(from, to);
    }

    function selectPeriod(days) {
        const start = dateDaysAgo(days - 1);
        const end = today();
        setFrom(start);
        setTo(end);
        load(start, end);
    }

    const largestProviderVolume = Math.max(0, ...data.providers.map((row) => Number(row.transaction_volume) || 0));
    const financialBars = data.summary ? [['Customer charges', data.summary.customer_charges_collected], ['Known provider fees', data.summary.provider_fees], ['Expenses', data.summary.expenses]] : [];
    const largestFinancialBar = Math.max(0, ...financialBars.map(([, value]) => Number(value) || 0));

    return <AppShell title="Reports">
        <div className="ops-page mx-auto max-w-6xl space-y-5">
            <header className="ops-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> BUSINESS</span><h1>Performance reports</h1><p>Compare activity recorded in your POSPilot account. Incomplete fee or expense information stays marked provisional.</p></div></header>
            <div className="flex flex-wrap gap-2" role="group" aria-label="Report period">
                {[['Today', 1], ['7 days', 7], ['30 days', 30]].map(([label, days]) => <button key={days} type="button" className={`min-h-11 rounded-xl border px-4 text-sm font-semibold ${from === dateDaysAgo(days - 1) && to === today() ? 'border-brand-accent bg-brand-accent text-white' : 'border-brand-line bg-white text-slate-700'}`} onClick={() => selectPeriod(days)}>{label}</button>)}
            </div>
            <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-2xl border border-brand-line bg-white p-4">
                <label className="grid gap-1 text-sm font-semibold">From<input aria-label="Report start date" type="date" value={from} max={to} onChange={(event) => setFrom(event.target.value)} className="min-h-11 rounded-xl border-brand-line" /></label>
                <label className="grid gap-1 text-sm font-semibold">To<input aria-label="Report end date" type="date" value={to} min={from} max={today()} onChange={(event) => setTo(event.target.value)} className="min-h-11 rounded-xl border-brand-line" /></label>
                <Button type="submit" disabled={loading || from > to}>Update report</Button>
            </form>
            {error && <ErrorNotice error={error} />}
            {loading ? <LoadingCard label="Preparing your report…" /> : <>
                {data.summary && <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Report totals">
                    {[
                        ['Total processed', money(data.summary.transaction_volume)],
                        ['Estimated earnings', money(data.summary.estimated_net_earnings)],
                        ['Known provider fees', money(data.summary.provider_fees)],
                        ['Customer charges', money(data.summary.customer_charges_collected)],
                        ['Expenses', money(data.summary.expenses)],
                        ['Successful transactions', String(data.summary.successful_transaction_count ?? 0)],
                        ['Average transaction', data.summary.successful_transaction_count ? money((Number(data.summary.transaction_volume) / data.summary.successful_transaction_count).toFixed(2)) : '—'],
                        ['Failed', String(data.statusCounts.failed ?? 0)],
                        ['Reversed', String(data.statusCounts.reversed ?? 0)],
                        ['Pending', String(data.statusCounts.pending ?? 0)],
                    ].map(([label, value]) => <article key={label} className="rounded-2xl border border-brand-line bg-white p-5"><p className="text-sm font-semibold text-slate-600">{label}</p><strong className="mt-2 block break-words text-2xl font-extrabold tabular-nums text-brand-ink">{value}</strong></article>)}
                    {!data.summary.is_final && <div className="sm:col-span-2 xl:col-span-4"><Notice tone="warning">PROVISIONAL — some fee or financial data is incomplete. Estimated earnings may change.</Notice></div>}
                </section>}
                {data.providers.length > 0 && <section className="rounded-2xl border border-brand-line bg-white p-5 sm:p-6"><h2 className="font-bold">Transaction volume by provider</h2><p className="mt-1 text-sm text-slate-600">Your recorded successful POS transactions in this period.</p><div className="mt-5 space-y-4">{data.providers.map((row) => <div key={row.provider_id}><div className="mb-1 flex justify-between gap-4 text-sm"><strong>{row.provider}</strong><span className="tabular-nums">{money(row.transaction_volume)}</span></div><div className="h-3 overflow-hidden rounded-full bg-emerald-50"><div className="h-full rounded-full bg-brand-accent" style={{ width: `${largestProviderVolume ? (Number(row.transaction_volume) / largestProviderVolume) * 100 : 0}%` }} /></div></div>)}</div></section>}
                {largestFinancialBar > 0 && <section className="rounded-2xl border border-brand-line bg-white p-5 sm:p-6"><h2 className="font-bold">Charges, fees and expenses</h2><p className="mt-1 text-sm text-slate-600">These recorded amounts explain the earnings estimate above. Provider fees may be incomplete.</p><div className="mt-5 space-y-4">{financialBars.map(([label, value], index) => <div key={label}><div className="mb-1 flex justify-between gap-4 text-sm"><strong>{label}</strong><span className="tabular-nums">{money(value)}</span></div><div className="h-3 overflow-hidden rounded-full bg-slate-100"><div className={`h-full rounded-full ${index === 0 ? 'bg-emerald-600' : index === 1 ? 'bg-amber-500' : 'bg-slate-500'}`} style={{ width: `${(Number(value) / largestFinancialBar) * 100}%` }} /></div></div>)}</div></section>}
                <section className="overflow-hidden rounded-2xl border border-brand-line bg-white">
                    <div className="border-b border-brand-line px-4 py-4 sm:px-6"><h2 className="font-bold">By terminal</h2><p className="mt-1 text-sm text-slate-600">Only successful POS transactions and expenses recorded for this business are included.</p></div>
                    {data.terminals.length ? <div className="overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="bg-slate-50 text-slate-600"><tr><th className="p-3 sm:p-4">Terminal</th><th className="p-3 sm:p-4">Provider</th><th className="p-3 sm:p-4">Count</th><th className="p-3 sm:p-4">Volume</th><th className="p-3 sm:p-4">Known provider fees</th><th className="p-3 sm:p-4">Expenses</th><th className="p-3 sm:p-4">Estimated result</th></tr></thead><tbody>{data.terminals.map((row) => <tr key={row.terminal_id ?? `${row.provider}-${row.terminal}`} className="border-t border-brand-line"><td className="p-3 font-semibold sm:p-4">{row.terminal}</td><td className="p-3 sm:p-4">{row.provider}</td><td className="p-3 sm:p-4">{row.transaction_count}</td><td className="p-3 sm:p-4">{money(row.transaction_volume)}</td><td className="p-3 sm:p-4">{row.provider_fee_known ? money(row.provider_fees) : 'Unknown'}</td><td className="p-3 sm:p-4">{money(row.allocated_expenses)}</td><td className="p-3 sm:p-4"><strong className="block">{money(row.estimated_net_after_expenses)}</strong><span className={row.is_final ? 'text-xs text-emerald-800' : 'text-xs font-semibold text-amber-800'}>{row.is_final ? 'Recorded estimate' : 'Provisional'}</span></td></tr>)}</tbody></table></div> : <p className="p-5 text-sm text-slate-600">No successful terminal activity is recorded for this period.</p>}
                    {!data.expenseAttributionComplete && <div className="border-t border-brand-line p-4"><Notice tone="warning">Some expenses are not assigned to a terminal. They remain unallocated and terminal results are provisional. Unallocated expenses: {money(data.unallocatedExpenses)}.</Notice></div>}
                </section>
                <section className="overflow-hidden rounded-2xl border border-brand-line bg-white">
                    <div className="border-b border-brand-line px-4 py-4 sm:px-6"><h2 className="font-bold">By provider</h2><p className="mt-1 text-sm text-slate-600">Provider comparisons use only this business’s successful POS records.</p></div>
                    {data.providers.length ? <div className="overflow-x-auto"><table className="w-full min-w-[680px] text-left text-sm"><thead className="bg-slate-50 text-slate-600"><tr><th className="p-3 sm:p-4">Provider</th><th className="p-3 sm:p-4">Count</th><th className="p-3 sm:p-4">Volume</th><th className="p-3 sm:p-4">Provider fees</th><th className="p-3 sm:p-4">Estimated earnings</th><th className="p-3 sm:p-4">Reconciliation</th></tr></thead><tbody>{data.providers.map((row) => <tr key={row.provider_id} className="border-t border-brand-line"><td className="p-3 font-semibold sm:p-4">{row.provider}</td><td className="p-3 sm:p-4">{row.transaction_count}</td><td className="p-3 sm:p-4">{money(row.transaction_volume)}</td><td className="p-3 sm:p-4">{feeKnown(row) ? money(row.provider_fees) : 'Unknown'}</td><td className="p-3 sm:p-4"><strong className="block">{money(row.estimated_earnings)}</strong><span className={row.is_final ? 'text-xs text-emerald-800' : 'text-xs font-semibold text-amber-800'}>{row.is_final ? 'Recorded estimate' : 'Provisional'}</span></td><td className="p-3 sm:p-4">{row.unreconciled_count} need review</td></tr>)}</tbody></table></div> : <p className="p-5 text-sm text-slate-600">No provider transaction activity is recorded for this period.</p>}
                </section>
            </>}
        </div>
    </AppShell>;
}
