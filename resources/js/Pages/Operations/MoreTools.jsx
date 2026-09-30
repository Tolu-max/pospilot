import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import { api, money, today } from '../../lib/api';
import { Card, ErrorNotice, LoadingCard, Notice, StatusPill } from '../../Components/PosPilotUI';

const items = [
    { href: '/dashboard?screen=setup', title: 'Terminals and pricing', text: 'Add your POS terminals and customer charge rules.' },
    { href: '/dashboard?screen=expenses', title: 'Business expenses', text: 'Record simple day-to-day operating costs.' },
    { href: '/dashboard?screen=closing', title: 'Cash and float control', text: 'Compare recorded cash and provider balances, including terminal-level balances where available.' },
    { href: '/dashboard?screen=providers', title: 'Provider statements', text: 'Connect Gmail and configure automatic provider statement matching.' },
    { href: '/profile', title: 'Profile and security', text: 'Update your account, business information and sessions.' },
];

export default function MoreTools() {
    const [profitability, setProfitability] = useState(null);
    const [profitabilityError, setProfitabilityError] = useState(null);
    const [profitabilityLoading, setProfitabilityLoading] = useState(true);
    const hasTerminalData = (profitability?.data?.length ?? 0) > 0;
    const hasCompleteTerminalData = hasTerminalData && profitability.expense_attribution_complete && profitability.data.every((row) => row.is_final);

    useEffect(() => {
        const end = new Date(`${today()}T00:00:00`);
        const start = new Date(end);
        start.setDate(start.getDate() - 6);
        const from = `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, '0')}-${String(start.getDate()).padStart(2, '0')}`;
        api(`/api/terminal-profitability?from=${from}&to=${today()}`)
            .then(setProfitability)
            .catch(setProfitabilityError)
            .finally(() => setProfitabilityLoading(false));
    }, []);

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
            <Card className="mt-6">
                <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-lg font-extrabold">Terminal profitability</h2><p className="mt-1 text-sm text-slate-600">Compare your own recorded terminal results over the last seven days.</p></div><StatusPill tone={hasCompleteTerminalData ? 'good' : 'warn'}>{hasCompleteTerminalData ? 'Complete data' : 'Provisional where data is missing'}</StatusPill></div>
                <p className="mt-3 text-sm text-slate-600">POSPilot includes known customer charges and provider fees. Expenses without a terminal assignment stay unallocated and are not silently spread across terminals.</p>
                {profitabilityError && <div className="mt-4"><ErrorNotice error={profitabilityError} /></div>}
                {profitabilityLoading && <div className="mt-4"><LoadingCard label="Comparing recorded terminal activity…" /></div>}
                {!profitabilityLoading && profitability && (profitability.data?.length ? <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[42rem] text-left text-sm"><thead><tr className="border-b border-brand-line text-slate-600"><th className="pb-3">Terminal</th><th className="pb-3">Provider</th><th className="pb-3">Transactions</th><th className="pb-3">Volume</th><th className="pb-3">Provider fees</th><th className="pb-3">Expenses</th><th className="pb-3">Estimated result</th></tr></thead><tbody>{profitability.data.map((row) => <tr key={row.terminal_id ?? 'unmapped'} className="border-b border-brand-line"><td className="py-3 font-semibold">{row.terminal}</td><td>{row.provider}</td><td>{row.transaction_count}</td><td>{money(row.transaction_volume)}</td><td>{row.provider_fee_known ? money(row.provider_fees) : 'Unknown'}</td><td>{money(row.allocated_expenses)}</td><td><div className="font-bold">{money(row.estimated_net_after_expenses)}</div>{!row.is_final && <div className="text-xs text-amber-800">Provisional</div>}</td></tr>)}</tbody></table><p className="mt-3 text-xs text-slate-600">Expenses not assigned to a terminal: {money(profitability.unallocated_expenses)}</p>{!profitability.expense_attribution_complete && <div className="mt-3"><Notice tone="warning">Some expenses are not assigned to a terminal, so terminal comparisons are provisional.</Notice></div>}</div> : <div className="mt-4"><Notice tone="info">No successful transactions have terminal assignments for this period yet.</Notice></div>)}
            </Card>
            <p className="more-tools-note"><strong>Provider connection note:</strong> OPay and PalmPay are supported for transaction records and imports only; they are not live-connected. Moniepoint is the only direct connection supported in this release.</p>
        </div>
    </AppShell>;
}
