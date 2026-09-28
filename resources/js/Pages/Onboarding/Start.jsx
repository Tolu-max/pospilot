import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import { api, money } from '../../lib/api';
import { showToast } from '../../lib/notifications';
import { Button, Card, ErrorNotice, Field, Notice, PageHeading, SelectField } from '../../Components/PosPilotUI';
import ProviderLogo from '../../Components/ProviderLogo';
import { trackSafeEvent } from '../../lib/analytics';

const defaultFeeBands = [
    { minimum_amount: '1000', maximum_amount: '5000', charge_type: 'fixed', charge_value: '' },
    { minimum_amount: '5001', maximum_amount: '10000', charge_type: 'fixed', charge_value: '' },
    { minimum_amount: '10001', maximum_amount: '15000', charge_type: 'fixed', charge_value: '' },
    { minimum_amount: '15001', maximum_amount: '30000', charge_type: 'fixed', charge_value: '' },
    { minimum_amount: '30001', maximum_amount: '', charge_type: 'fixed', charge_value: '' },
];
const supportedProviderOrder = ['moniepoint', 'opay', 'palmpay'];

function createFeeBand(values = {}) {
    return { key: globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`, ruleId: null, ...values };
}

export default function OnboardingStart({ userName = '', profile = null }) {
    const { auth } = usePage().props;
    const [step, setStep] = useState(1);
    const [values, setValues] = useState({ business_name: profile?.business_name || '', phone: profile?.phone || '', country: profile?.country || 'Nigeria', currency: profile?.currency || 'NGN', location: profile?.location || '' });
    const [providers, setProviders] = useState([]);
    const [providersLoading, setProvidersLoading] = useState(false);
    const [providerId, setProviderId] = useState('');
    const [selectedProviderSlugs, setSelectedProviderSlugs] = useState((profile?.selected_provider_slugs || []).filter((slug) => supportedProviderOrder.includes(slug)));
    const [terminal, setTerminal] = useState({ name: '', terminal_identifier: '' });
    const [terminals, setTerminals] = useState([]);
    const [feeBands, setFeeBands] = useState([]);
    const [removedFeeRuleIds, setRemovedFeeRuleIds] = useState([]);
    const [feeBandsLoading, setFeeBandsLoading] = useState(false);
    const [error, setError] = useState(null);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (step !== 2) return;
        let mounted = true;
        setProvidersLoading(true);
        api('/api/providers').then((result) => {
            if (!mounted) return;
            const available = (result.data || [])
                .filter((item) => supportedProviderOrder.includes(item.slug))
                .sort((first, second) => supportedProviderOrder.indexOf(first.slug) - supportedProviderOrder.indexOf(second.slug));
            setProviders(available);
            const currentProvider = available.find((item) => String(item.id) === String(providerId));
            if (!currentProvider || (selectedProviderSlugs.length > 0 && !selectedProviderSlugs.includes(currentProvider.slug))) {
                const selectedProvider = available.find((item) => selectedProviderSlugs.includes(item.slug));
                const moniepoint = available.find((item) => item.slug === 'moniepoint');
                setProviderId(String(selectedProvider?.id || moniepoint?.id || available[0]?.id || ''));
            }
        }).catch(setError).finally(() => mounted && setProvidersLoading(false));
        return () => { mounted = false; };
    }, [step]);

    useEffect(() => {
        if (step !== 4) return;
        let mounted = true;
        setFeeBandsLoading(true);
        api('/api/charge-rules').then((result) => {
            if (!mounted) return;
            const globalRules = (result.data || []).filter((item) => item.provider_id === null);
            if (globalRules.length > 0) {
                setFeeBands(globalRules.map((item) => createFeeBand({
                    ruleId: item.id,
                    minimum_amount: String(item.minimum_amount),
                    maximum_amount: item.maximum_amount === null ? '' : String(item.maximum_amount),
                    charge_type: item.charge_type,
                    charge_value: String(item.charge_value),
                })));
            } else {
                setFeeBands(defaultFeeBands.map((band) => createFeeBand(band)));
            }
        }).catch(setError).finally(() => mounted && setFeeBandsLoading(false));
        return () => { mounted = false; };
    }, [step]);

    function setProfileValue(key) {
        return (event) => setValues((current) => ({ ...current, [key]: event.target.value }));
    }

    async function saveProfile(event) {
        event.preventDefault(); setSaving(true); setError(null); setErrors({});
        try {
            const payload = { business_name: values.business_name, phone: values.phone || null, country: values.country, currency: values.currency, location: values.location || null, onboarding_state: 'in_progress' };
            const result = await api('/api/agent/profile', { method: 'PATCH', body: payload });
            setValues((current) => ({ ...current, business_name: result.business_name || current.business_name, phone: result.phone || '', country: result.country || current.country, currency: result.currency || current.currency, location: result.location || '' }));
            setStep(2);
        } catch (requestError) { setError(requestError); setErrors(requestError.errors || {}); }
        finally { setSaving(false); }
    }

    async function createTerminal() {
        setSaving(true); setError(null); setErrors({});
        try {
            const result = await api('/api/terminals', { method: 'POST', body: { provider_id: providerId, ...terminal } });
            setTerminals((current) => [...current, result]);
            setTerminal({ name: '', terminal_identifier: '' });
            showToast(`${result.name} added.`);
        } catch (requestError) { setError(requestError); setErrors(requestError.errors || {}); }
        finally { setSaving(false); }
    }

    async function continueProviders() {
        setSaving(true); setError(null); setErrors({});
        try {
            await api('/api/agent/profile', { method: 'PATCH', body: { selected_provider_slugs: selectedProviderSlugs } });
            setStep(3);
        } catch (requestError) { setError(requestError); setErrors(requestError.errors || {}); }
        finally { setSaving(false); }
    }

    function updateFeeBand(key, field, value) {
        setFeeBands((current) => current.map((band) => band.key === key ? { ...band, [field]: value } : band));
    }

    function removeFeeBand(key) {
        const band = feeBands.find((item) => item.key === key);
        if (band?.ruleId) setRemovedFeeRuleIds((current) => [...current, band.ruleId]);
        setFeeBands((current) => current.filter((item) => item.key !== key));
    }

    async function saveFeeBands() {
        setSaving(true); setError(null); setErrors({});
        try {
            if (feeBands.some((band) => !band.minimum_amount || !band.charge_value)) {
                throw new Error('Enter a minimum amount and fee for every band, or remove any band you do not use.');
            }

            for (const ruleId of removedFeeRuleIds) {
                await api(`/api/charge-rules/${ruleId}`, { method: 'DELETE' });
            }
            setRemovedFeeRuleIds([]);

            for (const band of feeBands) {
                const payload = {
                    minimum_amount: band.minimum_amount,
                    maximum_amount: band.maximum_amount || null,
                    charge_type: band.charge_type,
                    charge_value: band.charge_value,
                    priority: 0,
                    active: true,
                };
                const result = band.ruleId
                    ? await api(`/api/charge-rules/${band.ruleId}`, { method: 'PATCH', body: payload })
                    : await api('/api/charge-rules', { method: 'POST', body: payload });
                setFeeBands((current) => current.map((item) => item.key === band.key ? { ...item, ruleId: result.id } : item));
            }
            showToast('Transaction fee bands saved. You can update them later in Settings.');
        } catch (requestError) { setError(requestError); setErrors(requestError.errors || {}); }
        finally { setSaving(false); }
    }

    async function finish() {
        setSaving(true); setError(null);
        try {
            await api('/api/agent/profile', { method: 'PATCH', body: { onboarding_state: 'completed' } });
            trackSafeEvent('onboarding_completed');
            router.visit('/dashboard');
        } catch (requestError) { setError(requestError); }
        finally { setSaving(false); }
    }

    return (
        <AppShell title="Business setup">
            <div className="mx-auto max-w-2xl">
                <PageHeading
                    eyebrow={`Setup - Step ${step} of 5`}
                    title={step === 1 ? 'Welcome to POSPilot' : step === 2 ? 'Which provider is this terminal from?' : step === 3 ? 'Add a POS terminal' : step === 4 ? 'Set transaction fee bands' : 'You are ready to start'}
                    description={step === 1 ? `Let us set up your POS business${userName || auth?.user?.name ? `, ${userName || auth.user.name}` : ''}. It only takes a few minutes.` : step === 2 ? 'Choose the providers you use. This records your setup only; it does not turn on transaction sync.' : step === 3 ? 'Add one device now, or skip and add terminals later.' : step === 4 ? 'Set the fees your business charges at different transaction amounts. A manager can adjust these bands later.' : 'Your business profile is ready. You can add more terminals and pricing ranges in Settings.'}
                />
                <div className="mb-4 flex gap-2" aria-label={`Step ${step} of 5`}>
                    {[1, 2, 3, 4, 5].map((number) => <span key={number} className={`h-2 flex-1 rounded-full ${number <= step ? 'bg-brand-accent' : 'bg-slate-200'}`} />)}
                </div>
                {error && <div className="mb-4"><ErrorNotice error={error} /></div>}
                {Object.values(errors).flat().length > 0 && <div className="mb-4"><Notice tone="error"><ul className="list-inside list-disc">{Object.values(errors).flat().map((message, index) => <li key={index}>{message}</li>)}</ul></Notice></div>}

                {step === 1 && <Card><form onSubmit={saveProfile} className="space-y-5">
                    <Field label="Business or display name" name="business_name" value={values.business_name} onChange={setProfileValue('business_name')} required autoComplete="organization" error={errors.business_name?.[0]} />
                    <Field label="Phone number (optional)" name="phone" type="tel" autoComplete="tel" value={values.phone || ''} onChange={setProfileValue('phone')} error={errors.phone?.[0]} />
                    <Field label="Area or branch (optional)" name="location" value={values.location || ''} onChange={setProfileValue('location')} placeholder="Ikeja, Lagos" error={errors.location?.[0]} />
                    <Button type="submit" disabled={saving} className="w-full">{saving ? 'Saving...' : 'Continue'}</Button>
                </form></Card>}

                {step === 2 && <Card>
                    <fieldset>
                        <legend className="text-base font-extrabold">Which POS providers do you use?</legend>
                        <p className="mt-1 text-sm text-slate-600">Select all that apply. You can add multiple accounts and terminals for each provider.</p>
                        <div className="mt-4 space-y-3">
                            {providersLoading ? <p className="text-sm text-slate-600" role="status">Loading providers...</p> : providers.map((provider) => <label key={provider.id} className={`flex min-h-16 cursor-pointer items-center gap-3 rounded-xl border p-4 focus-within:ring-2 focus-within:ring-brand-accent ${selectedProviderSlugs.includes(provider.slug) ? 'border-brand-accent bg-brand-accentSoft' : 'border-brand-line'}`}>
                                <input type="checkbox" name="provider_slugs[]" value={provider.slug} checked={selectedProviderSlugs.includes(provider.slug)} onChange={(event) => { const next = event.target.checked ? [...new Set([...selectedProviderSlugs, provider.slug])] : selectedProviderSlugs.filter((slug) => slug !== provider.slug); setSelectedProviderSlugs(next); if (event.target.checked || provider.slug === providers.find((item) => String(item.id) === providerId)?.slug) { const nextSlug = event.target.checked ? provider.slug : next[0]; setProviderId(String(providers.find((item) => item.slug === nextSlug)?.id || '')); } }} className="h-5 w-5 rounded border-slate-300 text-brand-accent focus:ring-brand-accent" />
                                <ProviderLogo provider={provider} size="md" />
                                <span className="flex-1"><span className="block font-bold">{provider.name}</span><span className="mt-1 block text-sm text-slate-600">Choose the providers you use. You can add terminals and configure statement imports later.</span></span>
                            </label>)}
                        </div>
                    </fieldset>
                    <div className="mt-4 rounded-xl border border-brand-line bg-slate-50 p-4 text-sm leading-6 text-slate-700"><strong className="block text-slate-900">More providers coming soon</strong>We are adding more POS providers and statement integrations. You can update your choices later in Settings.</div>
                    <div className="mt-5 rounded-xl bg-sky-50 p-4 text-sm leading-6 text-sky-950">Email statement connection is configured separately in Providers. POSPilot only imports statements after you connect an account and set its rules.</div>
                    <div className="mt-5 flex gap-3"><Button type="button" variant="secondary" onClick={() => setStep(1)}>Back</Button><Button type="button" disabled={providersLoading || saving || selectedProviderSlugs.length === 0} onClick={continueProviders}>{saving ? 'Saving...' : 'Continue'}</Button></div>
                </Card>}

                {step === 3 && <Card><div className="space-y-4">
                    <SelectField label="Provider for this terminal" name="provider_id" value={providerId} onChange={(event) => setProviderId(event.target.value)}>{providers.filter((provider) => selectedProviderSlugs.includes(provider.slug)).map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}</SelectField>
                    <Field label="Terminal name" name="terminal_name" value={terminal.name} onChange={(event) => setTerminal({ ...terminal, name: event.target.value })} placeholder="Main counter" error={errors.name?.[0]} />
                    <Field label="Terminal ID (optional)" name="terminal_identifier" value={terminal.terminal_identifier} onChange={(event) => setTerminal({ ...terminal, terminal_identifier: event.target.value })} error={errors.terminal_identifier?.[0]} />
                    <div className="flex flex-wrap gap-3"><Button type="button" variant="secondary" onClick={() => setStep(2)}>Back</Button><Button type="button" disabled={saving || !terminal.name || !providerId} onClick={createTerminal}>{saving ? 'Saving...' : 'Add terminal'}</Button><Button type="button" variant="secondary" onClick={() => setStep(4)}>{terminals.length > 0 ? 'Continue' : 'Skip for now'}</Button></div>
                    {terminals.length > 0 && <ul className="divide-y divide-slate-100 border-t border-slate-100">{terminals.map((item) => <li key={item.id} className="py-3 font-semibold">{item.name} - {item.provider?.name}</li>)}</ul>}
                </div></Card>}

                {step === 4 && <Card>
                    <div className="rounded-xl border border-brand-line bg-slate-50 p-4">
                        <h2 className="font-extrabold text-slate-900">Set a fee for each transaction range</h2>
                        <p className="mt-1 text-sm leading-6 text-slate-600">These are starter ranges. Adjust the limits and fee amounts to match your business. You can change them later in Settings.</p>
                    </div>
                    {feeBandsLoading ? <p className="py-5 text-sm text-slate-600" role="status">Loading your fee bands...</p> : <div className="mt-4 space-y-3">
                        {feeBands.map((band, index) => <div key={band.key} className="rounded-xl border border-brand-line p-4">
                            <div className="mb-3 flex items-center justify-between gap-3"><h3 className="font-bold">Fee band {index + 1}</h3><button type="button" className="text-sm font-semibold text-slate-500 underline underline-offset-2 hover:text-red-700" onClick={() => removeFeeBand(band.key)}>Remove</button></div>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="From (NGN)" name={`fee-band-${index}-minimum`} inputMode="decimal" value={band.minimum_amount} onChange={(event) => updateFeeBand(band.key, 'minimum_amount', event.target.value)} required />
                                <Field label="To (NGN), leave blank for no limit" name={`fee-band-${index}-maximum`} inputMode="decimal" value={band.maximum_amount} onChange={(event) => updateFeeBand(band.key, 'maximum_amount', event.target.value)} />
                                <SelectField label="Fee type" name={`fee-band-${index}-type`} value={band.charge_type} onChange={(event) => updateFeeBand(band.key, 'charge_type', event.target.value)}><option value="fixed">Fixed fee</option><option value="percentage">Percentage</option></SelectField>
                                <Field label={band.charge_type === 'fixed' ? 'Fee amount (NGN)' : 'Fee rate (%)'} name={`fee-band-${index}-fee`} inputMode="decimal" value={band.charge_value} onChange={(event) => updateFeeBand(band.key, 'charge_value', event.target.value)} placeholder="Set by your manager" required />
                            </div>
                        </div>)}
                    </div>}
                    {!feeBandsLoading && <button type="button" className="mt-3 min-h-11 rounded-lg border border-brand-line px-4 text-sm font-bold text-slate-700 hover:bg-slate-50" onClick={() => setFeeBands((current) => [...current, createFeeBand({ minimum_amount: '', maximum_amount: '', charge_type: 'fixed', charge_value: '' })])}>Add another fee band</button>}
                    <p className="mt-4 text-sm leading-6 text-slate-600">For example, configure separate fees for NGN 1,000-5,000, NGN 5,001-10,000, NGN 10,001-15,000, and NGN 15,001-30,000. The amounts shown are editable; POSPilot does not choose your fees.</p>
                    <div className="mt-5 flex flex-wrap gap-3"><Button type="button" variant="secondary" onClick={() => setStep(3)}>Back</Button><Button type="button" disabled={saving || feeBandsLoading || feeBands.length === 0} onClick={saveFeeBands}>{saving ? 'Saving fee bands...' : 'Save fee bands'}</Button><Button type="button" variant="secondary" onClick={() => setStep(5)}>Continue</Button></div>
                </Card>}

                {step === 5 && <Card><div className="text-center"><span className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-brand-accentSoft text-sm font-black text-brand-accent" aria-hidden="true">Ready</span><h2 className="mt-4 text-xl font-extrabold">POSPilot is ready to start tracking your business.</h2><p className="mt-2 text-sm leading-6 text-slate-600">You can add more terminals, charge ranges, and provider setup details at any time.</p></div><Button type="button" className="mt-6 w-full" disabled={saving} onClick={finish}>{saving ? 'Finishing setup...' : 'Go to my dashboard'}</Button></Card>}
            </div>
        </AppShell>
    );
}
