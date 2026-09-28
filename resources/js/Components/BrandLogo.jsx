export default function BrandLogo({ className = '', compact = false, alt = 'POSPilot' }) {
    return <span className={`pospilot-brand-lockup ${compact ? 'pospilot-brand-lockup-compact' : ''} ${className}`.trim()}>
        <img className="pospilot-brand-icon" src="/images/brand/pospilot-icon-256.png" alt={compact ? alt : ''} aria-hidden={!compact} />
        {!compact && <img className="pospilot-brand-wordmark" src="/images/brand/pospilot-wordmark-800.png" alt={alt} />}
    </span>;
}
