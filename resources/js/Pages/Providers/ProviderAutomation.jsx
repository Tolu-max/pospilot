import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import ProviderLogo from '../../Components/ProviderLogo';
import FullPageLoader from '../../Components/FullPageLoader';
import { api } from '../../lib/api';
import { Button, Card, ErrorNotice, LoadingCard, Notice, StatusPill } from '../../Components/PosPilotUI';
import { trackSafeEvent } from '../../lib/analytics';

const supported = ['opay', 'palmpay', 'moniepoint'];
const statusLabels = {
    connected: 'Connected', sync_queued: 'Sync queued', syncing: 'Syncing statements', sync_error: 'Needs attention',
    permission_expired: 'Reconnect Gmail', disconnected: 'Not connected', needs_setup: 'Needs setup',
    needs_setup_expired: 'Setup expired', unsupported_format: 'Could not read this PDF',
    pdf_scanned_unsupported: 'Scanned PDF not supported', pdf_page_limit_exceeded: 'PDF is too long to process',
    pdf_parse_failed: 'PDF could not be read', unsupported_schema: 'Statement format needs setup',
    rejected: 'Attachment too large', failed: 'Could not process statement', duplicate_attachment: 'Duplicate statement attachment',
};

export default function ProviderAutomation() {
    const [status, setStatus] = useState(null);
    const [providers, setProviders] = useState([]);
    const [terminals, setTerminals] = useState([]);
    const [messages, setMessages] = useState([]);
    const [selected, setSelected] = useState([]);
    const [rules, setRules] = useState({});
    const [mappings, setMappings] = useState({});
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [redirectingToGmail, setRedirectingToGmail] = useState(false);
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);

    async function load(quiet = false) {
        if (!quiet) setLoading(true);
        try {
            const [connection, available, terminalList, statements] = await Promise.all([
                api('/api/gmail/connection'), api('/api/providers'), api('/api/terminals'), api('/api/gmail/statements'),
            ]);
            setStatus(connection);
            setProviders((available.data || []).filter((item) => supported.includes(item.slug)));
            setTerminals(terminalList.data || []);
            setMessages(statements.data || []);
            setSelected(connection.selected_provider_slugs || []);
            setRules(connection.provider_rules || {});
            setError(null);
        } catch (requestError) {
            setError(requestError);
        } finally {
            if (!quiet) setLoading(false);
        }
    }

    useEffect(() => { load(); }, []);

    useEffect(() => {
        if (!['sync_queued', 'syncing'].includes(status?.status)) return undefined;
        const interval = window.setInterval(() => load(true), 5000);
        return () => window.clearInterval(interval);
    }, [status?.status]);

    function toggleProvider(slug) {
        setSelected((current) => current.includes(slug) ? current.filter((item) => item !== slug) : [...current, slug]);
    }

    async function saveSetup(event) {
        event.preventDefault(); setBusy(true); setError(null); setNotice(null);
        try {
            await api('/api/gmail/connection/rules', { method: 'PUT', body: { selected_provider_slugs: selected, provider_rules: rules } });
            setNotice('Connection settings saved. POSPilot will check recent matching statements.');
            await load(true);
        } catch (requestError) {
            setError(requestError);
        } finally {
            setBusy(false);
        }
    }

    async function syncNow() {
        setBusy(true); setError(null); setNotice(null);
        try {
            trackSafeEvent('statement_sync_started');
            await api('/integrations/gmail/sync', { method: 'POST', body: {} });
            setNotice('Sync queued. POSPilot is checking matching statement emails.');
            await load(true);
        } catch (requestError) {
            setError(requestError);
        } finally {
            setBusy(false);
        }
    }

    async function importOlder() {
        setBusy(true); setError(null); setNotice(null);
        try {
            await api('/integrations/gmail/import-older', { method: 'POST', body: {} });
            setNotice('Older statement check complete.');
            await load(true);
        } catch (requestError) {
            setError(requestError);
        } finally {
            setBusy(false);
        }
    }

    async function saveMapping(message) {
        const form = mappings[message.id] || {};
        setBusy(true); setError(null); setNotice(null);
        try {
            const result = await api(`/api/gmail/statements/${message.id}/mapping`, {
                method: 'POST', body: { terminal_id: form.terminal_id, column_mapping: form.column_mapping || {} },
            });
            if (result.status === 'processed' && result.rows_imported > 0) trackSafeEvent('statement_imported');
            setNotice('Statement mapping saved and processed.');
            await load(true);
        } catch (requestError) {
            setError(requestError);
        } finally {
            setBusy(false);
        }
    }

    const setRule = (slug, value) => setRules((current) => ({ ...current, [slug]: { ...(current[slug] || {}), sender_email: value } }));
    const setMapping = (id, field, value) => setMappings((current) => ({
        ...current,
        [id]: { ...(current[id] || {}), column_mapping: { ...(current[id]?.column_mapping || {}), [field]: value } },
    }));
    const savedProviders = status?.selected_provider_slugs || [];
    const rulesPersisted = selected.length > 0 && selected.length === savedProviders.length && selected.every((slug) => savedProviders.includes(slug)
        && (status?.provider_rules?.[slug]?.sender_email || '') === (rules[slug]?.sender_email || ''));
    const monitoredProviders = selected.map((slug) => providers.find((provider) => provider.slug === slug)?.name).filter(Boolean);

    function beginGmailRedirect(event) {
        if (!status?.enabled || !status?.configured || !rulesPersisted) {
            event.preventDefault();
            setNotice('Choose the statement providers in Connection settings before connecting Gmail.');
            return;
        }
        setRedirectingToGmail(true);
    }

    return <AppShell title="Email Statements">{redirectingToGmail && <FullPageLoader label="Connecting Gmail" />}<div className="ops-page">
        <header className="ops-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> DATA SOURCES</span><h1>Email Statements</h1><p>Connect the inbox where you receive POS statements. POSPilot finds and processes matching statements after they arrive.</p></div></header>
        {error && <div className="mb-5"><ErrorNotice error={error} /></div>}
        {notice && <div className="mb-5"><Notice tone="success">{notice}</Notice></div>}
        {loading ? <LoadingCard label="Checking Gmail connection…" /> : <div className="space-y-5">
            {!status?.enabled && <Notice tone="info">Email statement connection is temporarily unavailable.</Notice>}
            <Card>
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-3"><ProviderLogo provider="Email Statements" size="md" /><div>
                        <h2 className="text-lg font-extrabold">Email Statements</h2>
                        <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-600">Request or send a statement from your provider app first. POSPilot then checks Gmail automatically for matching statements from OPay, PalmPay, and Moniepoint.</p>
                    </div></div>
                    <StatusPill tone={status?.connected ? 'good' : 'neutral'}>{statusLabels[status?.status] || 'Not connected'}</StatusPill>
                </div>
                {status?.connected ? <>
                    <div className="mt-5 grid gap-4 sm:grid-cols-3">
                        <div><p className="text-xs font-bold uppercase tracking-wide text-slate-500">Email</p><p className="mt-1 font-semibold text-brand-ink">{status.gmail_address_masked || 'Connected'}</p></div>
                        <div><p className="text-xs font-bold uppercase tracking-wide text-slate-500">Providers monitored</p><p className="mt-1 font-semibold text-brand-ink">{monitoredProviders.length ? monitoredProviders.join(' · ') : 'Choose in connection settings'}</p></div>
                        <div><p className="text-xs font-bold uppercase tracking-wide text-slate-500">Last synced</p><p className="mt-1 font-semibold text-brand-ink">{status.last_synced_at ? new Date(status.last_synced_at).toLocaleString() : 'Checking recent statements'}</p></div>
                    </div>
                    <div className="mt-5 flex flex-wrap gap-x-8 gap-y-3 border-t border-brand-line pt-4">
                        <p className="text-sm text-slate-600"><strong className="text-brand-ink">Statements imported:</strong> {status.statement_counts?.processed || 0}</p>
                        <p className="text-sm text-slate-600"><strong className="text-brand-ink">Transactions added:</strong> {status.statement_counts?.rows_imported || 0}</p>
                        {['sync_queued', 'syncing'].includes(status.status) && <p role="status" className="text-sm font-semibold text-brand-accent">{statusLabels[status.status]}…</p>}
                    </div>
                    {status.status === 'permission_expired' && <a className="secondary-button mt-4 inline-flex" href={status.configured && rulesPersisted ? '/integrations/gmail/connect' : undefined} onClick={beginGmailRedirect}>Reconnect Gmail</a>}
                </> : <>
                    <p className="mt-3 text-sm leading-6 text-slate-600">Connect Gmail so POSPilot can find POS statements sent to your inbox.</p>
                    <a className="primary-button mt-4 inline-flex" href={status?.enabled && status?.configured && rulesPersisted ? '/integrations/gmail/connect' : undefined} aria-disabled={!status?.enabled || !status?.configured || !rulesPersisted} aria-busy={redirectingToGmail} onClick={beginGmailRedirect}>{redirectingToGmail ? 'Connecting…' : 'Connect Gmail'}</a>
                </>}
            </Card>

            {status?.connected && messages.length > 0 && <Card>
                <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-lg font-extrabold">Statement activity</h2><p className="mt-1 text-sm text-slate-600">Statements are checked newest first. Unreadable or unmatched files wait here for setup.</p></div></div>
                <div className="mt-4 space-y-4">{messages.map((message) => <StatementItem key={message.id} message={message} terminals={terminals.filter((item) => item.provider?.slug === message.provider?.slug)} mapping={mappings[message.id] || {}} setMapping={(field, value) => field === 'terminal_id' ? setMappings((current) => ({ ...current, [message.id]: { ...(current[message.id] || {}), terminal_id: value } })) : setMapping(message.id, field, value)} save={() => saveMapping(message)} busy={busy} />)}</div>
            </Card>}

            <details className="rounded-2xl border border-brand-line bg-white p-5 shadow-sm">
                <summary className="cursor-pointer font-bold text-brand-ink">Connection settings</summary>
                <form onSubmit={saveSetup} className="mt-4 space-y-5">
                    <div><h2 className="font-extrabold">Choose statement providers</h2><p className="mt-1 text-sm text-slate-600">Choose all providers whose statements arrive in this inbox.</p>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">{providers.map((provider) => <label key={provider.slug} className="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border border-brand-line p-3 focus-within:ring-2 focus-within:ring-brand-accent"><input type="checkbox" checked={selected.includes(provider.slug)} onChange={() => toggleProvider(provider.slug)} className="h-5 w-5 rounded border-slate-300 text-brand-accent focus:ring-brand-accent" /><ProviderLogo provider={provider} size="sm" /><strong>{provider.name}</strong></label>)}</div>
                    </div>
                    <div><h2 className="font-extrabold">Optional sender match</h2><p className="mt-1 text-sm text-slate-600">Leave blank unless you have verified the sender from a real statement.</p>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">{providers.filter((provider) => selected.includes(provider.slug)).map((provider) => <label key={provider.slug} className="block text-sm font-semibold">{provider.name} sender email (optional)<input type="email" autoComplete="off" value={rules[provider.slug]?.sender_email || ''} onChange={(event) => setRule(provider.slug, event.target.value)} placeholder="Leave blank until verified" className="mt-1 w-full rounded-xl border border-slate-300 p-3 font-normal" /></label>)}</div>
                    </div>
                    <Button type="submit" disabled={busy || selected.length === 0 || !status?.enabled}>Save settings</Button>
                </form>
                {status?.connected && <div className="mt-5 flex flex-wrap gap-3 border-t border-brand-line pt-4">
                    <Button type="button" variant="secondary" onClick={syncNow} disabled={busy || ['sync_queued', 'syncing'].includes(status?.status) || !rulesPersisted || !status?.enabled}>Sync now</Button>
                    <Button type="button" variant="secondary" onClick={importOlder} disabled={busy || ['sync_queued', 'syncing'].includes(status?.status) || !rulesPersisted || !status?.enabled}>Check older statements</Button>
                    <Button type="button" variant="danger" onClick={() => router.delete('/integrations/gmail')} disabled={busy}>Disconnect Gmail</Button>
                </div>}
                <details className="mt-4 rounded-xl bg-slate-50 p-4">
                    <summary className="cursor-pointer text-sm font-bold">Learn more about access and privacy</summary>
                    <p className="mt-3 text-sm leading-6 text-slate-600">Google grants the restricted <code>gmail.readonly</code> permission, which can allow viewing Gmail messages and settings broadly. POSPilot’s statement workflow searches only recent emails matching the providers you select and retains the fields needed to identify and process statement attachments. Unrelated email content is not used for statement processing.</p>
                    <p className="mt-2 text-sm leading-6 text-slate-600">Google OAuth is in Testing mode, so only listed test users can connect and their refresh access expires after about seven days. General Gmail access remains subject to Google’s restricted-scope production verification. Direct OPay and Moniepoint transaction sync is unavailable in this release.</p>
                </details>
            </details>
        </div>}
        <p className="mt-5 text-sm text-slate-600">POSPilot never asks for your provider password, transaction PIN, or OTP. <Link href="/privacy" className="font-bold text-brand-accent">Read our privacy information</Link>.</p>
    </div></AppShell>;
}

