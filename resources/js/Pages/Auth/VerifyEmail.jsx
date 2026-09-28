import GuestLayout from '../../Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Notice } from '../../Components/PosPilotUI';

export default function VerifyEmail({ status }) {
    const form = useForm({});
    function submit(event) { event.preventDefault(); form.post('/email/verification-notification'); }
    return <GuestLayout><Head title="Verify your email" /><h1 className="text-2xl font-black">Check your email</h1><p className="mt-3 text-sm leading-6 text-slate-600">A verification link is needed to protect your account and unlock your financial workspace.</p>{status === 'verification-link-failed' && <div className="mt-4"><Notice tone="error">We couldn’t send the verification link right now. Please try again shortly.</Notice></div>}{status === 'verification-link-sent' && <div className="mt-4"><Notice tone="success">A fresh verification link has been sent.</Notice></div>}<form onSubmit={submit} className="mt-6"><Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? 'Sending…' : 'Resend verification email'}</Button></form><Link href="/logout" method="post" as="button" className="mt-4 min-h-11 w-full text-sm font-bold text-slate-600">Sign out</Link></GuestLayout>;
}
