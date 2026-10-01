import { Head, Link } from '@inertiajs/react';
import BrandLogo from '../../Components/BrandLogo';

const attentionItems = [
    { provider: 'OPay', date: 'Today', message: 'Settlement differs from the expected amount.', status: 'Needs review' },
    { provider: 'Moniepoint', date: 'Yesterday', message: 'One transaction is still pending.', status: 'Pending' },
    { provider: 'PalmPay', date: 'Yesterday', message: 'A reversed transaction needs confirmation.', status: 'Needs review' },
];

const recentTransactions = [
    { time: '2:16 PM', provider: 'PalmPay', reference: 'DEMO-PALMPAY-002', amount: '₦5,500.00', earnings: '₦65.00' },
    { time: '2:16 PM', provider: 'Moniepoint', reference: 'DEMO-MONIEPOINT-002', amount: '₦5,500.00', earnings: '₦65.00' },
    { time: '2:16 PM', provider: 'OPay', reference: 'DEMO-OPAY-002', amount: '₦5,500.00', earnings: '₦65.00' },
];

const terminals = [
    { name: 'Demo OPay terminal', provider: 'OPay', count: '8 transactions', volume: '₦75,250.00', earnings: '₦570.00' },
    { name: 'Demo Moniepoint terminal', provider: 'Moniepoint', count: '8 transactions', volume: '₦75,250.00', earnings: '₦570.00' },
    { name: 'Demo PalmPay terminal', provider: 'PalmPay', count: '8 transactions', volume: '₦75,250.00', earnings: '₦570.00' },
];

function SummaryCard({ label, value, note, emphasis = false }) {
    return <article className={`rounded-2xl border p-5 shadow-sm ${emphasis ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'}`}>
        <p className="text-sm font-semibold text-slate-600">{label}</p>
        <p className="mt-2 text-2xl font-extrabold tracking-tight text-slate-950">{value}</p>
        <p className="mt-2 text-xs leading-5 text-slate-500">{note}</p>
    </article>;
}

export default function Showcase() {
    const today = new Intl.DateTimeFormat('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date());

    return <>
        <Head title="POSPilot demo dashboard" />
        <main className="min-h-screen bg-slate-50 text-slate-900">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                    <Link href="/" aria-label="POSPilot home"><BrandLogo /></Link>
                    <nav aria-label="Demo navigation" className="flex flex-wrap items-center gap-2 text-sm">
                        <a className="rounded-lg px-3 py-2 font-semibold text-emerald-800 hover:bg-emerald-50" href="#transactions">Transactions</a>
                        <a className="rounded-lg px-3 py-2 font-semibold text-emerald-800 hover:bg-emerald-50" href="#operations">Operations</a>
                        <a className="rounded-lg px-3 py-2 font-semibold text-emerald-800 hover:bg-emerald-50" href="#terminals">Reports</a>
                        <Link className="rounded-lg border border-slate-300 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50" href="/login">Sign in</Link>
                    </nav>
                </div>
            </header>

            <div className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <aside role="note" className="mb-6 flex flex-col gap-1 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 sm:flex-row sm:items-center sm:gap-3">
                    <strong className="shrink-0 text-xs tracking-widest">PUBLIC DEMO · FICTIONAL DATA</strong>
                    <span>These sample figures and transaction references are illustrative only. They are not provider activity or Gmail imports.</span>
                </aside>

                <section className="mb-6 flex flex-wrap items-end justify-between gap-4">
                    <div><p className="text-xs font-bold tracking-widest text-emerald-700">SHOWCASE WORKSPACE · {today.toUpperCase()}</p><h1 className="mt-2 text-3xl font-extrabold tracking-tight">Good day, Demo operator</h1><p className="mt-2 text-sm text-slate-600">A read-only walkthrough of POSPilot for Nigerian POS agents.</p></div>
                    <span className="rounded-full border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-900">Earnings are provisional</span>
                </section>

                <section aria-label="Sample business summary" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryCard label="Estimated earnings" value="₦420.00" note="Provisional · provider fee details are incomplete" emphasis />
                    <SummaryCard label="Today's transaction volume" value="₦27,750.00" note="Successful sample POS activity" />
                    <SummaryCard label="Successful transactions" value="6" note="Sample activity recorded today" />
                    <SummaryCard label="Open issues" value="7" note="Fictional reconciliation records for demonstration" />
                    <SummaryCard label="Expenses" value="₦300.00" note="Sample expenses recorded today" />
                    <SummaryCard label="Cash float" value="₦55,550.00" note="Illustrative sample position" />
                    <SummaryCard label="Provider float" value="₦27,570.00" note="Illustrative sample position" />
                    <SummaryCard label="Data source" value="Demo records" note="No sample transaction was imported from Gmail" />
                </section>

                <section id="operations" className="mt-6 grid scroll-mt-6 gap-5 xl:grid-cols-2">
                    <article className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="border-b border-slate-100 px-5 py-4"><h2 className="text-lg font-bold">Things needing attention</h2><p className="mt-1 text-sm text-slate-600">Example settlement and transaction follow-up.</p></div>
                        <div className="divide-y divide-slate-100">{attentionItems.map((item) => <div key={`${item.provider}-${item.message}`} className="grid gap-1 px-5 py-4 sm:grid-cols-[100px_1fr_auto] sm:items-center sm:gap-4"><span className="text-sm font-bold">{item.provider}</span><div><p className="text-sm text-slate-800">{item.message}</p><p className="mt-1 text-xs text-slate-500">{item.date} · sample record</p></div><span className="text-xs font-semibold text-amber-800">{item.status}</span></div>)}</div>
                    </article>
                    <article id="terminals" className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-bold">Terminal performance</h2><p className="mt-1 text-sm text-slate-600">Sample activity over the last seven days.</p>
                        <div className="mt-4 space-y-3">{terminals.map((terminal) => <div key={terminal.name} className="rounded-xl border border-slate-200 p-4"><div className="flex flex-wrap items-center justify-between gap-2"><strong className="text-sm">{terminal.name}</strong><span className="text-xs font-semibold text-amber-800">Provisional</span></div><p className="mt-1 text-sm text-slate-600">{terminal.provider} · {terminal.count} · {terminal.volume}</p><p className="mt-3 text-sm font-bold">Estimated earnings {terminal.earnings}</p></div>)}</div>
                    </article>
                </section>

                <section id="transactions" className="mt-6 scroll-mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 px-5 py-4"><h2 className="text-lg font-bold">Recent transactions</h2><p className="mt-1 text-sm text-slate-600">All references below are fictional demo records.</p></div>
                    <div className="divide-y divide-slate-100">{recentTransactions.map((transaction) => <div key={transaction.reference} className="grid gap-1 px-5 py-4 sm:grid-cols-[80px_100px_1fr_130px_130px] sm:items-center sm:gap-4"><span className="text-sm text-slate-600">{transaction.time}</span><strong className="text-sm">{transaction.provider}</strong><code className="break-all text-xs text-slate-600">{transaction.reference}</code><span className="text-sm font-semibold">{transaction.amount}</span><span className="text-sm text-emerald-800">Estimated {transaction.earnings}</span></div>)}</div>
                </section>

                <footer className="mt-6 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 py-5 text-xs text-slate-500"><span>POSPilot · financial clarity for POS businesses</span><span>Read-only public demo · fictional sample data</span></footer>
            </div>
        </main>
    </>;
}
