import { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import { api, dateTime } from '../../lib/api';
import { Button, Card, ErrorNotice, Field, LoadingCard, Notice, StatusPill } from '../../Components/PosPilotUI';

export default function Edit({ mustVerifyEmail, status }) {
    const { auth } = usePage().props;
    const profileForm = useForm({ name: auth.user.name || '', email: auth.user.email || '' });
    const [accountData, setAccountData] = useState({ name: auth.user.name || '', email: auth.user.email || '' });
    const [accountSaving, setAccountSaving] = useState(false);
    const [accountSaved, setAccountSaved] = useState(false);
    const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });
    const [agent, setAgent] = useState(null);
    const [emailVerified, setEmailVerified] = useState(Boolean(auth?.user?.email_verified_at));
    const [agentData, setAgentData] = useState({ business_name: '', phone: '', country: 'Nigeria', currency: 'NGN', location: '' });
    const [sessions, setSessions] = useState([]);
    const [sessionsAvailable, setSessionsAvailable] = useState(true);
    const [loading, setLoading] = useState(true);
    const [agentError, setAgentError] = useState(null);
    const [securityError, setSecurityError] = useState(null);
    const [agentSaved, setAgentSaved] = useState(false);
    const [securitySaved, setSecuritySaved] = useState(false);

    useEffect(() => {
        let mounted = true;
        Promise.all([api('/api/agent/profile'), api('/api/security/sessions').catch((error) => ({ available: false, sessions: [], error }))]).then(([profile, sessionData]) => {
            if (!mounted) return;
            setAgent(profile); setAgentData({ business_name: profile.business_name || '', phone: profile.phone || '', country: profile.country || 'Nigeria', currency: profile.currency || 'NGN', location: profile.location || '' });
            const account = { name: profile.user?.name || auth?.user?.name || '', email: profile.user?.email || auth?.user?.email || '' };
            setAccountData(account);
            profileForm.setData(account);
            setEmailVerified(Boolean(profile.user?.email_verified_at));
            setSessions(sessionData.sessions || []); setSessionsAvailable(sessionData.available !== false);
            if (sessionData.error) setSecurityError(sessionData.error);
        }).catch(setAgentError).finally(() => mounted && setLoading(false));
        return () => { mounted = false; };
    }, []);

    function saveAccount(event) {
        event.preventDefault();
        setAccountSaving(true);
        setAccountSaved(false);
        profileForm.clearErrors();
        router.patch('/profile', accountData, {
            preserveScroll: true,
            onError: (errors) => profileForm.setError(errors),
            onSuccess: () => setAccountSaved(true),
            onFinish: () => setAccountSaving(false),
        });
    }
    function savePassword(event) { event.preventDefault(); setSecuritySaved(false); passwordForm.put('/password', { preserveScroll: true, onSuccess: () => { passwordForm.reset(); setSecuritySaved(true); } }); }
    async function saveBusiness(event) {
        event.preventDefault(); setAgentError(null); setAgentSaved(false);
        try { const result = await api('/api/agent/profile', { method: 'PATCH', body: agentData }); setAgent(result); setAgentSaved(true); }
        catch (error) { setAgentError(error); }
    }
    async function revokeSessions() {
        setSecurityError(null);
        try { await api('/api/security/sessions/others', { method: 'DELETE', body: {} }); const result = await api('/api/security/sessions'); setSessions(result.sessions || []); }
        catch (error) { setSecurityError(error); }
    }

    return <AppShell title="Profile and security"><div className="ops-page"><header className="ops-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> YOUR ACCOUNT</span><h1>Profile and security</h1><p>Keep your business details up to date and protect access to your account.</p></div></header>
        {status && <div className="mb-5"><Notice tone="success">{status}</Notice></div>}{loading ? <LoadingCard label="Loading profile and security information…" /> : <div className="space-y-5">
            <Card><h2 className="text-lg font-extrabold">Business profile</h2><p className="mt-1 text-sm text-slate-600">These details help identify your POS business. No provider login details are needed.</p>{agentError && <div className="mt-4"><ErrorNotice error={agentError} /></div>}{agentSaved && <div className="mt-4"><Notice tone="success">Business details saved.</Notice></div>}<form onSubmit={saveBusiness} className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Business name" name="business_name" value={agentData.business_name} onChange={(event) => setAgentData({ ...agentData, business_name: event.target.value })} required /><Field label="Phone (optional)" name="phone" type="tel" value={agentData.phone} onChange={(event) => setAgentData({ ...agentData, phone: event.target.value })} /><Field label="Location or branch (optional)" name="location" value={agentData.location} onChange={(event) => setAgentData({ ...agentData, location: event.target.value })} /><Field label="Country" name="country" value={agentData.country} onChange={(event) => setAgentData({ ...agentData, country: event.target.value })} /><Field label="Currency" name="currency" value={agentData.currency} onChange={(event) => setAgentData({ ...agentData, currency: event.target.value.toUpperCase() })} maxLength={3} /><div className="sm:col-span-2"><Button type="submit">Save business details</Button></div></form></Card>
            <Card><h2 className="text-lg font-extrabold">Sign-in information</h2><div className="mt-3 flex flex-wrap items-center gap-3"><StatusPill tone={emailVerified ? 'good' : 'warn'}>{emailVerified ? 'Email verified' : 'Email not verified'}</StatusPill>{!emailVerified && <a href="/verify-email" className="text-sm font-bold text-brand-accent underline underline-offset-2">Verify email</a>}</div><form onSubmit={saveAccount} className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Your name" name="name" value={accountData.name} onChange={(event) => setAccountData((current) => ({ ...current, name: event.target.value }))} error={profileForm.errors.name} required /><Field label="Email address" name="email" type="email" value={accountData.email} onChange={(event) => setAccountData((current) => ({ ...current, email: event.target.value }))} error={profileForm.errors.email} required /><div className="sm:col-span-2">{mustVerifyEmail && !emailVerified && <p className="mb-3 text-sm text-amber-900">Please verify your email before using financial features.</p>}<Button type="submit" disabled={accountSaving}>{accountSaving ? 'Saving…' : 'Save sign-in information'}</Button>{accountSaved && <span className="ml-3 text-sm font-semibold text-brand-accent">Saved</span>}</div></form></Card>
            <Card><h2 className="text-lg font-extrabold">Change password</h2><p className="mt-1 text-sm text-slate-600">You may be asked to confirm your current password again before a sensitive change.</p>{Object.keys(passwordForm.errors).length > 0 && <div className="mt-4"><Notice tone="error">{Object.values(passwordForm.errors).join(' ')}</Notice></div>}{securitySaved && <div className="mt-4"><Notice tone="success">Password updated. Other signed-in sessions may have been ended.</Notice></div>}<form onSubmit={savePassword} className="mt-4 grid gap-4 sm:grid-cols-2"><Field label="Current password" name="current_password" type="password" autoComplete="current-password" value={passwordForm.data.current_password} onChange={(event) => passwordForm.setData('current_password', event.target.value)} required /><span className="hidden sm:block" /><Field label="New password" name="password" type="password" autoComplete="new-password" value={passwordForm.data.password} onChange={(event) => passwordForm.setData('password', event.target.value)} error={passwordForm.errors.password} required /><Field label="Confirm new password" name="password_confirmation" type="password" autoComplete="new-password" value={passwordForm.data.password_confirmation} onChange={(event) => passwordForm.setData('password_confirmation', event.target.value)} required /><div className="sm:col-span-2"><Button type="submit" disabled={passwordForm.processing}>Update password</Button></div></form></Card>
            <Card><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-lg font-extrabold">Signed-in devices</h2><p className="mt-1 text-sm text-slate-600">Review active sessions and sign out other devices if something looks unfamiliar.</p></div><Button type="button" variant="secondary" disabled={!sessionsAvailable || sessions.filter((item) => !item.current).length === 0} onClick={revokeSessions}>Sign out other devices</Button></div>{securityError && <div className="mt-4"><ErrorNotice error={securityError} /></div>}{!sessionsAvailable ? <p className="mt-4 text-sm text-slate-600">Device session listing is not available in this environment.</p> : sessions.length ? <div className="mt-4 divide-y divide-slate-100">{sessions.map((session) => <div key={session.id} className="flex flex-col justify-between gap-2 py-3 sm:flex-row sm:items-center"><div><p className="font-bold">{session.current ? 'This device' : session.user_agent || 'Other device'}{session.current ? ' · Current session' : ''}</p><p className="mt-1 text-sm text-slate-600">{session.ip_address || 'IP unavailable'} · Active {dateTime(session.last_active_at)}</p></div>{session.current && <span className="text-sm font-bold text-brand-accent">You are here</span>}</div>)}</div> : <p className="mt-4 text-sm text-slate-600">No active device information is available.</p>}</Card>
        </div>}</div></AppShell>;
}
