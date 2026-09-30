import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import AppShell from '../Layouts/AppShell';
import { api, dateTime, money, today } from '../lib/api';
import DailyClosing from './Operations/DailyClosing';
import MoreTools from './Operations/MoreTools';
import Issues from './Operations/Issues';
import Reports from './Operations/Reports';
import ProviderAutomation from './Providers/ProviderAutomation';
import Moniepoint from './Providers/Moniepoint';
import BusinessSetup from './Operations/BusinessSetup';
import ProviderLogo from '../Components/ProviderLogo';
import StaffDashboard from './Staff/Dashboard';

const cleanLabel = (value) => String(value || 'Not recorded').replaceAll('_', ' ');

function dateDaysAgo(days) {
    const date = new Date(`${today()}T00:00:00`);
    date.setDate(date.getDate() - days);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function DashboardHome({ businessName, businessInsightEnabled, actionItems = [] }) {
    const { auth } = usePage().props;
    const [summary, setSummary] = useState(null);
    const [transactions, setTransactions] = useState([]);
    const [issues, setIssues] = useState([]);
    const [issuesAvailable, setIssuesAvailable] = useState(false);
    const [floatPreview, setFloatPreview] = useState(null);
    const [terminalPerformance, setTerminalPerformance] = useState([]);
    const [terminalPerformanceAvailable, setTerminalPerformanceAvailable] = useState(false);
    const [businessInsight, setBusinessInsight] = useState(null);
    const [insightLoading, setInsightLoading] = useState(false);
    const [insightError, setInsightError] = useState(null);
    const [error, setError] = useState(null);
    const [loading, setLoading] = useState(true);

    async function load() {
        setLoading(true);
        setError(null);
        const day = today();
        const period = `from=${encodeURIComponent(day)}&to=${encodeURIComponent(day)}`;
        try {
            const [financial, activity] = await Promise.all([
                api(`/api/financial-summary?${period}`),
                api('/api/transactions?per_page=5'),
            ]);
            setSummary(financial);
            setTransactions((activity.data || []).filter((transaction) => transaction.metadata?.activity_scope !== 'personal_wallet'));
            const secondaryResults = await Promise.allSettled([
                api(`/api/reconciliation/issues?from=${dateDaysAgo(6)}&to=${day}`),
                api(`/api/daily-closings/preview?closing_date=${day}`),
                api(`/api/terminal-profitability?from=${dateDaysAgo(6)}&to=${day}`),
            ]);
            setIssues(secondaryResults[0].status === 'fulfilled' ? secondaryResults[0].value.data || [] : []);
            setIssuesAvailable(secondaryResults[0].status === 'fulfilled');
            setFloatPreview(secondaryResults[1].status === 'fulfilled' ? secondaryResults[1].value : null);
            setTerminalPerformance(secondaryResults[2].status === 'fulfilled' ? secondaryResults[2].value.data || [] : []);
            setTerminalPerformanceAvailable(secondaryResults[2].status === 'fulfilled');
        } catch (requestError) {
            setError(requestError);
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => { load(); }, []);

    async function explainMyDay() {
        setInsightLoading(true);
        setInsightError(null);
        try {
            const result = await api('/api/business-insight', { method: 'POST' });
            setBusinessInsight(result.explanation);
        } catch {
            setInsightError('Business Insight is temporarily unavailable. Your dashboard figures are still up to date.');
        } finally {
            setInsightLoading(false);
        }
    }

    const provisional = summary?.is_final !== true;
    const attentionCount = issues.length;
    const expectedCash = floatPreview?.expected_cash;
    const actualProviderBalance = floatPreview?.actual_electronic_position;
    const statusCounts = summary?.status_counts || {};
    const statusTotal = ['successful', 'pending', 'failed', 'reversed'].reduce((total, status) => total + (Number(statusCounts[status]) || 0), 0);
    const greetingHour = new Date().getHours();
    const greeting = greetingHour < 12 ? 'Good morning' : greetingHour < 17 ? 'Good afternoon' : 'Good evening';
    const greetingText = auth?.user?.name?.split(' ')[0] || 'there';

    return <AppShell title="Home">
        <div className="dashboard">
            <section className="welcome-row">
                <div><span className="eyebrow"><span className="eyebrow-dot" /> TODAY · {new Date().toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }).toUpperCase()}</span><h1>{greeting}, {greetingText}</h1><p className="welcome-subtitle">Here is how your POS business is doing today.</p></div>
                <Link href="/dashboard?screen=closing" className="primary-button dashboard-close-action">Float &amp; closing</Link>
            </section>

            {error && <section className="panel" role="alert"><p className="font-bold text-red-800">We could not load the latest business figures.</p><p className="mt-1 text-sm text-slate-600">Refresh the page or try again.</p><button type="button" className="text-action" onClick={load}>Try again</button></section>}
            {loading ? <section className="panel" role="status" aria-busy="true"><div className="dashboard-skeleton" /><p className="mt-3 text-sm text-slate-600">Loading your latest figures…</p></section> : summary && <>
                {provisional && <section className="financial-confidence" role="status"><strong>Estimated earnings — provisional</strong><span>{(summary.provisional_reasons || []).map((item) => cleanLabel(item.code)).join(' · ') || 'Some financial information is not complete or verified yet.'}</span></section>}
                <section className="metrics-grid" aria-label="Today's business summary">
                    <Metric label="Estimated earnings" value={money(summary.estimated_net_earnings)} detail={provisional ? 'Provisional · review fee completeness' : 'Based on complete financial data'} tone="green" />
                    <Metric label="Today's transaction volume" value={money(summary.transaction_volume)} detail="Successful POS transaction value" />
                    <Metric label="Successful transactions" value={String(summary.successful_transaction_count ?? 0)} detail="Today" />
                    <Metric label="Open issues" value={issuesAvailable ? String(attentionCount) : '—'} detail="Recent records to review" tone={attentionCount > 0 ? 'alert' : 'normal'} />
                    <Metric label="Expenses" value={money(summary.expenses)} detail="Recorded today" />
                    <Metric label="Cash float" value={expectedCash === null || expectedCash === undefined ? 'Unknown' : money(expectedCash)} detail="Expected cash position" />
                    <Metric label="Provider float" value={actualProviderBalance === null || actualProviderBalance === undefined ? 'Unknown' : money(actualProviderBalance)} detail="Latest entered balance" />
                </section>

                <section className="panel home-issues-panel">
                    <div className="section-heading"><div><h2>Things needing attention</h2><p>Open issues found in your recent POSPilot records.</p></div><Link href="/dashboard?screen=issues" className="text-action">Review issues <span aria-hidden="true">→</span></Link></div>
                    {actionItems.length > 0 && <ul className="mb-4 grid gap-2" aria-label="Business action items">{actionItems.map((item) => <li key={item.type} className="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-slate-800"><span>{item.label}</span><Link className="text-action shrink-0" href={item.href}>Review</Link></li>)}</ul>}
                    {issues.length ? <div className="table-scroll"><table className="data-table issues-table"><thead><tr><th>Provider</th><th>Date</th><th>What needs checking</th><th>Status</th></tr></thead><tbody>{issues.slice(0, 5).map((issue, index) => <tr key={`${issue.type}-${issue.settlement_id || index}`}><td data-label="Provider">{issue.provider || 'Provider'}</td><td data-label="Date">{issue.settlement_date || 'Date not recorded'}</td><td data-label="Review">{issue.message || cleanLabel(issue.type)}</td><td data-label="Status">{cleanLabel(issue.lifecycle_status)}</td></tr>)}</tbody></table></div> : !issuesAvailable ? <p className="home-empty-message">Issues could not be loaded. Open the Issues page to retry.</p> : actionItems.length === 0 && <p className="home-empty-message">No open issues are recorded for the last seven days.</p>}
                </section>

                <div className="grid gap-5 xl:grid-cols-2">
                    <section className="panel">
                        <div className="section-heading"><div><h2>Float today</h2><p>Balances stay unknown until you record them.</p></div><Link href="/dashboard?screen=closing" className="text-action">Float &amp; closing <span aria-hidden="true">→</span></Link></div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="rounded-xl border border-brand-line p-4"><span className="text-sm font-semibold text-slate-600">Expected cash</span><strong className="mt-2 block text-xl font-extrabold text-brand-ink">{expectedCash === null || expectedCash === undefined ? 'Not recorded' : money(expectedCash)}</strong><span className="mt-1 block text-sm text-slate-600">{expectedCash === null || expectedCash === undefined ? 'Enter opening cash in Float & Closing.' : 'Based on your recorded opening cash and activity.'}</span></div>
                            <div className="rounded-xl border border-brand-line p-4"><span className="text-sm font-semibold text-slate-600">Electronic/provider float</span><strong className="mt-2 block text-xl font-extrabold text-brand-ink">{actualProviderBalance === null || actualProviderBalance === undefined ? 'Unknown' : money(actualProviderBalance)}</strong><span className="mt-1 block text-sm text-slate-600">Actual provider balances are entered from your provider app.</span></div>
                        </div>
                    </section>
                    <section className="panel">
                        <div className="section-heading"><div><h2>Terminal performance</h2><p>Recorded activity over the last seven days.</p></div><Link href="/dashboard?screen=reports" className="text-action">Open reports <span aria-hidden="true">→</span></Link></div>
                        {terminalPerformance.length ? <div className="space-y-3">{terminalPerformance.slice(0, 3).map((row) => <div key={row.terminal_id ?? `${row.provider}-${row.terminal}`} className="flex min-w-0 items-center justify-between gap-3 rounded-xl border border-brand-line p-3"><div className="min-w-0"><strong className="block truncate text-sm">{row.terminal}</strong><span className="text-sm text-slate-600">{row.provider} · {row.transaction_count} transactions · {money(row.transaction_volume)}</span></div><div className="shrink-0 text-right"><strong className="block text-sm">{money(row.estimated_net_after_expenses)}</strong><span className="text-xs text-slate-600">{row.is_final ? 'Recorded estimate' : 'Provisional'}</span></div></div>)}</div> : <p className="text-sm text-slate-600">{terminalPerformanceAvailable ? 'Terminal comparisons appear after successful activity is assigned to a terminal.' : 'Terminal performance could not be loaded. Open Reports to retry.'}</p>}
                    </section>
                </div>

                {statusTotal > 0 && <section className="panel" aria-label="Transaction status breakdown">
                    <div className="section-heading"><div><h2>Transaction outcomes</h2><p>Today’s recorded POS activity by status.</p></div><Link href="/dashboard?screen=reports" className="text-action">Full report →</Link></div>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{[['successful', 'Successful'], ['pending', 'Pending'], ['failed', 'Failed'], ['reversed', 'Reversed']].map(([status, label]) => <div key={status}><div className="flex justify-between gap-3 text-sm"><span className="font-semibold text-slate-700">{label}</span><strong className="tabular-nums">{statusCounts[status] || 0}</strong></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"><div className={`h-full rounded-full ${status === 'successful' ? 'bg-emerald-600' : status === 'pending' ? 'bg-amber-500' : status === 'failed' ? 'bg-rose-500' : 'bg-violet-500'}`} style={{ width: `${((Number(statusCounts[status]) || 0) / statusTotal) * 100}%` }} /></div></div>)}</div>
                </section>}

                <section className="panel dashboard-transactions-panel">
                    <div className="section-heading"><div><h2>Recent transactions</h2><p>Latest POS activity on your account.</p></div><Link href="/transactions" className="text-action">See all <span aria-hidden="true">→</span></Link></div>
                    <div className="table-scroll"><table className="data-table provider-table recent-transactions-table"><thead><tr><th>Date and time</th><th>Provider</th><th>Reference</th><th>Amount</th><th>Earnings</th><th>Status</th></tr></thead><tbody>
                        {transactions.map((transaction) => <tr key={transaction.id}>
                            <td data-label="Date"><span className="date-cell">{dateTime(transaction.transaction_at)}</span></td>
                            <td data-label="Provider"><span className="provider-cell compact"><ProviderLogo provider={transaction.provider} size="sm" /><strong>{transaction.provider?.name || 'Provider'}</strong></span></td>
                            <td data-label="Reference" className="reference-cell">{transaction.external_reference || 'Not supplied'}</td>
                            <td data-label="Amount" className="amount-cell">{money(transaction.amount)}</td>
                            <td data-label="Earnings" className="earnings-cell">{`${transaction.financial_status?.is_final === false ? 'Estimated ' : ''}${money(transaction.estimated_earnings)}`}</td>
                            <td data-label="Status"><StatusBadge status={transaction.transaction_status} /></td>
                        </tr>)}
                        {!transactions.length && <tr><td colSpan="6" className="empty-row">No POS transactions have been recorded yet.</td></tr>}
                    </tbody></table></div>
                </section>

                {businessInsightEnabled && <section className="panel business-insight-panel" aria-labelledby="business-insight-title">
                    <div className="section-heading"><div><h2 id="business-insight-title">Business Insight</h2><p>A short explanation of today’s POSPilot figures.</p></div><button type="button" className="primary-button" onClick={explainMyDay} disabled={insightLoading}>{insightLoading ? 'Preparing insight…' : 'Explain my day'}</button></div>
                    {businessInsight && <p className="business-insight-copy" role="status">{businessInsight}</p>}{insightError && <p className="business-insight-error" role="alert">{insightError}</p>}
                    <p className="business-insight-note">POSPilot calculates the figures. The explanation uses only sanitized daily totals.{provisional ? ' Earnings are provisional while provider fees are incomplete.' : ''}</p>
                </section>}
                <p className="dashboard-footer"><span>Financial values and confidence status are supplied by POSPilot.</span><span>Amounts shown in NGN.</span></p>
            </>}
        </div>
    </AppShell>;
}

function Metric({ label, value, detail, tone = 'normal' }) {
    const toneClass = tone === 'green' ? 'metric-card-green' : tone === 'alert' ? 'metric-card-alert' : '';
    return <article className={`metric-card ${toneClass}`}><span className={`metric-icon ${tone === 'alert' ? 'metric-icon-rose' : 'metric-icon-mint'}`} aria-hidden="true">{tone === 'alert' ? '!' : '₦'}</span><div className="metric-content"><span className="metric-title">{label}</span><strong className="metric-value">{value}</strong><small className="metric-detail">{detail}</small></div></article>;
}

function StatusBadge({ status }) {
    const normalized = String(status || 'unknown').toLowerCase();
    const tone = normalized === 'successful' ? 'status-success' : normalized === 'pending' ? 'status-pending' : normalized === 'reversed' ? 'status-reversed' : 'status-failed';
    return <span className={`status-badge ${tone}`}><span />{cleanLabel(normalized)}</span>;
}

export default function Dashboard(props) {
    const { features } = usePage().props;
    const screen = new URLSearchParams(window.location.search).get('screen');
    if (props.staffRole === 'attendant') return <StaffDashboard businessName={props.businessName} role="attendant" />;
    if (props.staffRole === 'manager' && !['closing', 'issues', 'expenses', 'team-activity'].includes(screen)) return <StaffDashboard businessName={props.businessName} role="manager" />;
    if (screen === 'closing') return <DailyClosing />;
    if (screen === 'more') return <MoreTools />;
    if (screen === 'issues') return <Issues />;
    if (screen === 'reports') return <Reports />;
    if (screen === 'providers') return <ProviderAutomation />;
    if (screen === 'moniepoint') return features?.moniepointDirect ? <Moniepoint /> : <ProviderAutomation />;
    if (screen === 'setup') return <BusinessSetup />;
    if (screen === 'expenses') return <BusinessSetup expensesOnly />;
    if (screen === 'team-activity') return <StaffDashboard businessName={props.businessName} role="manager" />;
    if (props.staffRole === 'manager') return <StaffDashboard businessName={props.businessName} role="manager" />;
    return <DashboardHome {...props} />;
}
