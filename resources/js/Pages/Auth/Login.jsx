import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import GoogleMark from '../../Components/GoogleMark';
import FullPageLoader from '../../Components/FullPageLoader';
import { Button, Field, Notice } from '../../Components/PosPilotUI';
import GuestLayout from '../../Layouts/GuestLayout';

export default function Login({ status, canResetPassword }) {
    const form = useForm({ email: '', password: '', remember: false });
    const [redirectingToGoogle, setRedirectingToGoogle] = useState(false);

    function submit(event) {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    }

    return (
        <GuestLayout>
            {redirectingToGoogle && <FullPageLoader label="Connecting to Google" />}
            <Head title="Sign in" />
            <p className="mb-2 text-xs font-bold uppercase tracking-[0.12em] text-brand-accent">Welcome back</p>
            <h1 className="text-2xl font-black">Sign in to POSPilot</h1>
            <p className="mt-2 text-sm leading-6 text-slate-600">Pick up where you left off and get a clear view of your POS business records.</p>

            {status && <div className="mt-5"><Notice tone="success">{status}</Notice></div>}

            <a href="/auth/google/redirect" onClick={() => setRedirectingToGoogle(true)} aria-busy={redirectingToGoogle} className="mt-6 flex min-h-12 w-full items-center justify-center gap-3 rounded-xl border border-brand-line bg-white px-4 font-bold text-slate-700 transition-colors hover:bg-brand-canvas focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent focus-visible:ring-offset-2">
                <GoogleMark />
                Continue with Google
            </a>

            <div className="my-6 flex items-center gap-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                <span className="h-px flex-1 bg-slate-200" />
                <span>Or use email</span>
                <span className="h-px flex-1 bg-slate-200" />
            </div>

            <form onSubmit={submit} aria-busy={form.processing} className="space-y-5">
                <Field label="Email address" name="email" type="email" autoComplete="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} required autoFocus />
                <Field label="Password" name="password" type="password" autoComplete="current-password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} required />

                <div className="flex min-h-11 items-center justify-between gap-3">
                    <label className="flex min-h-11 items-center gap-3 text-sm font-medium text-slate-700">
                        <input type="checkbox" checked={form.data.remember} onChange={(event) => form.setData('remember', event.target.checked)} className="h-5 w-5 rounded border-slate-300 text-brand-accent focus:ring-brand-accent" />
                        Keep me signed in
                    </label>
                    <Link href="/forgot-password" className="inline-flex min-h-11 items-center text-sm font-semibold text-brand-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent">
                        {canResetPassword ? 'Forgot password?' : 'Reset password'}
                    </Link>
                </div>

                <Button type="submit" disabled={form.processing} className="w-full">
                    {form.processing ? 'Signing in…' : 'Sign in'}
                </Button>
            </form>

            <p className="mt-6 border-t border-slate-100 pt-5 text-center text-sm text-slate-600">
                New to POSPilot?{' '}
                <Link href="/register" className="inline-flex min-h-11 items-center font-bold text-brand-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent">Create an account</Link>
            </p>
        </GuestLayout>
    );
}
