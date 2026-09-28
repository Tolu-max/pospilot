import '../css/app.css';
import '../css/max/pospilot.css';
import '../css/max/transactions.css';
import '../css/max/reconciliation.css';
import '../css/max/operations.css';
import '../css/max/demo-ui.css';
import '../css/max/inertia-adapter.css';
import '../css/max/polish.css';
import './bootstrap';

import BrandLogo from './Components/BrandLogo';
import Sabilytics from './Components/Sabilytics';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { useEffect, useState } from 'react';
import { trackCompletionEvent } from './lib/analytics';

const configuredAppName = import.meta.env.VITE_APP_NAME;
const appName = configuredAppName && configuredAppName !== 'PosAgent Bot' ? configuredAppName : 'POSPilot';

function InertiaLoadingOverlay() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        let revealTimer;
        const removeStartListener = router.on('start', () => {
            revealTimer = window.setTimeout(() => setVisible(true), 180);
        });
        const hideOverlay = () => {
            window.clearTimeout(revealTimer);
            setVisible(false);
        };
        const removeFinishListener = router.on('finish', hideOverlay);
        const removeCancelListener = router.on('cancel', hideOverlay);

        return () => {
            window.clearTimeout(revealTimer);
            removeStartListener();
            removeFinishListener();
            removeCancelListener();
        };
    }, []);

    if (!visible) {
        return null;
    }

    return (
        <div className="page-loading-overlay" role="status" aria-live="polite" aria-label="Loading page">
            <div className="page-loading-card">
                <BrandLogo className="page-loading-brand" />
                <span className="page-loading-spinner" aria-hidden="true" />
                <span className="page-loading-label">Loading your workspace</span>
            </div>
        </div>
    );
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        trackCompletionEvent(props.initialPage?.props?.analyticsEvent);
        router.on('navigate', (event) => {
            trackCompletionEvent(event.detail.page.props?.analyticsEvent);
        });
        const root = createRoot(el);

        root.render(<><Sabilytics /><App {...props} /><InertiaLoadingOverlay /></>);
    },
    progress: {
        color: '#4B5563',
    },
});
