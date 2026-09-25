import { useMemo, useState } from 'react'

const tabs = [
    'Dashboard', 'Profile', 'Providers & terminals', 'Charge rules', 'Transactions',
    'Transaction import', 'Expenses', 'Settlement import', 'Reconciliation',
    'Daily closing', 'Moniepoint',
]

async function request(path, { method = 'GET', values = {}, file = null } = {}) {
    const headers = { Accept: 'application/json' }
    const options = { method, credentials: 'same-origin', headers }

    if (!['GET', 'HEAD'].includes(method)) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
        if (csrf) headers['X-CSRF-TOKEN'] = csrf

        if (file) {
            const body = new FormData()
            Object.entries(values).forEach(([key, value]) => {
                if (value !== '' && value !== undefined) body.append(key, value)
            })
            body.append('file', file)
            options.body = body
        } else if (Object.keys(values).length > 0) {
            headers['Content-Type'] = 'application/json'
            options.body = JSON.stringify(values)
        }
    }

    const response = await fetch(path, options)
    const contentType = response.headers.get('content-type') || ''
    const body = contentType.includes('application/json')
        ? await response.json()
        : { message: response.ok ? 'Request succeeded (non-JSON response).' : 'Request failed. Check authentication and the server response.' }

    return { http_status: response.status, ok: response.ok, body }
}

function Result({ value }) {
    if (!value) return null

    const redact = (item) => {
        if (Array.isArray(item)) return item.map(redact)
        if (item && typeof item === 'object') {
            return Object.fromEntries(Object.entries(item).map(([key, nested]) => [
                key,
                /(api[_-]?key|webhook[_-]?secret|private[_-]?key|secret|password|pin|otp)/i.test(key) ? '[redacted]' : redact(nested),
            ]))
        }

        return item
    }

    return (
        <pre className="mt-3 max-h-80 overflow-auto rounded border border-slate-200 bg-slate-50 p-3 text-xs leading-5 text-slate-800">
            {JSON.stringify(redact(value), null, 2)}
        </pre>
    )
}

function ApiAction({ label, method = 'GET', path, fields = [], multipart = false, onResult, clearAfterSuccess = false }) {
    const initial = useMemo(() => Object.fromEntries(fields.map((field) => [field.name, field.defaultValue ?? ''])), [fields])
    const [values, setValues] = useState(initial)
    const [file, setFile] = useState(null)
    const [result, setResult] = useState(null)
    const [busy, setBusy] = useState(false)

    async function submit(event) {
        event.preventDefault()
        setBusy(true)
        const query = method === 'GET'
            ? new URLSearchParams(Object.fromEntries(Object.entries(values).filter(([, value]) => value !== ''))).toString()
            : ''
        const resolvedPath = typeof path === 'function' ? path(values) : path
        const url = query ? `${resolvedPath}${resolvedPath.includes('?') ? '&' : '?'}${query}` : resolvedPath
        const payload = method === 'GET' ? {} : Object.fromEntries(Object.entries(values).filter(([, value]) => value !== ''))

        try {
            const response = await request(url, { method, values: payload, file: multipart ? file : null })
            setResult(response)
            onResult?.(response)
            if (clearAfterSuccess && response.ok) {
                setValues(Object.fromEntries(fields.map((field) => [field.name, ''])))
                setFile(null)
                event.currentTarget.reset()
            }
        } catch {
            setResult({ http_status: 'network error', ok: false, body: { message: 'Could not reach the same-origin application.' } })
        } finally {
            setBusy(false)
        }
    }

    return (
        <form onSubmit={submit} className="rounded-lg border border-slate-200 bg-white p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
                <h3 className="font-medium text-slate-900">{label}</h3>
                <span className="rounded bg-slate-100 px-2 py-1 font-mono text-xs text-slate-600">{method}</span>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {fields.map((field) => (
                    <label key={field.name} className={`block text-sm text-slate-700 ${field.wide ? 'sm:col-span-2' : ''}`}>
                        <span className="mb-1 block">{field.label || field.name}</span>
                        {field.type === 'textarea' ? (
                            <textarea name={field.name} value={values[field.name]} rows={field.rows || 3} required={field.required} onChange={(event) => setValues({ ...values, [field.name]: event.target.value })} className="w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm" />
                        ) : field.type === 'select' ? (
                            <select name={field.name} value={values[field.name]} required={field.required} onChange={(event) => setValues({ ...values, [field.name]: event.target.value })} className="w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm">
                                <option value="">Select…</option>
                                {field.options.map((option) => <option key={option} value={option}>{option}</option>)}
                            </select>
                        ) : (
                            <input name={field.name} type={field.type || 'text'} value={values[field.name]} required={field.required} placeholder={field.placeholder} onChange={(event) => setValues({ ...values, [field.name]: event.target.value })} className="w-full rounded border border-slate-300 px-3 py-2 text-sm" />
                        )}
                    </label>
                ))}
                {multipart && (
                    <label className="block text-sm text-slate-700 sm:col-span-2">
                        <span className="mb-1 block">CSV file (max 5 MB)</span>
                        <input type="file" accept=".csv,.txt,text/csv" required onChange={(event) => setFile(event.target.files?.[0] || null)} className="w-full rounded border border-slate-300 px-3 py-2 text-sm" />
                    </label>
                )}
            </div>
            <button type="submit" disabled={busy || (multipart && !file)} className="mt-3 rounded bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-50">
                {busy ? 'Working…' : label}
            </button>
            <Result value={result} />
        </form>
    )
}

