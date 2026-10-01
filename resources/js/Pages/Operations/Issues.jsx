import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import { api, dateTime, money } from '../../lib/api';
import { ErrorNotice, LoadingCard, Notice } from '../../Components/PosPilotUI';

const tabs = [
    ['pending', 'Pending'], ['failed', 'Failed'], ['reversed', 'Reversed'], ['needs_attention', 'Needs attention'], ['resolved', 'Resolved'],
];

const maskReference = (value) => value ? `••••${String(value).slice(-4)}` : 'Not supplied';

export default function Issues() {
    const [records, setRecords] = useState({ pending: [], failed: [], reversed: [], needs_attention: [], resolved: [] });
    const [selected, setSelected] = useState('needs_attention');
    const [loading, setLoading] = useState(true);
    const [resolving, setResolving] = useState(null);
    const [error, setError] = useState(null);

    async function load() {
        setLoading(true);
        setError(null);
        try {
            const results = await Promise.all([
                api('/api/transactions?transaction_status=pending&per_page=100'),
                api('/api/transactions?transaction_status=failed&per_page=100'),
                api('/api/transactions?transaction_status=reversed&per_page=100'),
                api('/api/reconciliation/issues'),
                api('/api/team/activity'),
            ]);
            const [pending, failed, reversed, issueResult, activity] = results;
            const staffIssues = (activity.data || []).flatMap((shift) => (shift.issues || []).map((issue) => ({ id: issue.id, kind: 'staff_issue', provider: 'Staff report', terminal: shift.terminal, transaction_at: shift.started_at, message: issue.subject, status: issue.status })));
            setRecords({
                pending: pending.data || [],
                failed: failed.data || [],
                reversed: reversed.data || [],
                needs_attention: [...(issueResult.data || []), ...staffIssues.filter((issue) => issue.status === 'open')],
                resolved: staffIssues.filter((issue) => issue.status === 'resolved').map((issue) => ({ ...issue, id: null, issue_id: issue.id })),
            });
        } catch (requestError) {
            setError(requestError);
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => { load(); }, []);
    const activeRows = records[selected] || [];

    async function resolve(issueId) {
        setResolving(issueId);
        setError(null);
        try {
            await api(`/api/team/issues/${issueId}/resolve`, { method: 'POST', body: {} });
            await load();
        } catch (requestError) {
            setError(requestError);
        } finally {
            setResolving(null);
        }
    }

    return <AppShell title="Issues">
        <div className="ops-page mx-auto max-w-6xl space-y-5">
            <header className="ops-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> OPERATIONS</span><h1>Issues &amp; reversals</h1><p>Review pending activity, failed or reversed transactions, and reconciliation differences.</p></div></header>
            {error && <ErrorNotice error={error} />}
            <nav className="flex flex-wrap gap-2" aria-label="Issue status filters">
                {tabs.map(([key, label]) => <button key={key} type="button" aria-pressed={selected === key} onClick={() => setSelected(key)} className={`min-h-11 rounded-xl border px-4 text-sm font-semibold ${selected === key ? 'border-brand-accent bg-brand-accent text-white' : 'border-brand-line bg-white text-slate-700'}`}>
                    {label}<span className="ml-2 opacity-80">{records[key].length}</span>
                </button>)}
            </nav>
            {loading ? <LoadingCard label="Loading recent issues…" /> : activeRows.length === 0 ? <section className="rounded-2xl border border-brand-line bg-white p-6 sm:p-8"><h2 className="text-lg font-bold">No {tabs.find(([key]) => key === selected)?.[1].toLowerCase()} records</h2><p className="mt-2 text-sm leading-6 text-slate-600">POSPilot will show matching transactions and reconciliation records here when they are recorded.</p><Link href="/transactions" className="mt-4 inline-flex min-h-11 items-center font-bold text-brand-accent">View transactions →</Link></section> : <section className="overflow-hidden rounded-2xl border border-brand-line bg-white">
                <div className="border-b border-brand-line px-4 py-4 sm:px-6"><h2 className="font-bold">{tabs.find(([key]) => key === selected)?.[1]}</h2><p className="mt-1 text-sm text-slate-600">Records are shown from your POSPilot account. Review differences neutrally; an issue does not assign blame.</p></div>
                <div className="divide-y divide-brand-line">
                    {activeRows.map((row) => {
                        const isSettlementIssue = selected === 'needs_attention' && row.kind !== 'staff_issue';
                        const date = row.transaction_at ? dateTime(row.transaction_at) : row.settlement_date || (row.settlement_date === null ? 'Date not recorded' : 'Date not recorded');
                        const amount = row.amount ?? row.expected_amount ?? row.actual_amount;
                        const reference = row.external_reference || row.reference || row.settlement_reference;
                        return <article key={`${selected}-${row.id ?? row.issue_id ?? row.settlement_id ?? `${row.provider}-${date}-${row.type ?? 'record'}`}`} className="grid gap-3 p-4 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_auto] sm:items-center sm:px-6">
                            <div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><strong className="text-sm">{row.provider?.name || row.provider || 'Provider record'}</strong><span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{selected === 'needs_attention' ? String(row.message || row.type || 'Needs review').replaceAll('_', ' ') : tabs.find(([key]) => key === selected)?.[1]}</span></div><p className="mt-1 text-sm text-slate-600">{row.terminal?.name || row.terminal || 'Terminal not mapped'} · {date}</p><p className="mt-1 text-sm text-slate-700">{row.message || `${row.transaction_count ?? 1} transaction record${row.transaction_count === 1 ? '' : 's'}`}</p></div>
                            <div className="text-sm"><span className="text-slate-500">{isSettlementIssue ? 'Recorded amount' : 'Amount'}</span><strong className="mt-1 block">{amount === null || amount === undefined ? 'Not available' : money(amount)}</strong><span className="text-xs text-slate-500">Ref {maskReference(reference)}</span></div>
                            <div className="flex items-center gap-3 sm:justify-end">{row.issue_age_days !== undefined && <span className="text-xs text-slate-500">{row.issue_age_days}d open</span>}{row.id && selected !== 'needs_attention' && <Link href={`/transactions/${row.id}`} className="inline-flex min-h-11 items-center font-bold text-brand-accent">Details →</Link>}{isSettlementIssue && <Link href="/reconciliation" className="inline-flex min-h-11 items-center font-bold text-brand-accent">Review →</Link>}</div>
                            {row.kind === 'staff_issue' && row.status === 'open' && <button type="button" className="min-h-11 w-fit font-bold text-brand-accent disabled:opacity-50" disabled={resolving === row.id} onClick={() => resolve(row.id)}>{resolving === row.id ? 'Resolving…' : 'Resolve staff report'}</button>}
                        </article>;
                    })}
                </div>
                {selected === 'resolved' && <div className="border-t border-brand-line p-4 sm:p-6"><Notice tone="info">Resolved staff reports remain available for review. Reconciliation differences stay visible until their records are corrected.</Notice></div>}
            </section>}
        </div>
    </AppShell>;
}
