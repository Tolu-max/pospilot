import { Link } from '@inertiajs/react';
import { useState } from 'react';
import PasswordVisibilityToggle from './PasswordVisibilityToggle';

export function Card({ children, className = '', id }) {
    const background = /(?:^|\s)bg-/.test(className) ? '' : 'bg-white';
    return <section id={id} className={`rounded-2xl border border-brand-line ${background} p-5 shadow-sm sm:p-6 ${className}`}>{children}</section>;
}

export function Button({ children, variant = 'primary', className = '', ...props }) {
    const styles = variant === 'secondary'
        ? 'border border-brand-line bg-white text-brand-ink hover:bg-brand-canvas'
        : variant === 'danger'
            ? 'bg-red-700 text-white hover:bg-red-800'
            : 'bg-brand-accent text-white hover:bg-emerald-800';
    return <button className={`inline-flex min-h-12 items-center justify-center rounded-xl px-5 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 ${styles} ${className}`} {...props}>{children}</button>;
}

export function Field({ label, error, className = '', ...props }) {
    const id = props.id || props.name;
    const [passwordVisible, setPasswordVisible] = useState(false);
    const isPassword = props.type === 'password';
    const input = (
        <input
            id={id}
            className={`mt-2 min-h-12 w-full rounded-xl border-brand-line bg-white px-4 text-base font-normal text-brand-ink placeholder:text-slate-400 focus:border-brand-accent focus:ring-brand-accent ${isPassword ? 'pr-16' : ''}`}
            {...props}
            type={isPassword && passwordVisible ? 'text' : props.type}
        />
    );

    return (
        <div className={`block text-sm font-semibold text-brand-ink ${className}`}>
            <label htmlFor={id}>{label}</label>
            {isPassword ? (
                <span className="relative block">
                    {input}
                    <PasswordVisibilityToggle
                        visible={passwordVisible}
                        onClick={() => setPasswordVisible((visible) => !visible)}
                    />
                </span>
            ) : input}
            {error && <span className="mt-1 block text-sm font-medium text-red-700">{error}</span>}
        </div>
    );
}

export function SelectField({ label, error, children, className = '', ...props }) {
    const id = props.id || props.name;
    return <label htmlFor={id} className={`block text-sm font-semibold text-brand-ink ${className}`}>{label}<select id={id} className="mt-2 min-h-12 w-full rounded-xl border-brand-line bg-white px-4 text-base font-normal text-brand-ink focus:border-brand-accent focus:ring-brand-accent" {...props}>{children}</select>{error && <span className="mt-1 block text-sm font-medium text-red-700">{error}</span>}</label>;
}

export function Notice({ children, tone = 'info' }) {
    const tones = { info: 'border-sky-200 bg-sky-50 text-sky-900', success: 'border-emerald-200 bg-emerald-50 text-emerald-900', warning: 'border-amber-300 bg-amber-50 text-amber-950', error: 'border-red-200 bg-red-50 text-red-900' };
    return <div role={tone === 'error' ? 'alert' : 'status'} className={`rounded-xl border px-4 py-3 text-sm leading-6 ${tones[tone]}`}>{children}</div>;
}

export function ErrorNotice({ error }) {
    if (!error) return null;
    const validationMessages = Object.values(error.errors || {}).flat().filter(Boolean);
    return <Notice tone="error"><p>{error.message || 'Something went wrong. Please try again.'}</p>{validationMessages.length > 0 && <ul className="mt-2 list-inside list-disc">{validationMessages.map((message, index) => <li key={`${index}-${message}`}>{message}</li>)}</ul>}</Notice>;
}

export function StatusPill({ children, tone = 'neutral' }) {
    const tones = { neutral: 'bg-slate-100 text-slate-700', good: 'bg-emerald-100 text-emerald-900', warn: 'bg-amber-100 text-amber-950', bad: 'bg-red-100 text-red-900', info: 'bg-sky-100 text-sky-900' };
    return <span className={`inline-flex rounded-full px-3 py-1 text-xs font-bold capitalize ${tones[tone] || tones.neutral}`}>{String(children ?? 'Unknown').replaceAll('_', ' ')}</span>;
}

export function PageHeading({ eyebrow, title, description, action }) {
    return <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><p className="text-sm font-bold uppercase tracking-wide text-brand-accent">{eyebrow || 'POSPilot'}</p><h1 className="mt-1 text-2xl font-extrabold tracking-tight text-brand-ink sm:text-3xl">{title}</h1>{description && <p className="mt-2 max-w-2xl text-base leading-6 text-slate-600">{description}</p>}</div>{action}</div>;
}

export function EmptyState({ title, description, href, linkLabel }) {
    return <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center"><h3 className="text-lg font-bold text-brand-ink">{title}</h3><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-600">{description}</p>{href && <Link href={href} className="mt-5 inline-flex min-h-12 items-center rounded-xl bg-brand-accent px-5 font-semibold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent focus-visible:ring-offset-2">{linkLabel}</Link>}</div>;
}

export function LoadingCard({ label = 'Loading your information…' }) {
    return <div role="status" className="rounded-2xl border border-brand-line bg-white p-6 text-sm text-slate-600"><span className="mr-3 inline-block h-4 w-4 animate-pulse rounded-full bg-emerald-600 align-middle" />{label}</div>;
}

export function FormErrorList({ errors }) {
    const list = Object.values(errors || {}).flat().filter(Boolean);
    return list.length ? <Notice tone="error"><ul className="list-inside list-disc">{list.map((message, index) => <li key={`${index}-${message}`}>{message}</li>)}</ul></Notice> : null;
}
