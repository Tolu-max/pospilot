import { useEffect, useRef, useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import BrandLogo from '../Components/BrandLogo';
import ToastViewport from '../Components/ToastViewport';

const navigation = [
    { href: '/dashboard', label: 'Home', icon: 'home' },
    { href: '/transactions', label: 'Transactions', icon: 'transactions' },
    { href: '/reconciliation', label: 'Reconciliation', icon: 'reconcile' },
    { href: '/dashboard?screen=closing', label: 'Daily Closing', icon: 'closing' },
    { href: '/dashboard?screen=providers', label: 'Providers', icon: 'providers' },
    { href: '/dashboard?screen=expenses', label: 'Expenses', icon: 'expenses' },
    { href: '/profile', label: 'Settings', icon: 'settings' },
    { href: '/team', label: 'Team', icon: 'team' },
];

const icons = {
    home: <><path d="m3 10 9-7 9 7"/><path d="M5 9v11h14V9M9 20v-6h6v6"/></>,
    transactions: <><path d="M4 5h16M4 12h16M4 19h16"/><path d="M7 5h.01M7 12h.01M7 19h.01"/></>,
    reconcile: <><path d="m5 12 4 4L19 6"/><path d="M20 12a8 8 0 1 1-2.35-5.65"/></>,
    closing: <><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 15h3"/></>,
    providers: <><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h4M7 16h10"/></>,
    expenses: <><path d="M4 7h16v13H4zM7 7V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/><path d="M4 12h16M10 12v2h4v-2"/></>,
    settings: <><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.6.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.6-.9l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.9l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.6-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.6.9l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.8Z" transform="translate(-1 -1)"/></>,
    team: <><circle cx="9" cy="8" r="3"/><path d="M3 20c0-3 2.2-5 6-5s6 2 6 5M16 5.2a3 3 0 0 1 0 5.6M18 15c2 .6 3 2.2 3 4"/></>,
    menu: <><path d="M4 6h16M4 12h16M4 18h16"/></>,
    search: <><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.5 4.5"/></>,
    close: <><path d="m6 6 12 12M18 6 6 18"/></>,
    chevron: <path d="m7 10 5 5 5-5" />,
};

function Icon({ name, size = 17 }) {
    return <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{icons[name]}</svg>;
}

export default function AppShell({ children, title = 'Home' }) {
    const { auth, url, workspaceReady, workspace } = usePage();
    const [menuOpen, setMenuOpen] = useState(false);
    const [accountOpen, setAccountOpen] = useState(false);
    const businessName = workspace?.business_name || '';
    const accountMenuRef = useRef(null);
    const accountTriggerRef = useRef(null);
    const currentUrl = new URL(url || window.location.href, window.location.origin);
    const screen = currentUrl.searchParams.get('screen');
    const user = auth?.user;
    const isWorkspaceReady = workspaceReady === true;
    const role = workspace?.role || 'owner';
    const visibleNavigation = role === 'owner'
        ? navigation
        : role === 'manager'
            ? navigation.filter((item) => ['Home', 'Transactions', 'Reconciliation', 'Expenses'].includes(item.label)).concat([{ href: '/dashboard?screen=team-activity', label: 'Staff activity', icon: 'team' }])
            : navigation.filter((item) => item.label === 'Home');

    useEffect(() => {
        if (!accountOpen) {
            return undefined;
        }

        const closeOnOutsideClick = (event) => {
            if (!accountMenuRef.current?.contains(event.target)) {
                setAccountOpen(false);
            }
        };
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') {
                setAccountOpen(false);
                accountTriggerRef.current?.focus();
            }
        };

        document.addEventListener('pointerdown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [accountOpen]);

    const isActive = (item) => {
        if (item.href === '/dashboard') return currentUrl.pathname === '/dashboard' && !screen;
        if (item.href.startsWith('/dashboard?screen=')) return currentUrl.pathname === '/dashboard' && screen === item.href.split('=')[1];
        return currentUrl.pathname === item.href || currentUrl.pathname.startsWith(`${item.href}/`);
    };

    return <div className="app-shell">
        <Head title={`${title} · POSPilot`} />
        {menuOpen && <button type="button" className="mobile-scrim" aria-label="Close navigation" onClick={() => setMenuOpen(false)} />}
        <aside className={`sidebar ${menuOpen ? 'sidebar-open' : ''}`} aria-label="POSPilot navigation">
            <div className="brand-row"><Link href="/dashboard" className="brand-lockup" onClick={() => setMenuOpen(false)} aria-label="POSPilot dashboard"><BrandLogo /></Link><button type="button" className="icon-button sidebar-close" aria-label="Close navigation" onClick={() => setMenuOpen(false)}><Icon name="close" /></button></div>
            <nav className="primary-nav" aria-label="Main navigation">
                {isWorkspaceReady && visibleNavigation.map((item) => <Link key={item.label} href={item.href} onClick={() => setMenuOpen(false)} aria-current={isActive(item) ? 'page' : undefined} className={`nav-link ${isActive(item) ? 'nav-link-active' : ''}`}><Icon name={item.icon} /><span>{item.label}</span></Link>)}
            </nav>
            <div className="sidebar-bottom">
                <div className="sidebar-account-card">
                    <span className="account-brand-avatar account-brand-avatar-sidebar"><BrandLogo compact /></span>
                    <span className="profile-copy"><strong>{user?.name || 'Your account'}</strong><span>{businessName || 'Business workspace'}</span></span>
                </div>
            </div>
        </aside>
        <main className="app-main">
            <header className="topbar">
                <button type="button" className="icon-button mobile-menu-button" aria-label="Open navigation" aria-expanded={menuOpen} onClick={() => setMenuOpen(true)}><Icon name="menu" /></button>
                {title === 'Business setup' ? <p className="setup-unlock-note">Finish setup to unlock your workspace</p> : role !== 'attendant' && <form className="search-wrap" action="/transactions" method="get" role="search">
                    <Icon name="search" size={16} />
                    <input aria-label="Search transactions by reference" name="search" maxLength={100} defaultValue={currentUrl.searchParams.get('search') || currentUrl.searchParams.get('reference') || ''} placeholder="Search transactions by reference" />
                    <button type="submit" className="topbar-search-submit">Search</button>
                </form>}
                <div className="topbar-actions">
                    <span className="topbar-page-title">{title}</span>
                    <div className="account-menu" ref={accountMenuRef}>
                        <button ref={accountTriggerRef} type="button" className="account-menu-trigger" aria-label={`Account menu for ${user?.name || 'your account'}`} aria-haspopup="true" aria-expanded={accountOpen} aria-controls="account-menu-panel" onClick={() => setAccountOpen((open) => !open)}>
                            <span className="account-brand-avatar account-brand-avatar-header"><BrandLogo compact /></span>
                            <span className="account-trigger-copy"><strong>{user?.name || 'Your account'}</strong><small>Account</small></span>
                            <Icon name="chevron" size={15} />
                        </button>
                        {accountOpen && <div id="account-menu-panel" className="account-menu-panel">
                            <div className="account-menu-identity">
                                <strong>{user?.name || 'Your account'}</strong>
                                {user?.email && <span>{user.email}</span>}
                                <small>{businessName || 'Business workspace'}</small>
                            </div>
                            {isWorkspaceReady && role === 'owner' && <Link href="/profile" className="account-menu-link" onClick={() => setAccountOpen(false)}>Account settings</Link>}
                            <Link href="/logout" method="post" as="button" className="account-menu-link account-menu-signout" onClick={() => setAccountOpen(false)}>Sign out</Link>
                        </div>}
                    </div>
                </div>
            </header>
            <div className="page-content">{children}</div>
        </main>
        <ToastViewport />
    </div>;
}
