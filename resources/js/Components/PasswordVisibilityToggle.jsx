export default function PasswordVisibilityToggle({ visible, onClick, className = '' }) {
    return (
        <button
            type="button"
            aria-label={visible ? 'Hide password' : 'Show password'}
            aria-pressed={visible}
            onClick={onClick}
            className={`absolute right-2 top-1/2 inline-flex h-10 w-10 min-h-10 min-w-10 shrink-0 -translate-y-1/2 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 p-0 text-brand-accent transition-colors hover:border-emerald-200 hover:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent focus-visible:ring-offset-1 ${className}`}
        >
            {visible ? (
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-5 w-5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M3 3l18 18" />
                    <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
                    <path d="M9.9 5.2A11.6 11.6 0 0 1 12 5c5.2 0 8.7 4.1 9.5 6.2a2.1 2.1 0 0 1 0 1.6 10.5 10.5 0 0 1-3.1 4.2" />
                    <path d="M6.2 6.2a11 11 0 0 0-3.7 5 2.1 2.1 0 0 0 0 1.6C3.3 14.9 6.8 19 12 19c1.3 0 2.5-.3 3.5-.8" />
                </svg>
            ) : (
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="h-5 w-5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M2.5 12s3.3-7 9.5-7 9.5 7 9.5 7-3.3 7-9.5 7-9.5-7-9.5-7Z" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
            )}
        </button>
    );
}
