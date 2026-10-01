const safeEvents = Object.freeze({
    signup_completed: Object.freeze({}),
    login_completed: Object.freeze({}),
    onboarding_completed: Object.freeze({ feature: 'onboarding' }),
    gmail_connected: Object.freeze({ source: 'gmail' }),
    statement_sync_started: Object.freeze({ source: 'gmail' }),
    statement_imported: Object.freeze({ source: 'gmail' }),
    provider_connection_started: Object.freeze({ provider: 'moniepoint' }),
    shift_started: Object.freeze({ feature: 'shift' }),
    shift_closed: Object.freeze({ feature: 'shift' }),
    daily_closing_completed: Object.freeze({ feature: 'daily_closing' }),
});

const pendingEvents = [];
const processedCompletionIds = new Set();

export function trackSafeEvent(name) {
    if (!Object.hasOwn(safeEvents, name) || typeof window === 'undefined') {
        return;
    }

    const event = { name, metadata: safeEvents[name] };
    const tracker = window.sabilytics;

    if (typeof tracker?.track === 'function') {
        try {
            tracker.track(event.name, event.metadata);
        } catch {
            // Analytics failures must not interrupt POSPilot actions.
        }

        return;
    }

    pendingEvents.push(event);
}

export function trackCompletionEvent(envelope) {
    if (!envelope || typeof envelope !== 'object'
        || typeof envelope.id !== 'string'
        || processedCompletionIds.has(envelope.id)) {
        return;
    }

    processedCompletionIds.add(envelope.id);

    if (processedCompletionIds.size > 100) {
        processedCompletionIds.delete(processedCompletionIds.values().next().value);
    }

    trackSafeEvent(envelope.name);
}

export function flushSabilyticsEvents({ discard = false } = {}) {
    if (discard) {
        pendingEvents.length = 0;

        return;
    }

    const tracker = window.sabilytics;

    if (typeof tracker?.track !== 'function') {
        return;
    }

    while (pendingEvents.length > 0) {
        const event = pendingEvents.shift();

        try {
            tracker.track(event.name, event.metadata);
        } catch {
            // Analytics failures must not interrupt POSPilot actions.
        }
    }
}
