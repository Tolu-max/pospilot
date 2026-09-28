import GuestLayout from '../../Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Field, Notice } from '../../Components/PosPilotUI';

export default function ForgotPassword({ status }) {
    const form = useForm({ email: '' });
    function submit(event) { event.preventDefault(); form.post('/forgot-password'); }
    return <GuestLayout><Head title="Reset password" /><h1 className="text-2xl font-black">Reset your password</h1><p className="mt-2 text-sm leading-6 text-slate-600">Enter the email address for your account. For privacy, the confirmation message is the same whether or not an account matches.</p>{status && <div className="mt-4"><Notice tone="success">{status}</Notice></div>}{form.errors.email && <div className="mt-4"><Notice tone="error">{form.errors.email}</Notice></div>}<form onSubmit={submit} className="mt-6 space-y-4"><Field label="Email address" name="email" type="email" autoComplete="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} required autoFocus /><Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? 'Sending…' : 'Send reset instructions'}</Button></form><p className="mt-4 text-center"><Link href="/login" className="min-h-11 inline-flex items-center font-bold text-brand-accent">Back to sign in</Link></p></GuestLayout>;
}
