import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import { api, dateTime, money } from '../../lib/api';
import ProviderLogo from '../../Components/ProviderLogo';

const statuses = ['successful', 'pending', 'failed', 'reversed'];
const settlementStatuses = ['pending', 'settled', 'unreconciled', 'disputed'];
const defaultFilters = () => {
    const query = new URLSearchParams(window.location.search);
    return { provider_id: query.get('provider_id') || '', terminal_id: query.get('terminal_id') || '', transaction_status: query.get('transaction_status') || query.get('status') || '', settlement_status: query.get('settlement_status') || '', from: query.get('from') || '', to: query.get('to') || '', reference: query.get('reference') || query.get('search') || '' };
};
const label = (value) => String(value || 'Not recorded').replaceAll('_', ' ');
const terminalLabel = (providerName, terminalName) => {
    const terminal = String(terminalName || '').trim();
    const provider = String(providerName || '').trim();
    const displayName = provider && terminal.toLowerCase().startsWith(provider.toLowerCase())
        ? terminal.slice(provider.length).trim()
        : terminal;

    return displayName || terminal || 'Terminal not recorded';
};

export default function Index() {
    const { url } = usePage();
    const [filters, setFilters] = useState(defaultFilters);
    const [result, setResult] = useState(null);
    const [providers, setProviders] = useState([]);
    const [terminals, setTerminals] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => { setFilters(defaultFilters()); }, [url]);
    useEffect(() => {
        let active = true;
        setLoading(true);
        setError(null);
        const query = new URLSearchParams(window.location.search);
        const requested = defaultFilters();
        Object.entries(requested).forEach(([key, value]) => { if (value) query.set(key, value); else query.delete(key); });
        if (!query.has('per_page')) query.set('per_page', '20');
        Promise.all([api('/api/providers'), api('/api/terminals'), api(`/api/transactions?${query.toString()}`)])
            .then(([providerList, terminalList, transactions]) => {
                if (!active) return;
                setProviders(providerList.data || []);
                setTerminals(terminalList.data || []);
                setResult(transactions);
            })
            .catch((requestError) => active && setError(requestError))
            .finally(() => active && setLoading(false));
        return () => { active = false; };
    }, [url]);

    function submit(event) {
        event.preventDefault();
        const values = Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== ''));
        router.get('/transactions', { ...values, per_page: 20 }, { preserveScroll: true });
    }

    function update(key, value) {
        setFilters((current) => ({ ...current, [key]: value }));
    }

    const rows = result?.data || [];
    const activeFilterCount = Object.values(filters).filter(Boolean).length;

    return <AppShell title="Transactions"><div className="transactions-page">
        <div className="transactions-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> YOUR BUSINESS ACTIVITY</span><h1>Transactions</h1><p>Review your POS activity and the earnings recorded for each transaction.</p></div></div>
        <form onSubmit={submit} className="transactions-filter-bar" aria-label="Transaction filters">
            <label className="tx-filter-select"><span>Provider</span><select name="provider_id" value={filters.provider_id} onChange={(event) => update('provider_id', event.target.value)}><option value="">All providers</option>{providers.map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</select></label>
            <label className="tx-filter-select"><span>Terminal</span><select name="terminal_id" value={filters.terminal_id} onChange={(event) => update('terminal_id', event.target.value)}><option value="">All terminals</option>{terminals.map((terminal) => <option key={terminal.id} value={terminal.id}>{terminal.name}</option>)}</select></label>
            <label className="tx-filter-select"><span>Transaction status</span><select name="transaction_status" value={filters.transaction_status} onChange={(event) => update('transaction_status', event.target.value)}><option value="">All statuses</option>{statuses.map((status) => <option key={status} value={status}>{label(status)}</option>)}</select></label>
            <label className="tx-filter-select"><span>Settlement status</span><select name="settlement_status" value={filters.settlement_status} onChange={(event) => update('settlement_status', event.target.value)}><option value="">All settlement states</option>{settlementStatuses.map((status) => <option key={status} value={status}>{label(status)}</option>)}</select></label>
            <label className="tx-filter-select tx-filter-date"><span>From</span><input type="date" name="from" value={filters.from} onChange={(event) => update('from', event.target.value)} /></label>
            <label className="tx-filter-select tx-filter-date"><span>To</span><input type="date" name="to" value={filters.to} onChange={(event) => update('to', event.target.value)} /></label>
            <label className="tx-filter-search"><span>Reference</span><input type="search" name="reference" value={filters.reference} placeholder="Search a reference" onChange={(event) => update('reference', event.target.value)} /></label>
            <div className="transaction-filter-actions"><button className="tx-action-button tx-action-button-primary" type="submit">Apply filters</button><Link className="tx-action-button" href="/transactions">Clear{activeFilterCount ? ` (${activeFilterCount})` : ''}</Link></div>
        </form>

        {error && <div className="tx-error" role="alert"><strong>Transactions could not be loaded.</strong><span>{error.message}</span><button type="button" onClick={() => window.location.reload()}>Try again</button></div>}
        <div className="transactions-count-row"><div className="transactions-count">{result?.total ?? '—'} transactions<span>{result ? ` · Page ${result.current_page} of ${result.last_page}` : ''}</span></div><span className="tx-detail-label">Newest first</span></div>
        <section className="transactions-list-panel" aria-label="Transaction list" aria-busy={loading}>
            {loading ? <div className="tx-loading" role="status" aria-label="Loading transactions"><div className="tx-skeleton" /><div className="tx-skeleton" /><div className="tx-skeleton" /><p>Loading your transactions…</p></div> : rows.length === 0 ? <div className="tx-empty"><div><h3>{activeFilterCount ? 'No matching transactions' : 'No transactions yet'}</h3><p>{activeFilterCount ? 'Try a wider date range or remove one or more filters.' : 'When POSPilot receives supported provider activity, it will appear here.'}</p>{activeFilterCount > 0 && <Link href="/transactions" className="tx-action-button">Clear filters</Link>}</div></div> : <>
                <div className="tx-table-scroll"><table className="tx-table"><thead><tr><th>Date &amp; time</th><th>Provider / terminal</th><th>Reference</th><th>Amount</th><th>Earnings</th><th>Status</th><th>Action</th></tr></thead><tbody>
                    {rows.map((transaction) => <tr key={transaction.id} className="tx-row">
                        <td data-label="Date & time"><span className="tx-date-cell">{dateTime(transaction.transaction_at)}</span></td>
                        <td data-label="Provider / terminal"><span className="provider-cell compact"><ProviderLogo provider={transaction.provider} size="md" /><span><strong>{transaction.provider?.name || 'Provider'}</strong><small>{terminalLabel(transaction.provider?.name, transaction.terminal?.name)}</small></span></span></td>
                        <td data-label="Reference" className="reference-cell">{transaction.external_reference || 'Not supplied'}</td>
                        <td data-label="Amount" className="tx-principal">{money(transaction.amount)}</td>
                        <td data-label="Earnings" className="tx-earnings"><strong>{money(transaction.estimated_earnings)}</strong>{transaction.financial_status?.is_final === false && <small>Provisional</small>}</td>
                        <td data-label="Status"><StatusBadge status={transaction.transaction_status} /></td>
                        <td data-label="Action" className="tx-row-link"><Link href={`/transactions/${transaction.id}`} aria-label={`View transaction ${transaction.external_reference || transaction.id}`}>View <span aria-hidden="true">→</span></Link></td>
                    </tr>)}
                </tbody></table></div>
                <div className="tx-pagination"><span>Showing {rows.length} of {result.total} transactions</span><div className="tx-pagination-buttons">{result.prev_page_url && <PageLink url={result.prev_page_url} filters={filters}>Previous</PageLink>}<span>Page {result.current_page} / {result.last_page}</span>{result.next_page_url && <PageLink url={result.next_page_url} filters={filters}>Next</PageLink>}</div></div>
            </>}
        </section>
    </div></AppShell>;
}

function PageLink({ url, children, filters }) {
    const page = new URL(url, window.location.origin).searchParams.get('page');
    const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''));
    if (page) query.set('page', page);
    query.set('per_page', '20');
    return <Link href={`/transactions?${query.toString()}`} className="tx-action-button">{children}</Link>;
}

function StatusBadge({ status }) {
    const normalized = String(status || 'unknown').toLowerCase();
    const tone = normalized === 'successful' ? 'status-success' : normalized === 'pending' ? 'status-pending' : normalized === 'reversed' ? 'status-reversed' : 'status-failed';
    return <span className={`status-badge ${tone}`}><span />{label(normalized)}</span>;
}
