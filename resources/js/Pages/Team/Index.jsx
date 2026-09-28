import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import { Button, Card, EmptyState, Field, FormErrorList, LoadingCard, Notice, PageHeading, SelectField } from '../../Components/PosPilotUI';
import { api } from '../../lib/api';

export default function TeamIndex({ businessName }) {
    const [data, setData] = useState(null);
    const [activity, setActivity] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [formErrors, setFormErrors] = useState({});
    const [email, setEmail] = useState('');
    const [role, setRole] = useState('attendant');

    async function load() {
        setLoading(true);
        setError('');
        try { const [team, shifts] = await Promise.all([api('/api/team/members'), api('/api/team/activity')]); setData(team); setActivity(shifts.data || []); } catch (requestError) { setError(requestError.message); } finally { setLoading(false); }
    }

    useEffect(() => { load(); }, []);

    async function invite(event) {
        event.preventDefault(); setNotice(''); setError(''); setFormErrors({});
        try { await api('/api/team/invitations', { method: 'POST', body: { email, role } }); setEmail(''); setNotice('Invitation sent. It expires in seven days.'); await load(); }
        catch (requestError) { setError(requestError.message); setFormErrors(requestError.errors || {}); }
    }

    async function updateMember(member, changes) {
        setError('');
        try { await api(`/api/team/members/${member.id}`, { method: 'PATCH', body: changes }); setNotice('Team member updated.'); await load(); }
        catch (requestError) { setError(requestError.message); }
    }

    async function saveAssignments(member, terminalIds) {
        setError('');
        try { await api(`/api/team/members/${member.id}/terminals`, { method: 'PUT', body: { terminal_ids: terminalIds } }); setNotice('Terminal assignments saved.'); await load(); }
        catch (requestError) { setError(requestError.message); }
    }

    async function cancelInvitation(id) {
        setError('');
        try { await api(`/api/team/invitations/${id}`, { method: 'DELETE' }); setNotice('Invitation cancelled.'); await load(); }
        catch (requestError) { setError(requestError.message); }
    }

    return <AppShell title="Team">
        <div className="mx-auto max-w-6xl space-y-6">
            <PageHeading eyebrow={businessName} title="Team and terminals" description="Invite people to the business and choose the terminals they can operate." />
            {notice && <Notice tone="success" role="status">{notice}</Notice>}
            {error && <Notice tone="error" role="alert">{error} <button type="button" className="ml-2 underline" onClick={load}>Try again</button></Notice>}
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <Card>
                    <div className="mb-5 flex items-start justify-between gap-4"><div><h2 className="text-lg font-bold text-brand-ink">People</h2><p className="mt-1 text-sm text-slate-600">Set a role and keep access current.</p></div><span className="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-900">Owner controls</span></div>
                    {loading && <LoadingCard label="Loading team members…" />}
                    {!loading && data?.members?.length === 0 && <EmptyState title="Your team is ready to grow" description="Invite a trusted manager or attendant to start assigning terminals." />}
                    {!loading && data?.members?.length > 0 && <div className="space-y-4">{data.members.map((member) => {
                        const assigned = member.terminals.map((terminal) => terminal.id);
                        return <article key={member.id} className="rounded-xl border border-brand-line p-4">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><div><h3 className="font-bold text-brand-ink">{member.name}</h3><p className="text-sm text-slate-600">{member.email}</p></div><div className="flex flex-wrap items-center gap-2"><SelectField label="Role" name={`role-${member.id}`} value={member.role} onChange={(event) => updateMember(member, { role: event.target.value })}><option value="manager">Manager</option><option value="attendant">Attendant</option></SelectField><Button type="button" variant={member.is_active ? 'secondary' : 'primary'} onClick={() => updateMember(member, { is_active: !member.is_active })}>{member.is_active ? 'Deactivate' : 'Reactivate'}</Button></div></div>
                            <fieldset className="mt-4 border-t border-brand-line pt-4"><legend className="text-sm font-bold text-brand-ink">Terminal access</legend>{data.terminals.length ? <div className="mt-2 flex flex-wrap gap-2">{data.terminals.map((terminal) => <label key={terminal.id} className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-brand-line px-3 text-sm"><input type="checkbox" checked={assigned.includes(terminal.id)} onChange={(event) => saveAssignments(member, event.target.checked ? [...assigned, terminal.id] : assigned.filter((id) => id !== terminal.id))} />{terminal.name}</label>)}</div> : <p className="mt-2 text-sm text-slate-600">Add an active terminal in Providers before assigning access.</p>}</fieldset>
                        </article>;
                    })}</div>}
                    {!loading && data?.invitations?.length > 0 && <div className="mt-6 border-t border-brand-line pt-5"><h3 className="font-bold text-brand-ink">Pending invitations</h3><ul className="mt-3 divide-y divide-brand-line">{data.invitations.map((invitation) => <li key={invitation.id} className="flex min-h-14 items-center justify-between gap-4 py-2 text-sm"><span><strong>{invitation.email}</strong><span className="ml-2 capitalize text-slate-600">{invitation.role}</span></span><Button type="button" variant="secondary" onClick={() => cancelInvitation(invitation.id)}>Cancel</Button></li>)}</ul></div>}
                </Card>
                <Card>
                    <h2 className="text-lg font-bold text-brand-ink">Invite someone</h2><p className="mt-1 text-sm leading-6 text-slate-600">They’ll receive an invitation link and set up their POSPilot sign-in.</p>
                    <form onSubmit={invite} className="mt-5 space-y-4"><Field label="Work email" name="email" type="email" autoComplete="email" spellCheck={false} required value={email} onChange={(event) => setEmail(event.target.value)} error={formErrors.email?.[0]} /><SelectField label="Role" name="role" value={role} onChange={(event) => setRole(event.target.value)}><option value="attendant">Attendant</option><option value="manager">Manager</option></SelectField><FormErrorList errors={formErrors} /><Button type="submit" className="w-full">Send invitation</Button></form>
                    <div className="mt-6 rounded-xl bg-brand-canvas p-4 text-sm leading-6 text-slate-700"><strong className="text-brand-ink">Access at a glance</strong><p className="mt-1">Attendants can work assigned shifts. Managers can review operations, expenses and reconciliation. Only owners connect providers and manage business settings.</p></div>
                </Card>
            </div>
            <Card><div className="flex flex-wrap items-end justify-between gap-3"><div><h2 className="text-lg font-bold text-brand-ink">Staff and terminal activity</h2><p className="mt-1 text-sm text-slate-600">Recent shifts, submitted cash and reported issues across your business.</p></div><Button type="button" variant="secondary" onClick={load} disabled={loading}>Refresh activity</Button></div>
                {loading && <div className="mt-4"><LoadingCard label="Loading staff activity…" /></div>}
                {!loading && activity.length === 0 && <div className="mt-4"><EmptyState title="No staff shifts yet" description="Attendant shift activity will appear here as your team starts using assigned terminals." /></div>}
                {!loading && activity.length > 0 && <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[42rem] text-left text-sm"><thead><tr className="border-b border-brand-line text-slate-600"><th className="pb-3">Staff member</th><th className="pb-3">Terminal</th><th className="pb-3">Started</th><th className="pb-3">Status</th><th className="pb-3">Closing cash</th><th className="pb-3">Issues</th></tr></thead><tbody>{activity.map((shift) => <tr key={shift.id} className="border-b border-brand-line"><td className="py-3 font-semibold">{shift.staff}</td><td>{shift.terminal}</td><td>{new Date(shift.started_at).toLocaleString()}</td><td className="capitalize">{shift.status}</td><td>{shift.closing_cash === null ? '—' : `₦${Number(shift.closing_cash).toLocaleString('en-NG')}`}</td><td>{shift.issues?.map((issue) => <span key={issue.id} className="mr-2 inline-flex items-center gap-2 py-1">{issue.subject} · {issue.status}{issue.status === 'open' && <Button type="button" variant="secondary" onClick={async () => { try { await api(`/api/team/issues/${issue.id}/resolve`, { method: 'POST', body: {} }); setNotice('Issue marked resolved.'); await load(); } catch (requestError) { setError(requestError.message); } }}>Resolve</Button>}</span>)}</td></tr>)}</tbody></table></div>}
            </Card>
        </div>
    </AppShell>;
}
