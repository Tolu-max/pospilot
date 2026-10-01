import { useForm } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import { money, dateTime } from '../../lib/api';
import ProviderLogo from '../../Components/ProviderLogo';

const label = (value) => String(value || 'Not recorded').replaceAll('_', ' ');

export default function Show({ transaction, terminals = [], canAssignTerminal = false }) {
    const form = useForm({ customer_charge_override: transaction.customer_charge_override || '' });
    const terminalForm = useForm({ terminal_id: transaction.terminal_id ? String(transaction.terminal_id) : '' });
    const financial = transaction.financial_status || {};
    const isFinal = financial.is_final === true;
    const isSuccessful = transaction.transaction_status === 'successful';
    const isPersonalWalletActivity = transaction.metadata?.activity_scope === 'personal_wallet';
    const adjustments = transaction.adjustments || [];

    function save(event) {
        event.preventDefault();
        form.patch(`/transactions/${transaction.id}/customer-charge`, { preserveScroll: true });
    }

    function saveTerminal(event) {
        event.preventDefault();
        terminalForm.patch(`/transactions/${transaction.id}/terminal`, { preserveScroll: true });
    }

    return <AppShell title="Transaction details">
        <div className="transactions-page transaction-detail-page">
            <header className="transactions-heading">
                <div>
                    <span className="eyebrow"><span className="eyebrow-dot" /> TRANSACTION RECORD</span>
                    <h1>{isPersonalWalletActivity ? `${transaction.provider?.name || 'Provider'} wallet activity` : transaction.provider?.name || 'Provider transaction'}</h1>
                    <p>{isPersonalWalletActivity ? 'Personal wallet activity for testing. This is not POS-terminal activity.' : 'Review each amount separately. The transaction amount is not your earnings.'}</p>
                </div>
                <a href="/transactions" className="tx-action-button">← Transactions</a>
            </header>

            {!isFinal && !isPersonalWalletActivity && <div className="financial-confidence" role="status">
                <strong>This earnings figure is provisional.</strong>
                <span>Some provider fee or financial data has not been verified yet{financial.reasons?.length ? ` (${financial.reasons.map(label).join(', ')})` : ''}. Do not treat it as final.</span>
            </div>}

            <div className="transaction-detail-summary">
                <section className="transaction-detail-amount">
                    <span className="tx-detail-label">{isPersonalWalletActivity ? 'Wallet movement' : 'Transaction amount'}</span>
                    <strong>{money(transaction.amount)}</strong>
                    <p>{isPersonalWalletActivity ? 'This amount is shown as account activity and is not counted as POS sales.' : 'This is the customer’s transaction value, not agent revenue.'}</p>
                </section>
                <section className="transaction-detail-earnings">
                    <div><span className="tx-detail-label">{isPersonalWalletActivity ? 'POS earnings' : isSuccessful ? 'Estimated earnings' : 'Earnings contribution'}</span><span className={`tx-completeness ${isPersonalWalletActivity || isFinal ? 'tx-completeness-final' : 'tx-completeness-provisional'}`}>{isPersonalWalletActivity ? 'Not applicable' : !isSuccessful ? 'Not included' : isFinal ? 'Complete data' : 'Provisional'}</span></div>
                    <strong>{isPersonalWalletActivity ? '—' : money(transaction.estimated_earnings)}</strong>
                    <p>{isPersonalWalletActivity ? 'Personal wallet activity is excluded from POS earnings.' : isSuccessful ? 'Customer charge, less recorded provider deductions, plus verified credits.' : 'Only successful transactions contribute to finalized earnings.'}</p>
                </section>
            </div>

            <div className="transaction-detail-columns">
                <section className="transaction-details-panel">
                    <div className="tx-details-heading"><div><h2>Money breakdown</h2><p>Each amount is shown separately.</p></div></div>
                    {isPersonalWalletActivity ? <p className="rounded-xl bg-slate-50 p-4 text-sm leading-6 text-slate-700">POSPilot imported this real wallet statement row for connection testing. Provider fees and POS earnings are not inferred from personal account activity.</p> : <dl className="tx-financials tx-financials-expanded">
                        <FinancialRow label="Principal transaction amount" value={money(transaction.amount)} note="Not counted as earnings" />
                        <FinancialRow label="Customer charge" value={money(transaction.customer_charge)} note={`Source: ${label(transaction.customer_charge_source)}`} />
                        <FinancialRow label="Provider fee" value={transaction.provider_fee_supplied === false ? 'Not supplied by provider' : money(transaction.provider_fee)} note={transaction.provider_fee_supplied === false ? 'Earnings stay provisional until verified fee data is available.' : 'Provider-reported or recorded fee'} />
                        {adjustments.filter((adjustment) => adjustment.type !== 'customer_charge').map((adjustment) => <FinancialRow key={adjustment.id} label={`${label(adjustment.type)} (${label(adjustment.direction)})`} value={`${adjustment.direction === 'debit' ? '−' : '+'}${money(adjustment.amount)}`} note={`Source: ${label(adjustment.source)}`} />)}
                        <div className="tx-financial-row tx-financial-row-emphasis"><span>Estimated earnings contribution</span><strong>{money(transaction.estimated_earnings)}</strong></div>
                    </dl>}

                    {!isPersonalWalletActivity && <form onSubmit={save} className="transaction-charge-form">
                        <h3>Correct the customer charge</h3>
                        <p>A manual override is recorded without removing the imported or calculated value. Leave the field blank to restore that value.</p>
                        <div className="transaction-charge-form-row">
                            <label htmlFor="customer-charge-override">Manual charge (₦)</label>
                            <input id="customer-charge-override" name="customer_charge_override" inputMode="decimal" value={form.data.customer_charge_override} onChange={(event) => form.setData('customer_charge_override', event.target.value)} aria-invalid={Boolean(form.errors.customer_charge_override)} aria-describedby={form.errors.customer_charge_override ? 'charge-override-error' : undefined} />
                            {form.errors.customer_charge_override && <span id="charge-override-error" className="transaction-form-error">{form.errors.customer_charge_override}</span>}
                            <button className="tx-action-button tx-action-button-primary" type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save charge'}</button>
                        </div>
                        {form.recentlySuccessful && <p role="status" className="transaction-form-success">Customer charge updated.</p>}
                    </form>}
                </section>

                <section className="transaction-details-panel">
                    <div className="tx-details-heading"><div><h2>Record details</h2><p>Provider activity and source information.</p></div></div>
                    <dl className="tx-detail-meta tx-detail-meta-expanded">
                        <DetailField label="Transaction status" value={label(transaction.transaction_status)} />
                        <DetailField label="Settlement status" value={label(transaction.settlement_status)} />
                        <DetailField label="Provider" value={<span className="detail-provider"><ProviderLogo provider={transaction.provider} size="sm" />{transaction.provider?.name || 'Not recorded'}</span>} />
                        <DetailField label={isPersonalWalletActivity ? 'Activity type' : 'Terminal'} value={isPersonalWalletActivity ? 'Personal wallet statement' : transaction.terminal?.name || 'Not recorded'} />
                        {!isPersonalWalletActivity && transaction.provider_account && <DetailField label="Provider account" value={transaction.provider_account.display_name} />}
                        <DetailField label="Reference" value={transaction.external_reference || 'Not supplied'} />
                        <DetailField label="Transaction time" value={dateTime(transaction.transaction_at)} />
                        <DetailField label="Record source" value={label(transaction.source)} />
                        {transaction.import_batch && <DetailField label="Import file" value={transaction.import_batch.filename} />}
                    </dl>
                    {canAssignTerminal && !isPersonalWalletActivity && terminals.length > 0 && <form onSubmit={saveTerminal} className="mt-5 rounded-xl border border-brand-line p-4">
                        <h3 className="font-bold">Terminal assignment</h3>
                        <p className="mt-1 text-sm text-slate-600">Keep this transaction unassigned or link it to a known terminal. The statement will not be imported again.</p>
                        <div className="mt-3 flex flex-wrap gap-3">
                            <select aria-label="Assign terminal" value={terminalForm.data.terminal_id} onChange={(event) => terminalForm.setData('terminal_id', event.target.value)} className="min-h-11 min-w-56 rounded-lg border border-brand-line px-3">
                                <option value="">Unassigned terminal</option>
                                {terminals.map((terminal) => <option key={terminal.id} value={terminal.id}>{terminal.name}</option>)}
                            </select>
                            <button className="tx-action-button tx-action-button-primary" type="submit" disabled={terminalForm.processing || terminalForm.data.terminal_id === String(transaction.terminal_id || '')}>{terminalForm.processing ? 'Saving…' : 'Save assignment'}</button>
                        </div>
                        {terminalForm.recentlySuccessful && <p role="status" className="mt-2 text-sm font-semibold text-emerald-800">Terminal assignment updated.</p>}
                        {terminalForm.errors.terminal_id && <p role="alert" className="mt-2 text-sm text-red-700">{terminalForm.errors.terminal_id}</p>}
                    </form>}
                </section>
            </div>
        </div>
    </AppShell>;
}

function FinancialRow({ label: title, value, note }) {
    return <div className="tx-financial-row tx-financial-row-expanded"><span><span>{title}</span>{note && <small>{note}</small>}</span><strong>{value}</strong></div>;
}

function DetailField({ label: title, value }) {
    return <div className="tx-detail-field"><span className="tx-detail-label">{title}</span><strong>{value}</strong></div>;
}
