import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import AppShell from '../Layouts/AppShell';
import { api, dateTime, money, today } from '../lib/api';
import DailyClosing from './Operations/DailyClosing';
import MoreTools from './Operations/MoreTools';
import ProviderAutomation from './Providers/ProviderAutomation';
import Moniepoint from './Providers/Moniepoint';
import BusinessSetup from './Operations/BusinessSetup';
import ProviderLogo from '../Components/ProviderLogo';
import StaffDashboard from './Staff/Dashboard';

const cleanLabel = (value) => String(value || 'Not recorded').replaceAll('_', ' ');

function DashboardHome({ businessName, businessInsightEnabled }) {
    const { auth, features } = usePage().props;
    const [summary, setSummary] = useState(null);
    const [transactions, setTransactions] = useState([]);
    const [providers, setProviders] = useState([]);
    const [providerRows, setProviderRows] = useState([]);
    const [connections, setConnections] = useState([]);
    const [moniepoint, setMoniepoint] = useState(null);
    const [gmail, setGmail] = useState(null);
    const [issues, setIssues] = useState([]);
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
            const [financial, activity, availableProviders, connectionList, moniepointStatus, gmailStatus, breakdown, reconciliationIssues] = await Promise.all([
                api(`/api/financial-summary?${period}`),
                api('/api/transactions?per_page=5'),
                api('/api/providers'),
                api('/api/provider-connections'),
                features?.moniepointDirect ? api('/api/providers/moniepoint/connection') : Promise.resolve(null),
                api('/api/gmail/connection'),
                api(`/api/provider-breakdown?${period}`),
                api(`/api/reconciliation/issues?${period}`),
            ]);
            setSummary(financial);
            setTransactions(activity.data || []);
            setProviders(availableProviders.data || []);
            setConnections(connectionList.data || []);
            setMoniepoint(moniepointStatus);
            setGmail(gmailStatus);
            setProviderRows(breakdown.data || []);
            setIssues(reconciliationIssues.data || []);
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
    const attention = summary?.attention_transaction_count ?? 0;
    const greetingHour = new Date().getHours();
    const greeting = greetingHour < 12 ? 'Good morning' : greetingHour < 17 ? 'Good afternoon' : 'Good evening';

    function providerState(provider) {
        if (provider.slug === 'moniepoint' && features?.moniepointDirect) {
            if (moniepoint?.error || ['error', 'failed'].includes(moniepoint?.status)) return 'Connection issue';
            return moniepoint?.connected ? 'Connected' : 'Setup required';
        }
        if ((gmail?.selected_provider_slugs || []).includes(provider.slug)) {
            if (gmail.status === 'permission_expired') return 'Gmail permission expired';
            if (!gmail.connected) return 'Connect Gmail';
            if (gmail.statement_counts?.needs_setup) return 'Statement needs setup';
            return gmail.last_synced_at ? 'Waiting for statement' : 'Gmail connected';
        }
        if (provider.slug === 'moniepoint' && !features?.moniepointDirect) return 'Automatic statements';
        const connection = connections.find((item) => item.provider?.id === provider.id);
        if (connection?.connection_state === 'sync_error') return 'Connection issue';
        if (connection?.connection_state === 'manual') return 'Manual records';
        if (provider.capabilities?.direct_connection === 'requires_provider_access') return 'Provider API access required';
        if (provider.capabilities?.direct_connection === 'coming_later') return 'Direct connection coming later';
        if (provider.capabilities?.direct_connection === 'planned') return 'Direct connection planned';
        if (connection?.connection_state === 'csv_only' || provider.capabilities?.csv_transaction_import === 'supported') return 'No automatic connection';
        return 'Direct connection unavailable';
    }

    const expectedProviders = providers.filter((provider) => provider.capabilities?.direct_connection !== undefined);
    const greetingText = businessName || auth?.user?.name || 'your business';

    return <AppShell title="Home">
        <div className="dashboard">
            <section className="welcome-row">
                <div><span className="eyebrow"><span className="eyebrow-dot" /> TODAY · {new Date().toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }).toUpperCase()}</span><h1>{greeting}, {greetingText}</h1><p className="welcome-subtitle">Here is how your POS business is doing today.</p></div>
                <Link href="/dashboard?screen=closing" className="primary-button dashboard-close-action">Close today’s business</Link>
            </section>

            {error && <section className="panel" role="alert"><p className="font-bold text-red-800">We couldn’t load the latest business figures.</p><p className="mt-1 text-sm text-slate-600">{error.message}</p><button type="button" className="text-action" onClick={load}>Try again</button></section>}
            {loading ? <section className="panel" role="status" aria-busy="true"><div className="dashboard-skeleton" /><p className="mt-3 text-sm text-slate-600">Loading your latest figures…</p></section> : summary && <>
                {provisional && <section className="financial-confidence" role="status"><strong>Estimated earnings — provisional</strong><span>{(summary.provisional_reasons || []).map((item) => cleanLabel(item.code)).join(' · ') || 'Some financial information is not complete or verified yet.'}</span></section>}
                <section className="metrics-grid" aria-label="Today's business summary">
                    <Metric label={provisional ? 'Estimated earnings' : 'Estimated earnings'} value={money(summary.estimated_net_earnings)} detail={provisional ? 'Provisional · review fee completeness' : 'Based on complete financial data'} tone="green" />
                    <Metric label="Processed" value={money(summary.transaction_volume)} detail="Successful transaction value" />
                    <Metric label="Successful transactions" value={String(summary.successful_transaction_count ?? 0)} detail="Today" />
                    <Metric label="Needs attention" value={String(attention)} detail={`${summary.reconciliation_issue_count ?? 0} reconciliation issue(s)`} tone={attention > 0 ? 'alert' : 'normal'} />
                </section>

                <section className="panel business-insight-panel" aria-labelledby="business-insight-title">
                    <div className="section-heading">
                        <div><h2 id="business-insight-title">Business Insight</h2><p>A short explanation of today’s POSPilot figures.</p></div>
                        <button type="button" className="primary-button" onClick={explainMyDay} disabled={insightLoading || !businessInsightEnabled}>
                            {!businessInsightEnabled ? 'Coming soon' : insightLoading ? 'Preparing insight…' : 'Explain my day'}
                        </button>
                    </div>
                    {businessInsight && <p className="business-insight-copy" role="status">{businessInsight}</p>}
                    {insightError && <p className="business-insight-error" role="alert">{insightError}</p>}
                    {!businessInsightEnabled && <p className="business-insight-note" role="status">AI Business Insight is coming soon. Your dashboard figures remain available, and POSPilot is not sending data to Cencori while this feature is disabled.</p>}
                    {businessInsightEnabled && <p className="business-insight-note">POSPilot calculates your financial figures. When requested, daily totals are sent to Cencori for explanation; transaction records and customer details are not sent.{provisional ? ' Earnings are provisional while provider fees are incomplete.' : ''}</p>}
                </section>

                <section className="panel home-status-panel">
                    <div className="section-heading"><div><h2>Today’s status</h2><p>Key checks from your POSPilot records.</p></div></div>
                    <div className="home-status-grid">
                        <StatusLine ok={(summary.successful_transaction_count || 0) > 0} text={`${summary.successful_transaction_count || 0} successful transactions`} />
                        <StatusLine ok={Boolean(gmail?.connected)} warn={gmail?.status === 'sync_error'} text={gmail?.connected ? 'Gmail statement automation connected' : 'Connect Gmail in Providers to receive statements'} />
                        <StatusLine ok={!attention} warn={attention > 0} text={attention ? `${attention} item${attention === 1 ? '' : 's'} need review` : 'No items need review'} />
                    </div>
                </section>

                <div className="dashboard-columns">
                    <section className="panel dashboard-transactions-panel">
                        <div className="section-heading"><div><h2>Recent transactions</h2><p>Latest provider activity on your account.</p></div><Link href="/transactions" className="text-action">See all <span aria-hidden="true">→</span></Link></div>
                        <div className="table-scroll"><table className="data-table provider-table recent-transactions-table"><thead><tr><th>Date and time</th><th>Provider</th><th>Reference</th><th>Amount</th><th>Earnings</th><th>Status</th></tr></thead><tbody>
                            {transactions.map((transaction) => <tr key={transaction.id}>
                                <td data-label="Date"><span className="date-cell">{dateTime(transaction.transaction_at)}</span></td>
                                <td data-label="Provider"><span className="provider-cell compact"><ProviderLogo provider={transaction.provider} size="sm" /><strong>{transaction.provider?.name || 'Provider'}</strong></span></td>
                                <td data-label="Reference" className="reference-cell">{transaction.external_reference || 'Not supplied'}</td>
                                <td data-label="Amount" className="amount-cell">{money(transaction.amount)}</td>
                                <td data-label="Earnings" className="earnings-cell">{transaction.financial_status?.is_final === false ? 'Estimated ' : ''}{money(transaction.estimated_earnings)}</td>
                                <td data-label="Status"><StatusBadge status={transaction.transaction_status} /></td>
                            </tr>)}
                            {!transactions.length && <tr><td colSpan="6" className="empty-row">No transactions yet. Once POSPilot receives activity, it will appear here.</td></tr>}
                        </tbody></table></div>
                    </section>
                    <section className="panel dashboard-provider-panel">
                        <div className="section-heading"><div><h2>Providers</h2><p>Current setup and today’s activity.</p></div><Link href="/dashboard?screen=providers" className="text-action">Manage <span aria-hidden="true">→</span></Link></div>
                        <div className="provider-overview-list">
                            {expectedProviders.map((provider) => {
                                const row = providerRows.find((item) => item.provider_id === provider.id);
                                return <div className="provider-overview-row" key={provider.id}>
                                    <ProviderLogo provider={provider} size="md" />
                                    <div className="provider-overview-copy"><strong>{provider.name}</strong><span>{row ? `${row.transaction_count} successful · ${money(row.transaction_volume)}` : 'No successful activity today'}</span></div>
                                    <span className={`provider-state ${provider.slug === 'moniepoint' && moniepoint?.connected ? 'provider-state-connected' : ''}`}>{providerState(provider)}</span>
                                </div>;
                            })}
                            {!expectedProviders.length && <p className="empty-row">No provider records available yet.</p>}
                        </div>
                        <Link href="/dashboard?screen=providers" className="provider-setup-link">Manage provider setup <span aria-hidden="true">→</span></Link>
                    </section>
                </div>

                <section className="panel home-issues-panel">
                    <div className="section-heading"><div><h2>Items to review</h2><p>Based on settlement reconciliation records.</p></div><Link href="/reconciliation" className="text-action">Open reconciliation <span aria-hidden="true">→</span></Link></div>
                    {issues.length ? <div className="table-scroll"><table className="data-table issues-table"><thead><tr><th>Provider</th><th>Date</th><th>What needs checking</th><th>Expected</th></tr></thead><tbody>{issues.slice(0, 5).map((issue, index) => <tr key={`${issue.type}-${issue.settlement_id || index}`}><td>{issue.provider || 'Provider'}</td><td>{issue.settlement_date || 'Date not recorded'}</td><td>{issue.message || cleanLabel(issue.type)}</td><td>{issue.expected_amount ? money(issue.expected_amount) : 'Not available'}</td></tr>)}</tbody></table></div> : <p className="home-empty-message">Everything looks good. There are no reconciliation issues for today.</p>}
                </section>
                <p className="dashboard-footer"><span>Financial values and confidence status are supplied by POSPilot.</span><span>Amounts shown in NGN.</span></p>
            </>}
        </div>
    </AppShell>;
}

