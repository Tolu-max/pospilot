import BrandLogo from './BrandLogo';

export default function FullPageLoader({ label = 'Loading your workspace' }) {
    return <div className="page-loading-overlay" role="status" aria-live="polite" aria-label={label}>
        <div className="page-loading-card">
            <BrandLogo className="page-loading-brand" />
            <span className="page-loading-spinner" aria-hidden="true" />
            <span className="page-loading-label">{label}</span>
        </div>
    </div>;
}
