import { useEffect } from 'react';
import { flushSabilyticsEvents } from '../lib/analytics';

const trackedDomain = 'pospilot.tconnect.com.ng';
const trackedSiteId = 'arsmn1oxr2nl';

export default function Sabilytics() {
    useEffect(() => {
        if (window.location.hostname !== trackedDomain) {
            return undefined;
        }

        const existing = document.querySelector(`script[data-site="${trackedSiteId}"]`);

        if (existing) {
            if (window.sabilytics?.track) {
                flushSabilyticsEvents();
            } else {
                existing.addEventListener('load', flushSabilyticsEvents, { once: true });
            }

            return undefined;
        }

        const script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.sabilytics.com/script.js';
        script.setAttribute('data-site', trackedSiteId);
        script.setAttribute('data-domain', trackedDomain);
        script.addEventListener('load', flushSabilyticsEvents, { once: true });
        script.addEventListener('error', () => {
            flushSabilyticsEvents({ discard: true });
        }, { once: true });
        document.head.appendChild(script);

        return undefined;
    }, []);

    return null;
}
