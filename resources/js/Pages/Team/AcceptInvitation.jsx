import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';
import { Button, Field, Notice } from '../../Components/PosPilotUI';

export default function AcceptInvitation({ token, email, role, businessName, authenticated, accountExists }) {
    const form = useForm({ name: '', password: '', password_confirmation: '' });
    function submit(event) { event.preventDefault(); form.post(`/team/invitations/${token}/accept`); }

    return <GuestLayout><Head title="Join a POSPilot team" /><div className="space-y-4"><p className="text-sm font-bold uppercase tracking-wide text-brand-accent">Team invitation</p><h1 className="text-2xl font-black text-brand-ink">Join {businessName}</h1><p className="text-sm leading-6 text-slate-600">You’re invited as a <strong className="capitalize">{role}</strong>. The invitation is for {email}.</p>
        {accountExists && !authenticated ? <Notice tone="info">This email already has a POSPilot account. <Link href="/login" className="font-bold underline">Sign in</Link>, then return to this invitation to accept it.</Notice> : <form onSubmit={submit} className="space-y-4">{!authenticated && <><Field label="Your name" name="name" autoComplete="name" required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} /><Field label="Create password" name="password" type="password" autoComplete="new-password" required value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} /><Field label="Confirm password" name="password_confirmation" type="password" autoComplete="new-password" required value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} error={form.errors.password_confirmation} /></>}{form.errors.token && <Notice tone="error">{form.errors.token}</Notice>}<Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? 'Joining…' : 'Accept invitation'}</Button></form>}
    </div></GuestLayout>;
}
