import { Head, Link, useForm } from '@inertiajs/react';
import GoogleMark from '../../Components/GoogleMark';
import { Button, Field, Notice } from '../../Components/PosPilotUI';
import GuestLayout from '../../Layouts/GuestLayout';

export default function Register() {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    function submit(event) {
        event.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    }

    return (
        <GuestLayout>
            <Head title="Create account" />
            <p className="mb-2 text-xs font-bold uppercase tracking-[0.12em] text-brand-accent">Get started</p>
            <h1 className="text-2xl font-black">Create your POSPilot account</h1>
            <p className="mt-2 text-sm leading-6 text-slate-600">Set up your private workspace for clearer POS business records.</p>

            {Object.keys(form.errors).length > 0 && <div className="mt-5"><Notice tone="error">Please review the fields marked below.</Notice></div>}

            <form onSubmit={submit} aria-busy={form.processing} className="mt-6 space-y-4">
                <Field label="Your name" name="name" autoComplete="name" value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} error={form.errors.name} required autoFocus />
                <Field label="Email address" name="email" type="email" autoComplete="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} required />
                <Field label="Create password" name="password" type="password" autoComplete="new-password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} required />
                <Field label="Confirm password" name="password_confirmation" type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} error={form.errors.password_confirmation} required />
                <p className="text-xs leading-5 text-slate-500">We’ll ask you to verify your email address after creating your account.</p>
                <Button type="submit" className="w-full" disabled={form.processing}>
                    {form.processing ? 'Creating account…' : 'Create account'}
                </Button>
            </form>

            <div className="my-6 flex items-center gap-3 text-xs font-semibold uppercase tracking-wide text-slate-400">
                <span className="h-px flex-1 bg-slate-200" />
                <span>Or sign up with</span>
                <span className="h-px flex-1 bg-slate-200" />
            </div>

            <a href="/auth/google/redirect" className="flex min-h-12 w-full items-center justify-center gap-3 rounded-xl border border-brand-line bg-white px-4 font-bold text-slate-700 transition-colors hover:bg-brand-canvas focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent focus-visible:ring-offset-2">
                <GoogleMark />
                Continue with Google
            </a>

            <p className="mt-6 border-t border-slate-100 pt-5 text-center text-sm text-slate-600">
                Already have an account?{' '}
                <Link href="/login" className="inline-flex min-h-11 items-center font-bold text-brand-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent">Sign in</Link>
            </p>
        </GuestLayout>
    );
}
