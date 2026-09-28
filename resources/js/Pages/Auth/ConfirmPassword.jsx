import GuestLayout from '../../Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Field, Notice } from '../../Components/PosPilotUI';

export default function ConfirmPassword({ googleReauthenticationUrl }) {
    const form = useForm({ password: '' });
    function submit(event) { event.preventDefault(); form.post('/confirm-password', { onFinish: () => form.reset('password') }); }
    return <GuestLayout><Head title="Confirm your identity" /><h1 className="text-2xl font-black">Confirm it’s you</h1><p className="mt-2 text-sm leading-6 text-slate-600">For your security, please sign in again before changing provider access or sensitive account settings.</p>{form.errors.password && <div className="mt-4"><Notice tone="error">{form.errors.password}</Notice></div>}<form onSubmit={submit} className="mt-6 space-y-4"><Field label="Account password" name="password" type="password" autoComplete="current-password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} required autoFocus /><Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? 'Checking…' : 'Confirm password'}</Button></form>{googleReauthenticationUrl && <a href={googleReauthenticationUrl} className="mt-4 flex min-h-12 items-center justify-center rounded-xl border border-brand-line font-bold text-slate-700">Re-authenticate with Google</a>}<p className="mt-4 text-center"><Link href="/profile" className="text-sm font-semibold text-brand-accent">Back to account</Link></p></GuestLayout>;
}
