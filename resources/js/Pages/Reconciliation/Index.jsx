import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import { api, money } from '../../lib/api';
import { Card, EmptyState, ErrorNotice, LoadingCard, StatusPill } from '../../Components/PosPilotUI';

const outcomeTone = (outcome) => outcome === 'reconciled' ? 'good' : outcome === 'disputed' ? 'bad' : outcome === 'pending' ? 'neutral' : 'warn';
const outcomeText = (outcome) => ({ reconciled: 'Balanced', partially_reconciled: 'Partly matched', pending: 'Waiting for settlement', disputed: 'Disputed', unreconciled: 'Needs review' }[outcome] || 'Needs review');

export default function Index({ settlements = [] }) {
    const [overview, setOverview] = useState(null);
    const [issues, setIssues] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    useEffect(() => {
        let mounted = true;
        Promise.all([api('/api/reconciliation/overview'), api('/api/reconciliation/issues')]).then(([summary, list]) => {
            if (!mounted) return;
            setOverview(summary); setIssues(list.data || []);
        }).catch(setError).finally(() => mounted && setLoading(false));
        return () => { mounted = false; };
    }, []);

    const categorized = [
        { key: 'reconciled', title: 'Balanced' },
        { key: 'pending', title: 'Waiting for settlement' },
        { key: 'unreconciled', title: 'Needs review' },
        { key: 'disputed', title: 'Disputed' },
    ];
    return <AppShell title="Reconciliation"><div className="recon-page"><div className="recon-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> SETTLEMENT CHECK</span><h1>Does it add up?</h1><p>Compare what your provider was expected to settle with what actually arrived.</p></div></div>
        {error && <div className="mb-5"><ErrorNotice error={error} /></div>}{loading && <LoadingCard label="Checking your settlement records…" />}
        {overview && <div className="recon-stat-grid"><Metric label="Settlements checked" value={overview.settlement_count || 0} /><Metric label="Issues to review" value={overview.issue_count || 0} alert={(overview.issue_count || 0) > 0} /><Metric label="Net difference" value={money(overview.unreconciled_amount)} alert={overview.unreconciled_amount !== '0.00'} /></div>}
        {issues.length > 0 && <Card className="mb-5 border-amber-200 bg-amber-50"><h2 className="text-lg font-extrabold text-amber-950">Why something needs attention</h2><div className="mt-3 divide-y divide-amber-200">{issues.slice(0, 10).map((issue, index) => <div key={`${issue.type}-${issue.settlement_id || index}`} className="py-3"><p className="font-bold text-amber-950">{issue.provider || 'Provider'} · {issue.message || String(issue.type).replaceAll('_', ' ')}</p><p className="mt-1 text-sm text-amber-900">{issue.settlement_date || 'Date not recorded'}{issue.expected_amount ? ` · Expected ${money(issue.expected_amount)}` : ''}</p></div>)}</div></Card>}
        <div className="space-y-5">{categorized.map((category) => {
            const list = settlements.filter((settlement) => (settlement.outcome || settlement.status) === category.key || (category.key === 'unreconciled' && ['partially_reconciled', 'unreconciled'].includes(settlement.outcome)));
            return <section key={category.key} className="recon-panel"><div className="recon-section-head"><div><h2>{category.title}</h2><p>{list.length} settlement{list.length === 1 ? '' : 's'}</p></div><StatusPill tone={outcomeTone(category.key)}>{list.length}</StatusPill></div>
                {list.length ? <div className="mt-3 divide-y divide-slate-100">{list.map((settlement) => <Settlement key={settlement.id} settlement={settlement} />)}</div> : <p className="mt-4 text-sm text-slate-500">Nothing in this category right now.</p>}
            </section>;
        })}</div>
        {!settlements.length && !loading && <div className="mt-5"><EmptyState title="No settlement records yet" description="Settlement records will appear here when supported provider activity is available. Your transaction records remain available under Transactions." /></div>}
    </div></AppShell>;
}

function Settlement({ settlement }) {
    const outcome = settlement.outcome || settlement.status;
    const discrepancy = settlement.discrepancy;
    return <details className="group py-4"><summary className="flex cursor-pointer list-none flex-col justify-between gap-3 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent sm:flex-row sm:items-center"><div><p className="font-extrabold">{settlement.provider}</p><p className="mt-1 text-sm text-slate-600">{settlement.reference || 'Reference not supplied'} · {settlement.settlement_date}</p></div><div className="flex items-center gap-3"><span className="font-black">{money(discrepancy)}</span><StatusPill tone={outcomeTone(outcome)}>{outcomeText(outcome)}</StatusPill></div></summary><dl className="mt-4 grid gap-3 rounded-xl bg-brand-canvas p-4 text-sm sm:grid-cols-3"><Amount label="Expected" value={money(settlement.expected_amount)} /><Amount label="Actual received" value={settlement.actual_amount === null ? 'Not received yet' : money(settlement.actual_amount)} /><Amount label="Difference" value={money(discrepancy)} /></dl>{settlement.issues?.length > 0 && <ul className="mt-3 list-inside list-disc text-sm text-slate-600">{settlement.issues.map((issue, index) => <li key={index}>{issue.message}</li>)}</ul>}</details>;
}

function Amount({ label, value }) { return <div><dt className="text-slate-600">{label}</dt><dd className="mt-1 font-extrabold">{value}</dd></div>; }
function Metric({ label, value, alert = false }) { return <article className={`recon-stat ${alert ? 'recon-stat-amber' : 'recon-stat-green'}`}><span>{label}</span><strong>{value}</strong><small>Based on current reconciliation records</small></article>; }
