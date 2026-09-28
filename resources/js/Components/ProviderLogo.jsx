export default function ProviderLogo({ provider, size = 'md', decorative = true }) {
    const name = typeof provider === 'string' ? provider : provider?.name;

    return <span className={`provider-logo provider-logo-${size}`} aria-hidden={decorative || undefined} role={decorative ? undefined : 'img'} aria-label={decorative ? undefined : `${name || 'Unknown provider'} terminal`}>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="4" width="18" height="16" rx="3" />
            <path d="M7 8h10M7 12h10M7 16h5" />
        </svg>
    </span>;
}