function Panel({ title, description, children }) {
    return (
        <section className="space-y-4">
            <div>
                <h2 className="text-xl font-semibold text-slate-900">{title}</h2>
                {description && <p className="mt-1 text-sm text-slate-600">{description}</p>}
            </div>
            <div className="grid gap-4 lg:grid-cols-2">{children}</div>
        </section>
    )
}

const date = new Date().toISOString().slice(0, 10)

export default function Index() {
    const [activeTab, setActiveTab] = useState(tabs[0])
    const [transactionToken, setTransactionToken] = useState('')
    const [settlementToken, setSettlementToken] = useState('')
    const [dailyClosingId, setDailyClosingId] = useState('')

    return (
        <main className="min-h-screen bg-white text-slate-900">
            <header className="border-b border-green-200 bg-green-50 px-4 py-5 sm:px-8">
                <div className="mx-auto max-w-7xl">
                    <p className="text-xs font-semibold uppercase tracking-wider text-green-800">Internal only · throwaway QA</p>
                    <h1 className="mt-1 text-2xl font-bold">QA / Integration Frontend</h1>
                    <p className="mt-1 text-sm text-slate-600">Exercises the current POSPilot backend contract. Not the production interface.</p>
                    <div className="mt-3 flex gap-4 text-sm">
                        <a className="text-green-800 underline" href="/login">Existing login</a>
                        <a className="text-green-800 underline" href="/register">Existing registration</a>
                    </div>
                </div>
            </header>

            <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-8 lg:grid-cols-[220px_1fr]">
                <nav aria-label="QA sections" className="flex gap-2 overflow-x-auto pb-2 lg:flex-col lg:overflow-visible">
                    {tabs.map((tab) => (
                        <button key={tab} onClick={() => setActiveTab(tab)} className={`shrink-0 rounded px-3 py-2 text-left text-sm ${activeTab === tab ? 'bg-green-700 text-white' : 'bg-slate-100 text-slate-700 hover:bg-green-50'}`}>
                            {tab}
                        </button>
                    ))}
                </nav>

                <div className="min-w-0 space-y-4">
                    {activeTab === 'Dashboard' && <Panel title="Financial summary" description="Backend values and confidence metadata are authoritative; this page does not recalculate money.">
                        <ApiAction label="Load summary" path="/api/financial-summary" fields={[{ name: 'from', type: 'date' }, { name: 'to', type: 'date' }]} />
                        <ApiAction label="Provider breakdown" path="/api/provider-breakdown" fields={[{ name: 'from', type: 'date' }, { name: 'to', type: 'date' }]} />
                    </Panel>}

                    {activeTab === 'Profile' && <Panel title="Agent profile">
                        <ApiAction label="Read profile" path="/api/agent/profile" />
                        <ApiAction label="Update profile" method="PATCH" path="/api/agent/profile" fields={[
                            { name: 'business_name' }, { name: 'phone' }, { name: 'country', defaultValue: 'Nigeria' },
                            { name: 'currency', defaultValue: 'NGN' }, { name: 'location' },
                            { name: 'onboarding_state', type: 'select', options: ['not_started', 'in_progress', 'completed'] },
                        ]} />
                    </Panel>}

                    {activeTab === 'Providers & terminals' && <Panel title="Providers, connections & terminals">
                        <ApiAction label="Available providers" path="/api/providers" />
                        <ApiAction label="Provider connection states" path="/api/provider-connections" />
                        <ApiAction label="List terminals" path="/api/terminals" />
                        <ApiAction label="Create terminal" method="POST" path="/api/terminals" fields={[{ name: 'provider_id', required: true }, { name: 'name', required: true }, { name: 'terminal_identifier' }, { name: 'active', defaultValue: '1' }]} />
                        <ApiAction label="Update terminal" method="PATCH" path={(values) => `/api/terminals/${encodeURIComponent(values.terminal_id || '0')}`} fields={[{ name: 'terminal_id', required: true }, { name: 'name' }, { name: 'terminal_identifier' }]} />
                        <ApiAction label="Activate / deactivate terminal" method="PATCH" path={(values) => `/api/terminals/${encodeURIComponent(values.terminal_id || '0')}/status`} fields={[{ name: 'terminal_id', required: true }, { name: 'active', type: 'select', options: ['1', '0'], required: true }]} />
                        <ApiAction label="Delete terminal" method="DELETE" path={(values) => `/api/terminals/${encodeURIComponent(values.terminal_id || '0')}`} fields={[{ name: 'terminal_id', required: true }]} />
                    </Panel>}

                    {activeTab === 'Charge rules' && <Panel title="Customer charge rules">
                        <ApiAction label="List rules" path="/api/charge-rules" />
                        <ApiAction label="Create rule" method="POST" path="/api/charge-rules" fields={[
                            { name: 'minimum_amount', required: true }, { name: 'maximum_amount' },
                            { name: 'charge_type', type: 'select', options: ['fixed', 'percentage'], required: true },
                            { name: 'charge_value', required: true }, { name: 'provider_id' }, { name: 'priority', defaultValue: '1' }, { name: 'active', defaultValue: '1' },
                        ]} />
                        <ApiAction label="Preview charge" path="/api/charge-rules/preview" fields={[{ name: 'amount', required: true }, { name: 'provider_id' }]} />
                        <ApiAction label="Update rule" method="PATCH" path={(values) => `/api/charge-rules/${encodeURIComponent(values.rule_id || '0')}`} fields={[{ name: 'rule_id', required: true }, { name: 'minimum_amount' }, { name: 'maximum_amount' }, { name: 'charge_value' }, { name: 'active' }]} />
                        <ApiAction label="Delete rule" method="DELETE" path={(values) => `/api/charge-rules/${encodeURIComponent(values.rule_id || '0')}`} fields={[{ name: 'rule_id', required: true }]} />
                    </Panel>}

                    {activeTab === 'Transactions' && <Panel title="Transactions">
                        <ApiAction label="List / filter transactions" path="/api/transactions" fields={[
                            { name: 'provider_id' }, { name: 'terminal_id' },
                            { name: 'transaction_status', type: 'select', options: ['successful', 'pending', 'failed', 'reversed'] },
                            { name: 'settlement_status', type: 'select', options: ['pending', 'settled', 'unreconciled', 'disputed'] },
                            { name: 'from', type: 'date' }, { name: 'to', type: 'date' }, { name: 'reference' }, { name: 'per_page', defaultValue: '25' },
                        ]} />
                        <ApiAction label="Transaction detail" path={(values) => `/api/transactions/${encodeURIComponent(values.transaction_id || '0')}`} fields={[{ name: 'transaction_id', required: true }]} />
                        <ApiAction label="Override customer charge" method="PATCH" path={(values) => `/api/transactions/${encodeURIComponent(values.transaction_id || '0')}/customer-charge`} fields={[{ name: 'transaction_id', required: true }, { name: 'customer_charge_override', placeholder: 'Decimal amount; leave blank to clear override' }]} />
                    </Panel>}

                    {activeTab === 'Transaction import' && <Panel title="Transaction CSV import" description="Preview first. Confirmation consumes the session-bound preview token and does not re-upload the CSV.">
                        <ApiAction label="Preview transaction CSV" method="POST" path="/transactions/import/preview" multipart onResult={(response) => setTransactionToken(response.body?.preview_token || '')} fields={[{ name: 'provider_id', required: true }]} />
                        <ApiAction key={`transaction-confirm-${transactionToken}`} label="Confirm transaction import" method="POST" path="/transactions/import/confirm" fields={[{ name: 'preview_token', required: true, defaultValue: transactionToken }]} />
                        <ApiAction label="Import history" path="/api/imports" />
                        <ApiAction label="Import batch detail" path={(values) => `/api/imports/${encodeURIComponent(values.batch_id || '0')}`} fields={[{ name: 'batch_id', required: true }]} />
                    </Panel>}

                    {activeTab === 'Expenses' && <Panel title="Operating expenses">
                        <ApiAction label="List expenses" path="/api/expenses" fields={[{ name: 'per_page', defaultValue: '25' }]} />
                        <ApiAction label="Add expense" method="POST" path="/api/expenses" fields={[
                            { name: 'amount', required: true }, { name: 'category', type: 'select', options: ['transport', 'power', 'staff', 'cash handling', 'miscellaneous'], required: true },
                            { name: 'description' }, { name: 'expense_date', type: 'date', defaultValue: date, required: true },
                        ]} />
                        <ApiAction label="Update expense" method="PATCH" path={(values) => `/api/expenses/${encodeURIComponent(values.expense_id || '0')}`} fields={[{ name: 'expense_id', required: true }, { name: 'amount' }, { name: 'category' }, { name: 'description' }, { name: 'expense_date', type: 'date' }]} />
                        <ApiAction label="Delete expense" method="DELETE" path={(values) => `/api/expenses/${encodeURIComponent(values.expense_id || '0')}`} fields={[{ name: 'expense_id', required: true }]} />
                    </Panel>}

                    {activeTab === 'Settlement import' && <Panel title="Settlement CSV import">
                        <ApiAction label="Preview settlement CSV" method="POST" path="/settlements/import/preview" multipart onResult={(response) => setSettlementToken(response.body?.preview_token || '')} fields={[{ name: 'provider_id', required: true }]} />
                        <ApiAction key={`settlement-confirm-${settlementToken}`} label="Confirm settlement import" method="POST" path="/settlements/import/confirm" fields={[{ name: 'preview_token', required: true, defaultValue: settlementToken }]} />
                        <ApiAction label="Settlement list" path="/api/settlements" />
                        <ApiAction label="Settlement detail" path={(values) => `/api/settlements/${encodeURIComponent(values.settlement_id || '0')}`} fields={[{ name: 'settlement_id', required: true }]} />
                    </Panel>}

                    {activeTab === 'Reconciliation' && <Panel title="Reconciliation">
                        <ApiAction label="Overview" path="/api/reconciliation/overview" fields={[{ name: 'from', type: 'date' }, { name: 'to', type: 'date' }]} />
                        <ApiAction label="Issues" path="/api/reconciliation/issues" fields={[{ name: 'from', type: 'date' }, { name: 'to', type: 'date' }]} />
                    </Panel>}

                    {activeTab === 'Daily closing' && <Panel title="Daily closing" description="Amounts and variances are calculated by the backend. Use a draft ID returned by save before adding balances/finalizing.">
                        <ApiAction label="Preview closing" path="/api/daily-closings/preview" fields={[{ name: 'closing_date', type: 'date', defaultValue: date }]} />
                        <ApiAction label="Save draft" method="POST" path="/api/daily-closings" fields={[{ name: 'closing_date', type: 'date', defaultValue: date }, { name: 'opening_cash' }, { name: 'entered_closing_cash' }, { name: 'notes' }]} onResult={(response) => setDailyClosingId(response.body?.id || response.body?.daily_closing?.id || '')} />
                        <ApiAction label="Closing history" path="/api/daily-closings" fields={[{ name: 'from', type: 'date' }, { name: 'to', type: 'date' }, { name: 'per_page', defaultValue: '25' }]} />
                        <ApiAction label="Closing details" path={(values) => `/api/daily-closings/${encodeURIComponent(values.closing_id || '0')}`} fields={[{ name: 'closing_id', required: true, defaultValue: dailyClosingId }]} />
                        <ApiAction label="Enter provider / terminal balance" method="POST" path={(values) => `/api/daily-closings/${encodeURIComponent(values.closing_id || '0')}/balances`} fields={[{ name: 'closing_id', required: true, defaultValue: dailyClosingId }, { name: 'provider_id', required: true }, { name: 'terminal_id' }, { name: 'actual_balance' }]} />
                        <ApiAction label="Variance breakdown" path={(values) => `/api/daily-closings/${encodeURIComponent(values.closing_id || '0')}/breakdown`} fields={[{ name: 'closing_id', required: true, defaultValue: dailyClosingId }]} />
                        <ApiAction label="Finalize closing" method="POST" path={(values) => `/api/daily-closings/${encodeURIComponent(values.closing_id || '0')}/finalize`} fields={[{ name: 'closing_id', required: true, defaultValue: dailyClosingId }]} />
                    </Panel>}

                    {activeTab === 'Moniepoint' && <Panel title="Moniepoint connection" description="Status and credential actions use the provider-specific authenticated endpoints. No credential is saved in browser storage or displayed by this page.">
                        <ApiAction label="Connection status" path="/api/providers/moniepoint/connection" />
                        <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 lg:col-span-2">
                            Credentials form warning: use fictional/local credentials only. Never enter your Moniepoint dashboard password, PIN, OTP, or production credentials here. Values are kept only in this page's in-memory form state, are not logged or stored in localStorage, and fields are cleared after a successful submit.
                        </div>
                        <ApiAction label="Save/update integration credentials" method="PUT" path="/api/providers/moniepoint/connection" clearAfterSuccess fields={[
                            { name: 'api_key', type: 'password', required: true }, { name: 'webhook_secret', type: 'password', required: true },
                            { name: 'business_id', required: true },
                        ]} />
                        <ApiAction label="Test connection" method="POST" path="/api/providers/moniepoint/connection/test" />
                        <ApiAction label="Disconnect" method="DELETE" path="/api/providers/moniepoint/connection" />
                    </Panel>}

                    <footer className="border-t border-slate-200 pt-4 text-xs text-slate-500">
                        Requests use the current same-origin Laravel routes, session cookies, JSON responses, and the page CSRF meta token. This internal page does not use mock data or calculate authoritative financial values.
                    </footer>
                </div>
            </div>
        </main>
    )
}
