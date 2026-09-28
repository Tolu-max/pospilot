import GuestLayout from '../../Layouts/GuestLayout';
import { Head, useForm } from '@inertiajs/react';
import { Button, Field, Notice } from '../../Components/PosPilotUI';

export default function ResetPassword({ token, email }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });
    function submit(event) { event.preventDefault(); form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') }); }
    return <GuestLayout><Head title="Choose a new password" /><h1 className="text-2xl font-black">Choose a new password</h1><p className="mt-2 text-sm text-slate-600">Create a new password for your POSPilot account.</p>{Object.keys(form.errors).length > 0 && <div className="mt-4"><Notice tone="error">The reset link may be invalid or expired. Check the details and try again.</Notice></div>}<form onSubmit={submit} className="mt-6 space-y-4"><Field label="Email address" name="email" type="email" autoComplete="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} error={form.errors.email} required /><Field label="New password" name="password" type="password" autoComplete="new-password" value={form.data.password} onChange={(event) => form.setData('password', event.target.value)} error={form.errors.password} required autoFocus /><Field label="Confirm new password" name="password_confirmation" type="password" autoComplete="new-password" value={form.data.password_confirmation} onChange={(event) => form.setData('password_confirmation', event.target.value)} error={form.errors.password_confirmation} required /><Button type="submit" className="w-full" disabled={form.processing}>{form.processing ? 'Updating…' : 'Update password'}</Button></form></GuestLayout>;
}
