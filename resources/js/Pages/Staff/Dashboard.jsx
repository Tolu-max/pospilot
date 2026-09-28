import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import { Button, Card, EmptyState, Field, LoadingCard, Notice, PageHeading, SelectField } from '../../Components/PosPilotUI';
import { api, dateTime, money } from '../../lib/api';

export default function StaffDashboard({ businessName, role }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [busy, setBusy] = useState(false);
    const [selectedShiftTransactions, setSelectedShiftTransactions] = useState(null);
    const [terminalId, setTerminalId] = useState('');
    const [openingCash, setOpeningCash] = useState('');
    const [closingCash, setClosingCash] = useState('');
    const [expense, setExpense] = useState({ amount: '', category: 'miscellaneous', description: '' });
    const [cash, setCash] = useState({ entry_type: 'cash_out', amount: '', category: 'other', description: '' });
    const [issue, setIssue] = useState({ subject: '', description: '' });
    const manager = role === 'manager';

    async function load() {
        setLoading(true); setError('');
        try { setData(await api(manager ? '/api/team/activity' : '/api/staff/dashboard')); }
        catch (requestError) { setError(requestError.message); }
        finally { setLoading(false); }
    }

    useEffect(() => { load(); }, [role]);

    async function submit(path, body, successMessage) {
        setBusy(true); setError(''); setNotice('');
        try { await api(path, { method: 'POST', body }); setNotice(successMessage); await load(); }
        catch (requestError) { setError(requestError.message); }
        finally { setBusy(false); }
    }

    async function viewShift(shift) {
        setError(''); setSelectedShiftTransactions({ shiftId: shift.id, loading: true, transactions: [] });
        try { const result = await api(`/api/staff/shifts/${shift.id}/transactions`); setSelectedShiftTransactions({ shiftId: shift.id, loading: false, transactions: result.data || [] }); }
        catch (requestError) { setSelectedShiftTransactions(null); setError(requestError.message); }
    }

    const activeShift = data?.active_shift;
    const title = manager ? 'Staff activity' : 'My shift';

    return <AppShell title={title}>
        <div className="mx-auto max-w-6xl space-y-6">
            <PageHeading eyebrow={businessName} title={manager ? 'Staff activity' : `Welcome, ${data?.staff_name || 'attendant'}`} description={manager ? 'Review terminal coverage, shift cash submissions and reported issues.' : 'Start work on an assigned terminal, keep a clear cash record and submit your closing count.'} />
            {notice && <Notice tone="success" role="status">{notice}</Notice>}
            {error && <Notice tone="error" role="alert">{error} <button type="button" className="ml-2 underline" onClick={load}>Try again</button></Notice>}
            {loading && <LoadingCard label="Loading your workspace…" />}

            {!loading && manager && <Card><h2 className="text-lg font-bold text-brand-ink">Recent shifts</h2>{data?.data?.length ? <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[48rem] text-left text-sm"><thead><tr className="border-b border-brand-line text-slate-600"><th className="pb-3">Staff</th><th className="pb-3">Terminal</th><th className="pb-3">Started</th><th className="pb-3">Status</th><th className="pb-3">Closing cash</th><th className="pb-3">Issues</th></tr></thead><tbody>{data.data.map((shift) => <tr key={shift.id} className="border-b border-brand-line"><td className="py-3 font-semibold">{shift.staff}</td><td>{shift.terminal}</td><td>{dateTime(shift.started_at)}</td><td className="capitalize">{shift.status}</td><td>{shift.closing_cash === null ? '—' : money(shift.closing_cash)}</td><td><ul>{shift.issues?.map((item) => <li key={item.id} className="flex items-center gap-2 py-1"><span>{item.subject} · {item.status}</span>{item.status === 'open' && <Button type="button" variant="secondary" onClick={() => submit(`/api/team/issues/${item.id}/resolve`, {}, 'Issue marked resolved.')}>Resolve</Button>}</li>)}</ul>{!shift.issues?.length && '—'}</td></tr>)}</tbody></table></div> : <EmptyState title="No shift activity yet" description="Staff shifts and issue reports will appear here when attendants start working." />}</Card>}

            {!loading && !manager && <>
                {activeShift ? <Card className="border-emerald-200"><div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><p className="text-sm font-bold uppercase tracking-wide text-emerald-800">Shift in progress</p><h2 className="mt-1 text-xl font-extrabold text-brand-ink">{activeShift.terminal?.name}</h2><p className="mt-1 text-sm text-slate-600">Started {dateTime(activeShift.started_at)}</p></div><span className="rounded-full bg-emerald-100 px-3 py-1 text-sm font-bold text-emerald-900">Active</span></div>
                    <form className="mt-5 flex flex-col gap-3 border-t border-brand-line pt-5 sm:flex-row sm:items-end" onSubmit={(event) => { event.preventDefault(); submit(`/api/staff/shifts/${activeShift.id}/close`, { closing_cash: closingCash }, 'Shift ended. Your closing cash was submitted.'); }}><Field label="Closing cash (₦)" name="closing_cash" type="text" inputMode="decimal" required value={closingCash} onChange={(event) => setClosingCash(event.target.value)} /><Button type="submit" disabled={busy}>{busy ? 'Submitting…' : 'End shift and submit cash'}</Button></form>
                </Card> : <Card><h2 className="text-lg font-bold text-brand-ink">Start a shift</h2><p className="mt-1 text-sm text-slate-600">Choose one of your assigned terminals. Only its transactions during your shift appear here.</p>{data?.assigned_terminals?.length ? <form className="mt-5 grid gap-4 sm:grid-cols-3 sm:items-end" onSubmit={(event) => { event.preventDefault(); submit('/api/staff/shifts', { terminal_id: Number(terminalId), opening_cash: openingCash || null }, 'Your shift has started.'); }}><SelectField label="Assigned terminal" name="terminal_id" required value={terminalId} onChange={(event) => setTerminalId(event.target.value)}><option value="">Choose terminal</option>{data.assigned_terminals.map((terminal) => <option key={terminal.id} value={terminal.id}>{terminal.name} · {terminal.provider?.name}</option>)}</SelectField><Field label="Opening cash (optional)" name="opening_cash" type="text" inputMode="decimal" value={openingCash} onChange={(event) => setOpeningCash(event.target.value)} /><Button type="submit" disabled={!terminalId}>Start shift</Button></form> : <div className="mt-5"><EmptyState title="No terminals assigned" description="Ask your owner to assign you an active terminal before starting a shift." /></div>}</Card>}

                {activeShift && <div className="grid gap-6 lg:grid-cols-2">
                    <Card><h2 className="text-lg font-bold text-brand-ink">Shift transactions</h2><p className="mt-1 text-sm text-slate-600">Transactions are shown for this terminal and shift time only.</p>{data.transactions?.length ? <ul className="mt-4 divide-y divide-brand-line">{data.transactions.map((transaction) => <li key={transaction.id} className="flex items-center justify-between gap-4 py-3 text-sm"><span><strong className="block text-brand-ink">{transaction.reference || 'No reference'}</strong><span className="text-slate-600">{dateTime(transaction.transaction_at)} · <span className="capitalize">{transaction.status}</span></span></span><strong>{money(transaction.amount)}</strong></li>)}</ul> : <p className="mt-5 rounded-xl bg-brand-canvas p-4 text-sm text-slate-600">No transactions recorded during this shift yet.</p>}</Card>
                    <Card><h2 className="text-lg font-bold text-brand-ink">Record cash activity</h2><p className="mt-1 text-sm text-slate-600">Log cash float or payouts to keep the close clear.</p><form className="mt-4 space-y-3" onSubmit={(event) => { event.preventDefault(); submit(`/api/staff/shifts/${activeShift.id}/cash-activity`, cash, 'Cash activity recorded.'); }}><div className="grid gap-3 sm:grid-cols-2"><SelectField label="Activity" name="entry_type" value={cash.entry_type} onChange={(event) => setCash({ ...cash, entry_type: event.target.value })}><option value="cash_in">Cash in</option><option value="cash_out">Cash out</option></SelectField><SelectField label="Reason" name="cash_category" value={cash.category} onChange={(event) => setCash({ ...cash, category: event.target.value })}><option value="cash_float">Cash float</option><option value="cash_payout">Cash payout</option><option value="other">Other</option></SelectField></div><Field label="Amount (₦)" name="cash_amount" type="text" inputMode="decimal" required value={cash.amount} onChange={(event) => setCash({ ...cash, amount: event.target.value })} /><Field label="Details (optional)" name="cash_description" value={cash.description} onChange={(event) => setCash({ ...cash, description: event.target.value })} /><Button type="submit">Save cash activity</Button></form></Card>
                    <Card><h2 className="text-lg font-bold text-brand-ink">Record an expense</h2><form className="mt-4 space-y-3" onSubmit={(event) => { event.preventDefault(); submit(`/api/staff/shifts/${activeShift.id}/expenses`, expense, 'Expense recorded for this shift.'); setExpense({ amount: '', category: 'miscellaneous', description: '' }); }}><Field label="Amount (₦)" name="expense_amount" type="text" inputMode="decimal" required value={expense.amount} onChange={(event) => setExpense({ ...expense, amount: event.target.value })} /><SelectField label="Category" name="expense_category" value={expense.category} onChange={(event) => setExpense({ ...expense, category: event.target.value })}><option value="transport">Transport</option><option value="power">Power</option><option value="staff">Staff</option><option value="cash handling">Cash handling</option><option value="miscellaneous">Other</option></SelectField><Field label="Details (optional)" name="expense_description" value={expense.description} onChange={(event) => setExpense({ ...expense, description: event.target.value })} /><Button type="submit">Save expense</Button></form></Card>
                    <Card><h2 className="text-lg font-bold text-brand-ink">Report an issue</h2><p className="mt-1 text-sm text-slate-600">Your owner or manager can follow up from Staff activity.</p><form className="mt-4 space-y-3" onSubmit={(event) => { event.preventDefault(); submit(`/api/staff/shifts/${activeShift.id}/issues`, issue, 'Issue sent to your business team.'); setIssue({ subject: '', description: '' }); }}><Field label="Issue title" name="issue_subject" required value={issue.subject} onChange={(event) => setIssue({ ...issue, subject: event.target.value })} /><label htmlFor="issue_description" className="block text-sm font-semibold text-brand-ink">Details<textarea id="issue_description" name="issue_description" required maxLength={2000} rows={4} className="mt-2 w-full rounded-xl border-brand-line px-4 py-3 focus:border-brand-accent focus:ring-brand-accent" value={issue.description} onChange={(event) => setIssue({ ...issue, description: event.target.value })} /></label><Button type="submit">Send issue report</Button></form></Card>
                </div>}
                {data?.recent_shifts?.length > 0 && <Card><h2 className="text-lg font-bold text-brand-ink">Recent shifts</h2><ul className="mt-3 divide-y divide-brand-line">{data.recent_shifts.map((shift) => <li key={shift.id} className="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><span><strong>{shift.terminal?.name}</strong><span className="ml-2 text-slate-600">{dateTime(shift.started_at)}</span></span><span className="capitalize text-slate-600">{shift.status} {shift.closing_cash !== null ? `· closed with ${money(shift.closing_cash)}` : ''}</span><Button type="button" variant="secondary" onClick={() => viewShift(shift)}>View shift transactions</Button></li>)}</ul>{selectedShiftTransactions && <div className="mt-4 rounded-xl bg-brand-canvas p-4"><h3 className="font-bold text-brand-ink">Shift transactions</h3>{selectedShiftTransactions.loading ? <p className="mt-2 text-sm text-slate-600" role="status">Loading shift activity…</p> : selectedShiftTransactions.transactions.length ? <ul className="mt-2 divide-y divide-brand-line">{selectedShiftTransactions.transactions.map((transaction) => <li key={transaction.id} className="flex items-center justify-between gap-4 py-2 text-sm"><span>{transaction.reference || 'No reference'} · {dateTime(transaction.transaction_at)}</span><strong>{money(transaction.amount)}</strong></li>)}</ul> : <p className="mt-2 text-sm text-slate-600">No transactions were recorded during this shift.</p>}</div>}</Card>}
            </>}
        </div>
    </AppShell>;
}
