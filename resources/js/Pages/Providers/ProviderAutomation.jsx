import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import ProviderLogo from '../../Components/ProviderLogo';
import { api } from '../../lib/api';
import { Button, Card, ErrorNotice, Field, LoadingCard, Notice, StatusPill } from '../../Components/PosPilotUI';
import { trackSafeEvent } from '../../lib/analytics';

const supported = ['opay', 'palmpay', 'moniepoint'];
const statusLabels = {
    connected: 'Gmail connected', syncing: 'Syncing', sync_error: 'Could not check Gmail', permission_expired: 'Gmail permission expired',
    disconnected: 'Gmail disconnected', needs_setup: 'Statement needs setup', needs_setup_expired: 'Setup window expired; wait for the next statement',
    unsupported_format: 'Unsupported statement format', pdf_not_supported: 'PDF not supported for automatic import', xls_not_supported: 'Legacy XLS not supported', unsupported_schema: 'Statement format needs review', rejected: 'Statement is too large to process', failed: 'Statement could not be processed',
};

export default function ProviderAutomation() {
    const { features } = usePage().props;
    const [status, setStatus] = useState(null);
    const [providers, setProviders] = useState([]);
    const [terminals, setTerminals] = useState([]);
    const [messages, setMessages] = useState([]);
    const [selected, setSelected] = useState([]);
    const [rules, setRules] = useState({});
    const [mappings, setMappings] = useState({});
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);

    async function load() {
        setLoading(true); setError(null);
        try {
            const [connection, available, terminalList, statements] = await Promise.all([
                api('/api/gmail/connection'), api('/api/providers'), api('/api/terminals'), api('/api/gmail/statements'),
            ]);
            setStatus(connection); setProviders((available.data || []).filter((item) => supported.includes(item.slug)));
            setTerminals(terminalList.data || []); setMessages(statements.data || []);
            setSelected(connection.selected_provider_slugs || []); setRules(connection.provider_rules || {});
        } catch (requestError) { setError(requestError); }
        finally { setLoading(false); }
    }
    useEffect(() => { load(); }, []);

    function toggleProvider(slug) {
        setSelected((current) => current.includes(slug) ? current.filter((item) => item !== slug) : [...current, slug]);
    }

    async function saveSetup(event) {
        event.preventDefault(); setBusy(true); setError(null); setNotice(null);
        try {
            await api('/api/gmail/connection/rules', { method: 'PUT', body: { selected_provider_slugs: selected, provider_rules: rules } });
            setNotice('Provider choices saved. Add a sender address when you can verify it from a real statement email.'); await load();
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }

    async function syncNow() {
        setBusy(true); setError(null); setNotice(null);
        try {
            trackSafeEvent('statement_sync_started');
            const result = await api('/integrations/gmail/sync', { method: 'POST', body: {} });
            const knownProcessed = new Set(messages.filter((message) => message.status === 'processed').map((message) => message.id));
            const latestStatements = await api('/api/gmail/statements');
            if ((latestStatements.data || []).some((message) => message.status === 'processed' && message.rows_imported > 0 && !knownProcessed.has(message.id))) {
                trackSafeEvent('statement_imported');
            }
            setNotice(result.statements_found ? `Check complete. ${result.statements_found} statement attachment${result.statements_found === 1 ? '' : 's'} found.` : 'Check complete. No new matching statements were found.');
            await load();
        }
        catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }

    async function importOlder() {
        setBusy(true); setError(null); setNotice(null);
        try {
            const result = await api('/integrations/gmail/import-older', { method: 'POST', body: {} });
            setNotice(result.statements_found ? `Older statement check complete. ${result.statements_found} matching attachment${result.statements_found === 1 ? '' : 's'} found.` : 'Older statement check complete. No matching statements from the past year were found.');
            await load();
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }

    async function saveMapping(message) {
        const form = mappings[message.id] || {};
        setBusy(true); setError(null); setNotice(null);
        try {
            const result = await api(`/api/gmail/statements/${message.id}/mapping`, { method: 'POST', body: { terminal_id: form.terminal_id, column_mapping: form.column_mapping || {} } });
            if (result.status === 'processed' && result.rows_imported > 0) {
                trackSafeEvent('statement_imported');
            }
            setNotice('Mapping saved. Statement processing is complete.'); await load();
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }

    const setRule = (slug, value) => setRules((current) => ({ ...current, [slug]: { ...(current[slug] || {}), sender_email: value } }));
    const setMapping = (id, field, value) => setMappings((current) => ({ ...current, [id]: { ...(current[id] || {}), column_mapping: { ...(current[id]?.column_mapping || {}), [field]: value } } }));
    const savedProviders = status?.selected_provider_slugs || [];
    const opay = providers.find((provider) => provider.slug === 'opay');
    const moniepoint = providers.find((provider) => provider.slug === 'moniepoint');
    const directStatus = (provider, enabled) => {
        if (provider?.capabilities?.connector_implementation === 'planned') return 'Direct transaction sync not available yet';
        if (provider?.capabilities?.direct_connection === 'requires_provider_access') return enabled ? 'Provider access required' : 'Connection setup disabled';
        return 'Not currently available';
    };
    const rulesPersisted = selected.length > 0 && selected.length === savedProviders.length && selected.every((slug) => savedProviders.includes(slug)
        && (status?.provider_rules?.[slug]?.sender_email || '') === (rules[slug]?.sender_email || ''));

    return <AppShell title="Providers / Data Sources"><div className="ops-page">
        <header className="ops-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> PROVIDERS / DATA SOURCES</span><h1>Connect transaction sources</h1><p>POSPilot brings your POS transactions, charges, fees, expenses and reconciliation into one place.</p></div></header>
        {error && <div className="mb-5"><ErrorNotice error={error} /></div>}{notice && <div className="mb-5"><Notice tone="success">{notice}</Notice></div>}
        {loading ? <LoadingCard label="Checking provider setup…" /> : <div className="space-y-5">
            <Card><div><h2 className="text-lg font-extrabold">Automatic data sources</h2><p className="mt-1 text-sm text-slate-600">Direct provider APIs are preferred when your business has official access. Email statements work for agents without developer credentials. You can use both.</p></div>
                <div className="mt-4 grid gap-3 lg:grid-cols-3">
                    <SourceCard provider="OPay" title="Direct connection" status={directStatus(opay, features?.opayDirect)} detail="Preferred first when enabled for your business. OPay documents transaction-history access for eligible Business accounts, but POSPilot has not implemented that connector yet." />
                    <SourceCard provider="Moniepoint" title="Direct connection" status={directStatus(moniepoint, features?.moniepointDirect)} detail="Preferred after OPay for eligible Moniepoint Business/API accounts. Current credential checks do not sync existing POS transaction history." action={features?.moniepointDirect ? <Link href="/dashboard?screen=moniepoint" onClick={() => trackSafeEvent('provider_connection_started')} className="text-sm font-bold text-brand-accent">Advanced business integration</Link> : null} />
                    <SourceCard provider="Email Statements" title="Fallback for everyday POS use" status={status?.connected ? 'Connected' : 'Connect email'} detail="Request or send a statement from your provider app. POSPilot can then find its email and process supported attachments. OPay, PalmPay, and Moniepoint statement setup is available below." action={<a href="#gmail-settings" className="text-sm font-bold text-brand-accent">Set up email statements</a>} />
                </div>
                <p className="mt-4 text-xs leading-5 text-slate-500">PalmPay direct history access is not verified. PalmPay can be selected below for email statement processing. Direct-source availability does not mean a provider has been live-tested.</p>
            </Card>
            {!status?.enabled && <Notice tone="info">Gmail statement beta is currently disabled by the deployment feature flag.</Notice>}
            <form onSubmit={saveSetup} className="space-y-5">
                <Card><div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-lg font-extrabold">1. Choose your providers</h2><p className="mt-1 text-sm text-slate-600">You can choose more than one provider and add multiple accounts or terminals later.</p></div><StatusPill tone="neutral">Statements only</StatusPill></div>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2">{providers.map((provider) => <label key={provider.slug} className="flex min-h-16 cursor-pointer items-center gap-3 rounded-xl border border-brand-line p-4 focus-within:ring-2 focus-within:ring-brand-accent"><input type="checkbox" checked={selected.includes(provider.slug)} onChange={() => toggleProvider(provider.slug)} className="h-5 w-5 rounded border-slate-300 text-brand-accent focus:ring-brand-accent" /><ProviderLogo provider={provider} size="md" /><span><strong className="block">{provider.name}</strong><small className="text-slate-600">Automatic statements; format is mapped before import.</small></span></label>)}</div>
                </Card>
                <Card><h2 className="text-lg font-extrabold">2. Optional sender match</h2><p className="mt-1 text-sm leading-6 text-slate-600">Leave blank to search recent messages by provider and statement terms. If you verify the sender on a real provider email, enter its exact address to narrow the search.</p><div className="mt-4 grid gap-4 sm:grid-cols-2">{providers.filter((provider) => selected.includes(provider.slug)).map((provider) => <Field key={provider.slug} label={`${provider.name} statement sender (optional)`} name={`sender-${provider.slug}`} type="email" autoComplete="off" value={rules[provider.slug]?.sender_email || ''} onChange={(event) => setRule(provider.slug, event.target.value)} placeholder="Leave blank until verified" />)}</div>
                    <Button type="submit" className="mt-4" disabled={busy || selected.length === 0 || !status?.enabled}>Save provider setup</Button>
                </Card>
            </form>
            <Card id="gmail-settings"><div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-lg font-extrabold">Email Statements · Gmail</h2><p className="mt-1 max-w-3xl text-sm leading-6 text-slate-600">Connect Gmail so POSPilot can find POS statements sent to your inbox. This authorization is separate from Google sign-in. Google’s <code>gmail.readonly</code> scope is restricted and technically allows viewing messages and settings across Gmail. POSPilot is designed to search for and process statement emails matching the providers you configure. Unrelated email content is not used by POSPilot’s statement processing.</p><p className="mt-2 text-sm text-slate-600">POSPilot does not receive every provider transaction automatically by email. Request or send a statement from your provider app first. After it arrives, POSPilot searches for it, retrieves the attachment, maps the account and terminal, and imports through its existing financial pipeline. OAuth tokens stay server-side in encrypted storage.</p></div><StatusPill tone={status?.connected ? 'good' : 'neutral'}>{statusLabels[status?.status] || 'Gmail disconnected'}</StatusPill></div>
                {status?.connected ? <div className="mt-4 flex flex-wrap gap-3"><span className="self-center text-sm font-semibold text-slate-700">Email: {status.gmail_address_masked || 'Connected'}</span>{status.status === 'permission_expired' ? <a className="secondary-button" href={status?.configured && rulesPersisted ? '/integrations/gmail/connect' : undefined}>Reconnect Gmail</a> : <><Button type="button" variant="secondary" onClick={syncNow} disabled={busy || !rulesPersisted || !status?.enabled}>Check for statements</Button><Button type="button" variant="secondary" onClick={importOlder} disabled={busy || !rulesPersisted || !status?.enabled}>Import older statements</Button></>}<Button type="button" variant="danger" onClick={() => router.delete('/integrations/gmail')} disabled={busy}>Disconnect Gmail</Button><span className="self-center text-sm text-slate-600">{status.last_synced_at ? `Last checked ${new Date(status.last_synced_at).toLocaleString()}` : 'Never checked'}</span><span className="basis-full text-sm text-slate-600">Older import searches matching provider statement attachments from the past year. Unrelated email is excluded.</span></div> : <a className="primary-button mt-4 inline-flex" href={status?.enabled && status?.configured && rulesPersisted ? '/integrations/gmail/connect' : undefined} aria-disabled={!status?.enabled || !status?.configured || !rulesPersisted} onClick={(event) => { if (!status?.enabled || !status?.configured || !rulesPersisted) event.preventDefault(); }}>{status?.configured ? 'Connect Gmail' : 'Gmail setup unavailable'}</a>}
                <p className="mt-3 text-xs leading-5 text-slate-500">Testing mode is limited to Google Cloud test users. Refresh tokens expire after about seven days. General Gmail access requires Google's restricted-scope verification.</p>
            </Card>
            <Card><div className="flex items-start justify-between gap-4"><div><h2 className="text-lg font-extrabold">Statement inbox</h2><p className="mt-1 text-sm text-slate-600">CSV and XLSX spreadsheet attachments are supported. PDF, legacy XLS, and other formats are marked unsupported.</p></div></div>
                {messages.length ? <div className="mt-4 space-y-4">{messages.map((message) => <StatementItem key={message.id} message={message} terminals={terminals.filter((item) => item.provider?.slug === message.provider?.slug)} mapping={mappings[message.id] || {}} setMapping={(field, value) => field === 'terminal_id' ? setMappings((current) => ({ ...current, [message.id]: { ...(current[message.id] || {}), terminal_id: value } })) : setMapping(message.id, field, value)} save={() => saveMapping(message)} busy={busy} />)}</div> : <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">No statements found yet. Request or send one from your provider app, then check Gmail here.</p>}
            </Card>
            {status?.statement_counts?.unsupported_format > 0 && <Notice tone="info">A provider sent a format POSPilot cannot verify yet. Choose CSV from the provider when available.</Notice>}
            {status?.statement_counts?.needs_setup > 0 && <Notice tone="warning">A statement needs setup. No transactions are imported until its columns and destination terminal are confirmed.</Notice>}
        </div>}
        <p className="mt-5 text-sm text-slate-600">POSPilot will never ask for your provider password, transaction PIN, or OTP. <Link href="/privacy" className="font-bold text-brand-accent">Read our privacy information</Link>.</p>
    </div></AppShell>;
}

function SourceCard({ provider, title, status, detail, action }) {
    return <article className="rounded-xl border border-brand-line bg-slate-50 p-4"><div className="flex items-center gap-3"><ProviderLogo provider={provider} size="md" /><div><h3 className="font-extrabold">{provider}</h3><p className="text-xs text-slate-600">{title}</p></div></div><p className="mt-3 text-sm font-bold text-brand-ink">{status}</p><p className="mt-1 min-h-12 text-sm leading-5 text-slate-600">{detail}</p>{action && <div className="mt-3">{action}</div>}</article>;
}

function StatementItem({ message, terminals, mapping, setMapping, save, busy }) {
    const fields = [
        ['amount', 'Amount', true], ['transaction_at', 'Date and time', true], ['transaction_status', 'Status', true],
        ['external_reference', 'Transaction reference', false], ['provider_fee', 'Provider fee', false], ['customer_charge', 'Customer charge', false],
        ['transaction_type', 'Transaction type', false], ['merchant_identifier', 'Merchant ID', false], ['business_identifier', 'Business ID', false],
        ['terminal_identifier', 'Terminal ID or serial', false], ['provider_account_identifier', 'Provider account identifier', false], ['settlement_reference', 'Settlement reference', false],
    ];
    return <article className="rounded-xl border border-brand-line p-4"><div className="flex flex-wrap items-center justify-between gap-3"><div className="flex items-center gap-2"><ProviderLogo provider={message.provider} size="sm" /><strong>{message.provider?.name || 'Provider'} statement</strong></div><StatusPill tone={message.status === 'processed' ? 'good' : message.status === 'needs_setup' ? 'warning' : 'neutral'}>{statusLabels[message.status] || message.status}</StatusPill></div>
        <p className="mt-2 text-sm text-slate-600">{message.file_type?.toUpperCase()} · {message.received_at ? new Date(message.received_at).toLocaleString() : 'Time not available'}{message.status === 'processed' ? ` · ${message.rows_imported} imported · ${message.rows_duplicate} duplicates · ${message.rows_failed} rejected` : ''}</p>
        {message.status === 'needs_setup' && <p className="mt-2 text-sm text-slate-700">We found a {message.provider?.name || 'provider'} statement that needs setup.{message.masked_account_identifier ? ` Account: ${message.masked_account_identifier}` : ' We could not confidently identify an account from the available headers.'}</p>}
        {message.can_map && <div className="mt-4 space-y-4">{terminals.length ? <div><p className="text-sm font-bold">Match this statement to a terminal</p><select aria-label="Destination terminal" className="mt-2 w-full rounded-xl border border-slate-300 p-3" value={mapping.terminal_id || ''} onChange={(event) => setMapping('terminal_id', event.target.value)}><option value="">Select terminal</option>{terminals.map((terminal) => <option key={terminal.id} value={terminal.id}>{terminal.name}{terminal.terminal_identifier ? ` · ${terminal.terminal_identifier}` : ''}</option>)}</select></div> : <p className="text-sm text-slate-700">Add a terminal before matching transactions to your business. <Link href="/dashboard?screen=setup" className="font-bold text-brand-accent">Add terminal</Link></p>}
            <p className="text-sm leading-5 text-slate-600">Map the columns. Header names are shown; statement rows are not displayed as a preview. Amount, date/time, and status are required.</p>
            <div className="grid gap-3 sm:grid-cols-2">{fields.map(([field, label, required]) => <label key={field} className="block text-sm font-semibold">{label}{required ? ' *' : ' (optional)'}<select aria-label={`${label} column`} className="mt-1 w-full rounded-xl border border-slate-300 p-3" required={required} value={mapping.column_mapping?.[field] || ''} onChange={(event) => setMapping(field, event.target.value)}><option value="">Do not map</option>{(message.headers || []).map((header, index) => <option key={`${header}-${index}`} value={header}>{header || `Column ${index + 1}`}</option>)}</select></label>)}</div>
            <Button type="button" disabled={busy || !mapping.terminal_id || !mapping.column_mapping?.amount || !mapping.column_mapping?.transaction_at || !mapping.column_mapping?.transaction_status} onClick={save}>Save mapping and process</Button></div>}
    </article>;
}
