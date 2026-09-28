import { Link } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';

const items = [
    { href: '/dashboard?screen=setup', title: 'Terminals and pricing', text: 'Add your POS terminals and customer charge rules.' },
    { href: '/dashboard?screen=expenses', title: 'Business expenses', text: 'Record simple day-to-day operating costs.' },
    { href: '/dashboard?screen=providers', title: 'Provider statements', text: 'Connect Gmail and configure automatic provider statement matching.' },
    { href: '/profile', title: 'Profile and security', text: 'Update your account, business information and sessions.' },
];

export default function MoreTools() {
    return <AppShell title="More tools">
        <div className="ops-page">
            <header className="ops-heading">
                <div>
                    <span className="eyebrow"><span className="eyebrow-dot" /> BUSINESS SETUP</span>
                    <h1>More tools</h1>
                    <p>Manage the details behind your daily POS operations.</p>
                </div>
            </header>
            <nav className="more-tools-list" aria-label="Business tools">
                {items.map((item) => <Link key={item.href} href={item.href} className="more-tool-link">
                    <span><strong>{item.title}</strong><small>{item.text}</small></span>
                    <span aria-hidden="true">→</span>
                </Link>)}
            </nav>
            <p className="more-tools-note"><strong>Provider connection note:</strong> OPay and PalmPay are supported for transaction records and imports only; they are not live-connected. Moniepoint is the only direct connection supported in this release.</p>
        </div>
    </AppShell>;
}
