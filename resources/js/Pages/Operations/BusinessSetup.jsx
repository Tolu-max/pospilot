import { useEffect, useState } from 'react';
import AppShell from '../../Layouts/AppShell';
import { api, money, today } from '../../lib/api';
import { Button, Card, EmptyState, ErrorNotice, Field, LoadingCard, Notice, SelectField } from '../../Components/PosPilotUI';
import ProviderLogo from '../../Components/ProviderLogo';
import { usePage } from '@inertiajs/react';

export default function BusinessSetup({ expensesOnly = false }) {
    const { workspace } = usePage().props;
    const canSeeFinancialSummary = workspace?.role === 'owner';
    const [providers, setProviders] = useState([]);
    const [terminals, setTerminals] = useState([]);
    const [rules, setRules] = useState([]);
    const [expenses, setExpenses] = useState([]);
    const [expenseSummary, setExpenseSummary] = useState(null);
    const [terminalForm, setTerminalForm] = useState({ provider_id: '', name: '', terminal_identifier: '' });
    const [ruleForm, setRuleForm] = useState({ provider_id: '', minimum_amount: '', maximum_amount: '', charge_type: 'fixed', charge_value: '', priority: '0' });
    const [expenseForm, setExpenseForm] = useState({ amount: '', category: 'transport', description: '', expense_date: today() });
    const [previewAmount, setPreviewAmount] = useState('');
    const [previewResult, setPreviewResult] = useState(null);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [message, setMessage] = useState('');
    const [deleteIntent, setDeleteIntent] = useState(null);
    const [editingTerminalId, setEditingTerminalId] = useState(null);
    const [editTerminalForm, setEditTerminalForm] = useState({ provider_id: '', name: '', terminal_identifier: '' });

    async function load() {
        setLoading(true); setError(null);
        try {
            const calls = expensesOnly ? [api('/api/expenses?per_page=50')] : [api('/api/providers'), api('/api/terminals'), api('/api/charge-rules')];
            if (expensesOnly && canSeeFinancialSummary) calls.push(api(`/api/financial-summary?from=${today()}&to=${today()}`));
            const result = await Promise.all(calls);
            if (!expensesOnly) { setProviders(result[0].data || []); setTerminals(result[1].data || []); setRules(result[2].data || []); }
            if (expensesOnly) {
                setExpenses(result[0].data?.data || result[0].data || []);
                setExpenseSummary(canSeeFinancialSummary ? result[1] : null);
            }
            if (!expensesOnly && !terminalForm.provider_id && result[0].data?.[0]) setTerminalForm((current) => ({ ...current, provider_id: String(result[0].data[0].id) }));
        } catch (requestError) { setError(requestError); }
        finally { setLoading(false); }
    }
    useEffect(() => { load(); }, [expensesOnly]);

    async function submit(endpoint, body, reset) {
        setBusy(true); setError(null); setMessage('');
        try { await api(endpoint, { method: 'POST', body }); reset(); setMessage('Saved successfully.'); await load(); }
        catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }
    async function remove(endpoint) {
        if (deleteIntent !== endpoint) {
            setDeleteIntent(endpoint);
            return;
        }
        setBusy(true); setError(null); setMessage('');
        try { await api(endpoint, { method: 'DELETE', body: {} }); setDeleteIntent(null); setMessage('Removed successfully.'); await load(); }
        catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }
    async function toggle(terminal) {
        try { await api(`/api/terminals/${terminal.id}/status`, { method: 'PATCH', body: { active: !terminal.active } }); await load(); }
        catch (requestError) { setError(requestError); }
    }
    async function saveTerminal(event) {
        event.preventDefault();
        setBusy(true); setError(null); setMessage('');
        try {
            await api(`/api/terminals/${editingTerminalId}`, { method: 'PATCH', body: { ...editTerminalForm, terminal_identifier: editTerminalForm.terminal_identifier || null } });
            setEditingTerminalId(null);
            setMessage('Terminal updated successfully.');
            await load();
        } catch (requestError) { setError(requestError); }
        finally { setBusy(false); }
    }
    async function preview(event) {
        event.preventDefault(); setError(null); setPreviewResult(null);
        try { const query = new URLSearchParams({ amount: previewAmount }); setPreviewResult(await api(`/api/charge-rules/preview?${query}`)); }
        catch (requestError) { setError(requestError); }
    }
    function submitExpense(event) {
        event.preventDefault();
        const formValues = Object.fromEntries(new FormData(event.currentTarget).entries());
        submit('/api/expenses', formValues, () => setExpenseForm((current) => ({ ...current, amount: '', description: '' })));
    }

    return <AppShell title={expensesOnly ? 'Expenses' : 'Terminals and pricing'}><div className="ops-page"><header className="ops-heading"><div><span className="eyebrow"><span className="eyebrow-dot" /> BUSINESS SETUP</span><h1>{expensesOnly ? 'Business expenses' : 'Terminals and customer charges'}</h1><p>{expensesOnly ? 'Record straightforward operating expenses so they are included in your earnings summaries.' : 'Tell POSPilot which terminals you use and how you charge customers. You can use the same provider for more than one terminal.'}</p></div></header>
        {error && <div className="mb-5"><ErrorNotice error={error} /><Button type="button" variant="secondary" className="mt-3" onClick={load}>Try again</Button></div>}{message && <div className="mb-5"><Notice tone="success">{message}</Notice></div>}{loading ? <LoadingCard /> : <div className="space-y-5">{expensesOnly && canSeeFinancialSummary && <Card className="border-0 bg-brand-accent text-white"><p className="text-sm font-bold">Today's expenses</p><p className="mt-2 text-3xl font-black">{money(expenseSummary?.expenses)}</p><p className="mt-2 text-sm text-emerald-50">Expenses reduce your estimated business earnings.</p></Card>}{!expensesOnly && <>
            <Card><h2 className="text-lg font-extrabold">Add a terminal</h2><form className="mt-4 grid gap-4 sm:grid-cols-3" onSubmit={(event) => { event.preventDefault(); submit('/api/terminals', terminalForm, () => setTerminalForm({ ...terminalForm, name: '', terminal_identifier: '' })); }}><SelectField label="Provider" name="provider_id" value={terminalForm.provider_id} onChange={(event) => setTerminalForm({ ...terminalForm, provider_id: event.target.value })}>{providers.map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</SelectField><Field label="Terminal name" name="terminal_name" value={terminalForm.name} onChange={(event) => setTerminalForm({ ...terminalForm, name: event.target.value })} required placeholder="Main counter" /><Field label="Terminal ID (optional)" name="terminal_identifier" value={terminalForm.terminal_identifier} onChange={(event) => setTerminalForm({ ...terminalForm, terminal_identifier: event.target.value })} placeholder="Printed on device" /><div className="sm:col-span-3"><Button type="submit" disabled={busy || !providers.length}>Add terminal</Button></div></form><div className="mt-5">{terminals.length ? <div className="divide-y divide-slate-100">{terminals.map((terminal) => <div key={terminal.id} className="py-3"><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="font-bold inline-flex items-center gap-2"><ProviderLogo provider={terminal.provider} size="sm" />{terminal.name}</p><p className="text-sm text-slate-600">{terminal.provider?.name} · {terminal.terminal_identifier || 'No terminal ID'}</p></div><div className="flex flex-wrap items-center gap-2"><span className="text-sm text-slate-600">{terminal.active ? 'Active' : 'Inactive'}</span><Button type="button" variant="secondary" onClick={() => { setEditingTerminalId(terminal.id); setEditTerminalForm({ provider_id: String(terminal.provider_id), name: terminal.name, terminal_identifier: terminal.terminal_identifier || '' }); }}>Edit</Button><Button type="button" variant="secondary" onClick={() => toggle(terminal)}>{terminal.active ? 'Deactivate' : 'Activate'}</Button><Button type="button" variant="secondary" onClick={() => remove(`/api/terminals/${terminal.id}`)}>{deleteIntent === `/api/terminals/${terminal.id}` ? 'Confirm delete' : 'Delete'}</Button>{deleteIntent === `/api/terminals/${terminal.id}` && <Button type="button" variant="secondary" onClick={() => setDeleteIntent(null)}>Cancel</Button>}</div></div>{editingTerminalId === terminal.id && <form className="mt-3 grid gap-3 rounded-xl border border-brand-line bg-brand-canvas p-4 sm:grid-cols-3" onSubmit={saveTerminal}><SelectField label="Provider" name={`edit_provider_${terminal.id}`} value={editTerminalForm.provider_id} onChange={(event) => setEditTerminalForm({ ...editTerminalForm, provider_id: event.target.value })}>{providers.map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</SelectField><Field label="Terminal name" name={`edit_name_${terminal.id}`} value={editTerminalForm.name} onChange={(event) => setEditTerminalForm({ ...editTerminalForm, name: event.target.value })} required /><Field label="Terminal ID (optional)" name={`edit_identifier_${terminal.id}`} value={editTerminalForm.terminal_identifier} onChange={(event) => setEditTerminalForm({ ...editTerminalForm, terminal_identifier: event.target.value })} /><div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={busy}>Save changes</Button><Button type="button" variant="secondary" onClick={() => setEditingTerminalId(null)}>Cancel edit</Button></div></form>}</div>)}</div> : <p className="text-sm text-slate-600">No terminals added yet.</p>}</div></Card>
            <Card><h2 className="text-lg font-extrabold">Customer charge rules</h2><p className="mt-1 text-sm text-slate-600">Charges are separate from transaction principal. Equal-priority ranges for the same provider cannot overlap.</p><form className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" onSubmit={(event) => { event.preventDefault(); const body = { ...ruleForm, provider_id: ruleForm.provider_id || null, maximum_amount: ruleForm.maximum_amount || null, priority: Number(ruleForm.priority) }; submit('/api/charge-rules', body, () => setRuleForm({ ...ruleForm, minimum_amount: '', maximum_amount: '', charge_value: '' })); }}><SelectField label="Provider (optional)" name="rule_provider" value={ruleForm.provider_id} onChange={(event) => setRuleForm({ ...ruleForm, provider_id: event.target.value })}><option value="">All providers</option>{providers.map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</SelectField><Field label="Minimum transaction" name="minimum_amount" value={ruleForm.minimum_amount} inputMode="decimal" onChange={(event) => setRuleForm({ ...ruleForm, minimum_amount: event.target.value })} required /><Field label="Maximum (optional)" name="maximum_amount" value={ruleForm.maximum_amount} inputMode="decimal" onChange={(event) => setRuleForm({ ...ruleForm, maximum_amount: event.target.value })} /><SelectField label="Charge method" name="charge_type" value={ruleForm.charge_type} onChange={(event) => setRuleForm({ ...ruleForm, charge_type: event.target.value })}><option value="fixed">Fixed naira amount</option><option value="percentage">Percentage</option></SelectField><Field label={ruleForm.charge_type === 'fixed' ? 'Charge amount (₦)' : 'Charge rate (%)'} name="charge_value" value={ruleForm.charge_value} inputMode="decimal" onChange={(event) => setRuleForm({ ...ruleForm, charge_value: event.target.value })} required /><Field label="Priority" name="priority" type="text" inputMode="numeric" value={ruleForm.priority} onChange={(event) => setRuleForm({ ...ruleForm, priority: event.target.value })} /><div className="sm:col-span-2 lg:col-span-3"><Button type="submit" disabled={busy}>Add charge rule</Button></div></form><div className="mt-5 divide-y divide-slate-100">{rules.map((rule) => { const endpoint = `/api/charge-rules/${rule.id}`; return <div key={rule.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><div><p className="font-bold">{money(rule.minimum_amount)} to {rule.maximum_amount ? money(rule.maximum_amount) : 'no limit'} → {rule.charge_type === 'fixed' ? money(rule.charge_value) : `${rule.charge_value}%`}</p><p className="text-sm text-slate-600">{rule.provider?.name || 'All providers'} · Priority {rule.priority} · {rule.active ? 'Active' : 'Inactive'}</p></div><div className="flex gap-2"><Button type="button" variant="secondary" onClick={() => remove(endpoint)}>{deleteIntent === endpoint ? 'Confirm delete' : 'Delete'}</Button>{deleteIntent === endpoint && <Button type="button" variant="secondary" onClick={() => setDeleteIntent(null)}>Cancel</Button>}</div></div>; })}</div><form onSubmit={preview} className="mt-5 flex flex-col gap-3 border-t border-brand-line pt-5 sm:flex-row sm:items-end"><Field label="Try a transaction amount" name="preview_amount" inputMode="decimal" value={previewAmount} onChange={(event) => setPreviewAmount(event.target.value)} placeholder="10000" required className="sm:max-w-xs" /><Button type="submit" variant="secondary">Preview charge</Button>{previewResult && <p className="text-sm font-bold text-brand-accent">Expected customer charge: {money(previewResult.charge)}</p>}</form></Card>
        </>}
        <Card><h2 className="text-lg font-extrabold">Add an expense</h2><form className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" onSubmit={submitExpense}><Field label="Amount (₦)" name="amount" inputMode="decimal" value={expenseForm.amount} onChange={(event) => setExpenseForm((current) => ({ ...current, amount: event.target.value }))} required /><SelectField label="Category" name="category" value={expenseForm.category} onChange={(event) => setExpenseForm((current) => ({ ...current, category: event.target.value }))}>{['transport', 'power', 'staff', 'cash handling', 'miscellaneous'].map((category) => <option key={category}>{category}</option>)}</SelectField><Field label="Date" name="expense_date" type="date" value={expenseForm.expense_date} onChange={(event) => setExpenseForm((current) => ({ ...current, expense_date: event.target.value }))} required /><Field label="Description (optional)" name="description" value={expenseForm.description} onChange={(event) => setExpenseForm((current) => ({ ...current, description: event.target.value }))} /><div className="sm:col-span-2 lg:col-span-4"><Button type="submit" disabled={busy}>Record expense</Button></div></form></Card>
        {expensesOnly && <Card><h2 className="text-lg font-extrabold">Recent expenses</h2>{expenses.length ? <div className="mt-3 divide-y divide-slate-100">{expenses.map((expense) => { const endpoint = `/api/expenses/${expense.id}`; return <div key={expense.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><div><p className="font-bold capitalize">{expense.category}</p><p className="text-sm text-slate-600">{expense.description || 'No description'} · {String(expense.expense_date).slice(0, 10)}</p></div><div className="flex items-center gap-3"><span className="font-extrabold">{money(expense.amount)}</span><Button type="button" variant="secondary" onClick={() => remove(endpoint)}>{deleteIntent === endpoint ? 'Confirm delete' : 'Delete'}</Button>{deleteIntent === endpoint && <Button type="button" variant="secondary" onClick={() => setDeleteIntent(null)}>Cancel</Button>}</div></div>; })}</div> : <EmptyState title="No expenses recorded" description="Add a business expense above. It will be included in estimated earnings for its date." />}</Card>}</div>}</div></AppShell>;
}