function Metric({ label, value, detail, tone = 'normal' }) {
    const toneClass = tone === 'green' ? 'metric-card-green' : tone === 'alert' ? 'metric-card-alert' : '';
    return <article className={`metric-card ${toneClass}`}><span className={`metric-icon ${tone === 'alert' ? 'metric-icon-rose' : 'metric-icon-mint'}`} aria-hidden="true">{tone === 'alert' ? '!' : '₦'}</span><div className="metric-content"><span className="metric-title">{label}</span><strong className="metric-value">{value}</strong><small className="metric-detail">{detail}</small></div></article>;
}

function StatusLine({ ok, warn = false, text }) {
    return <div className="home-status-line"><span aria-hidden="true" className={warn ? 'home-status-icon status-icon-warn' : ok ? 'home-status-icon status-icon-ok' : 'home-status-icon'}>{warn ? '!' : ok ? '✓' : '–'}</span><span>{text}</span></div>;
}

function StatusBadge({ status }) {
    const normalized = String(status || 'unknown').toLowerCase();
    const tone = normalized === 'successful' ? 'status-success' : normalized === 'pending' ? 'status-pending' : normalized === 'reversed' ? 'status-reversed' : 'status-failed';
    return <span className={`status-badge ${tone}`}><span />{cleanLabel(normalized)}</span>;
}

export default function Dashboard(props) {
    const { features } = usePage().props;
    if (props.staffRole) return <StaffDashboard businessName={props.businessName} role={props.staffRole} />;
    const screen = new URLSearchParams(window.location.search).get('screen');
    if (screen === 'closing') return <DailyClosing />;
    if (screen === 'more') return <MoreTools />;
    if (screen === 'providers') return <ProviderAutomation />;
    if (screen === 'moniepoint') return features?.moniepointDirect ? <Moniepoint /> : <ProviderAutomation />;
    if (screen === 'setup') return <BusinessSetup />;
    if (screen === 'expenses') return <BusinessSetup expensesOnly />;
    if (screen === 'team-activity') return <StaffDashboard businessName={props.businessName} role="manager" />;
    return <DashboardHome {...props} />;
}
