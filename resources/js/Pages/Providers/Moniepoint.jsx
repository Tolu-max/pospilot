import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import { api } from '../../lib/api';
import { Button, Card, ErrorNotice, Field, LoadingCard, Notice, StatusPill } from '../../Components/PosPilotUI';
import ProviderLogo from '../../Components/ProviderLogo';
import { trackSafeEvent } from '../../lib/analytics';

const blank = { api_key: '', business_id: '' };

export default function Moniepoint() {
    const [status, setStatus] = useState(null);
    const [values, setValues] = useState(blank);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [message, setMessage] = useState('');
    const [confirmDisconnect, setConfirmDisconnect] = useState(false);
    async function refresh() {
        setError(null);
        try { setStatus(await api('/api/providers/moniepoint/connection')); }
        catch (requestError) { setError(requestError); }
        finally { setLoading(false); }
    }
    useEffect(() => { refresh(); }, []);
    const set = (key) => (event) => setValues((current) => ({ ...current, [key]: event.target.value }));

    async function save(event) {
        event.preventDefault(); setBusy(true); setError(null); setMessage(''); trackSafeEvent('provider_connection_started');
        try {
            const result = await api('/api/providers/moniepoint/connection', { method: 'PUT', body: values });
            setStatus(result); setValues(blank); setMessage('Connection details saved securely. POSPilot will not show them again.');
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }
    async function action(kind) {
        setBusy(true); setError(null); setMessage('');
        try {
            const result = kind === 'test'
                ? await api('/api/providers/moniepoint/connection/test', { method: 'POST', body: {} })
                : await api('/api/providers/moniepoint/connection', { method: 'DELETE', body: {} });
            setStatus(result.connection || result);
            setMessage(kind === 'test' ? 'Connection test completed.' : 'POSPilot access was disconnected. Your transaction history is unchanged.');
            if (kind === 'disconnect') { setValues(blank); setConfirmDisconnect(false); }
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }

    return <AppShell title="Moniepoint connection"><div className="ops-page"><header className="ops-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> PROVIDER CONNECTION</span><h1 className="provider-page-title"><ProviderLogo provider="Moniepoint" size="lg" />Connect Moniepoint</h1><p>Use the integration credentials from your Moniepoint Business setup. POSPilot never asks for your dashboard password, PIN or OTP.</p></div></header>
        {error && <div className="mb-5"><ErrorNotice error={error} /></div>}{message && <div className="mb-5"><Notice tone="success">{message}</Notice></div>}{status?.connected && <div className="mb-5"><Notice tone="info">Provider API access was verified by introspection. POSPilot has not verified a historical transaction API or webhook contract, so direct transactions are not syncing.</Notice></div>}
        {loading ? <LoadingCard label="Checking connection statusâ€¦" /> : <div className="grid gap-5 lg:grid-cols-5"><Card className="lg:col-span-2"><div className="flex items-center justify-between gap-2"><h2 className="text-lg font-extrabold">Connection status</h2><StatusPill tone={status?.connected ? 'good' : 'neutral'}>{status?.connected ? 'Connected' : status?.status || 'Not connected'}</StatusPill></div><dl className="mt-5 space-y-4 text-sm"><Row label="Connection type" value={status?.connection_type || 'Not connected'} /><Row label="Environment" value={status?.environment || 'Not verified'} /><Row label="Business" value={status?.business_name || 'Not verified'} /><Row label="Granted scopes" value={(status?.granted_scopes || []).join(', ') || 'Not reported'} /><Row label="POSPilot terminals" value={status?.terminal_count ?? 0} /><Row label="Last verified" value={status?.last_synced_at ? new Date(status.last_synced_at).toLocaleString('en-NG') : 'No sync yet'} /><Row label="Webhook ingestion" value="Disabled pending provider verification" /></dl>{status?.error && <p className="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-950">Status note: {status.error}</p>}<div className="mt-5 flex flex-wrap gap-2"><Button type="button" variant="secondary" disabled={busy || !status?.configured} onClick={() => action('test')}>Test connection</Button>{status?.configured && <Button type="button" variant="danger" disabled={busy} onClick={() => setConfirmDisconnect(true)}>Disconnect</Button>}</div>{confirmDisconnect && <div className="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4" role="alert"><p className="text-sm leading-6 text-amber-950">Disconnect POSPilot from Moniepoint? Existing financial records will stay in POSPilot.</p><div className="mt-3 flex flex-wrap gap-2"><Button type="button" variant="danger" disabled={busy} onClick={() => action('disconnect')}>{busy ? 'Disconnectingâ€¦' : 'Yes, disconnect'}</Button><Button type="button" variant="secondary" disabled={busy} onClick={() => setConfirmDisconnect(false)}>Keep connected</Button></div></div>}</Card>
            <Card className="lg:col-span-3"><h2 className="text-lg font-extrabold">Integration credentials</h2><p className="mt-2 text-sm leading-6 text-slate-600">Use the API key issued for your Moniepoint Business. POSPilot checks its environment, authorized business, and granted scopes. The API key is encrypted and write-only.</p><form onSubmit={save} className="mt-5 space-y-4"><Field label="Business ID" name="business_id" inputMode="numeric" value={values.business_id} onChange={set('business_id')} required error={error?.errors?.business_id?.[0]} /><Field label="Integration API key" name="api_key" type="password" autoComplete="new-password" value={values.api_key} onChange={set('api_key')} required error={error?.errors?.api_key?.[0]} /><p className="text-xs leading-5 text-slate-500">Credentials are sent only to POSPilot's secure backend. They are never displayed in a response after saving. Recent password confirmation is required.</p><Button type="submit" disabled={busy}>{busy ? 'Saving securelyâ€¦' : 'Save integration details'}</Button></form></Card></div>}
        <section className="mt-5 rounded-2xl border border-brand-line bg-white p-5 shadow-sm" aria-labelledby="statement-automation-title"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 id="statement-automation-title" className="text-lg font-extrabold">Automatic email statements</h2><p className="mt-1 text-sm text-slate-600">Gmail connection is not available yet.</p></div><StatusPill tone="neutral">Not enabled</StatusPill></div><p className="mt-3 text-sm leading-6 text-slate-600">Before POSPilot asks for Gmail access, we need a verified provider statement format and Googleâ€™s required production approvals. Moniepoint currently documents statement exports as Excel or PDF, but this repository does not contain a verified file schema or a provider email-delivery setup.</p><p className="mt-3 text-sm leading-6 text-slate-600">A future Gmail grant would allow broad message-reading access. Provider sender and search rules would limit what POSPilot processes, but would not technically limit Googleâ€™s OAuth permission to only those messages. No Gmail access is being requested or stored now.</p></section>
        <div className="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950"><strong>Keep credentials private.</strong> Use only official integration credentials. Never paste your personal Moniepoint password, transaction PIN, card details or one-time code.</div>
        </div>
    </AppShell>;
}

function Row({ label, value }) { return <div><dt className="text-slate-500">{label}</dt><dd className="mt-1 break-words font-bold text-brand-ink">{value}</dd></div>; }