function StatementItem({ message, terminals, mapping, setMapping, save, busy }) {
    const fields = [
        ['amount', 'Amount', true], ['transaction_at', 'Date and time', true], ['transaction_status', 'Status', true],
        ['external_reference', 'Transaction reference', false], ['provider_fee', 'Provider fee', false], ['customer_charge', 'Customer charge', false],
        ['transaction_type', 'Transaction type', false], ['merchant_identifier', 'Merchant ID', false], ['business_identifier', 'Business ID', false],
        ['terminal_identifier', 'Terminal ID or serial', false], ['provider_account_identifier', 'Provider account identifier', false], ['settlement_reference', 'Settlement reference', false],
    ];
    return <article className="rounded-xl border border-brand-line p-4">
        <div className="flex flex-wrap items-center justify-between gap-3"><div className="flex items-center gap-2"><ProviderLogo provider={message.provider} size="sm" /><strong>{message.provider?.name || 'Provider'} statement</strong></div><StatusPill tone={message.status === 'processed' ? 'good' : message.status === 'needs_setup' ? 'warning' : 'neutral'}>{statusLabels[message.failure_code] || statusLabels[message.status] || 'Statement update'}</StatusPill></div>
        <p className="mt-2 text-sm text-slate-600">{message.file_type?.toUpperCase()} · {message.received_at ? new Date(message.received_at).toLocaleString() : 'Time not available'}{message.status === 'processed' ? ` · ${message.rows_imported} imported · ${message.rows_duplicate} already counted · ${message.rows_failed} skipped` : ''}</p>
        {message.status === 'needs_setup' && <p className="mt-2 text-sm text-slate-700">We found a {message.provider?.name || 'provider'} statement that needs setup.{message.masked_account_identifier ? ` Account: ${message.masked_account_identifier}` : ' We could not confidently match its account or terminal.'}</p>}
        {message.status === 'duplicate_attachment' && <p className="mt-2 text-sm text-slate-600">This file matches a statement already checked. POSPilot did not import it again.</p>}
        {message.failure_code === 'pdf_scanned_unsupported' && <p className="mt-2 text-sm text-slate-600">This PDF contains no extractable text, so POSPilot cannot safely read its transactions yet.</p>}
        {message.status === 'unsupported_schema' && <p className="mt-2 text-sm text-slate-600">POSPilot could not recognize the table layout. No transactions were imported.</p>}
        {message.can_map && <div className="mt-4 space-y-4">
            {terminals.length ? <div><p className="text-sm font-bold">Choose the POS terminal for this statement</p><select aria-label="Destination terminal" className="mt-2 w-full rounded-xl border border-slate-300 p-3" value={mapping.terminal_id || ''} onChange={(event) => setMapping('terminal_id', event.target.value)}><option value="">Select terminal</option>{terminals.map((terminal) => <option key={terminal.id} value={terminal.id}>{terminal.name}{terminal.terminal_identifier ? ` · ${terminal.terminal_identifier}` : ''}</option>)}</select></div> : <p className="text-sm text-slate-700">Add this provider’s POS terminal before importing. <Link href="/dashboard?screen=setup" className="font-bold text-brand-accent">Add terminal</Link></p>}
            <p className="text-sm leading-5 text-slate-600">Match the statement headings to each field. POSPilot does not show statement rows here.</p>
            <div className="grid gap-3 sm:grid-cols-2">{fields.map(([field, label, required]) => <label key={field} className="block text-sm font-semibold">{label}{required ? ' *' : ' (optional)'}<select aria-label={`${label} column`} className="mt-1 w-full rounded-xl border border-slate-300 p-3" required={required} value={mapping.column_mapping?.[field] || ''} onChange={(event) => setMapping(field, event.target.value)}><option value="">Do not map</option>{(message.headers || []).map((header, index) => <option key={`${header}-${index}`} value={header}>{header || `Column ${index + 1}`}</option>)}</select></label>)}</div>
            <Button type="button" disabled={busy || !mapping.terminal_id || !mapping.column_mapping?.amount || !mapping.column_mapping?.transaction_at || !mapping.column_mapping?.transaction_status} onClick={save}>Save mapping and import</Button>
        </div>}
    </article>;
}
