import { Link } from '@inertiajs/react';
import BrandLogo from '../Components/BrandLogo';

export default function GuestLayout({ children }) {
    return (
        <main className="guest-page flex min-h-screen items-center justify-center bg-brand-canvas px-4 py-8">
            <div className="guest-layout-shell">
                <aside className="guest-intro-panel" aria-label="About POSPilot">
                    <Link href="/" className="guest-brand guest-brand-desktop rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent" aria-label="POSPilot home">
                        <BrandLogo />
                    </Link>
                    <div className="guest-intro-copy">
                        <p className="guest-intro-kicker">A clearer view of your business</p>
                        <h2>Keep your POS business moving with confidence.</h2>
                        <p>Bring your financial records into one private workspace, ready whenever you need them.</p>
                    </div>
                    <div className="guest-preview-card" aria-hidden="true">
                        <span className="guest-preview-label">Your workspace</span>
                        <span className="guest-preview-line"><i /><i /><i /></span>
                        <span className="guest-preview-row"><b>Business records</b><span>Organized</span></span>
                        <span className="guest-preview-row"><b>Account security</b><span>Protected</span></span>
                    </div>
                    <p className="guest-intro-footnote">Built for the people behind the counter.</p>
                </aside>

                <div className="guest-card-wrap">
                    <Link href="/" className="guest-brand guest-brand-mobile mx-auto mb-6 flex w-fit min-h-12 items-center rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-accent" aria-label="POSPilot home">
                        <BrandLogo />
                    </Link>
                    <section className="guest-card rounded-3xl border border-brand-line bg-white p-6 shadow-sm sm:p-8">{children}</section>
                    <p className="guest-privacy mt-5 text-center text-xs leading-5 text-slate-500">Your financial records are private to your account.</p>
                </div>
            </div>
        </main>
    );
}
