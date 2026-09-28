import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

const toastStyles = {
    success: { title: 'All set', icon: <path d="m5 12 4 4L19 6" /> },
    error: { title: 'Something went wrong', icon: <><path d="M12 9v4" /><path d="M12 17h.01" /><circle cx="12" cy="12" r="9" /></> },
    warning: { title: 'Needs your attention', icon: <><path d="M12 9v4" /><path d="M12 17h.01" /><path d="m10.3 3.9-8 14a2 2 0 0 0 1.7 3h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0Z" /></> },
    info: { title: 'Just so you know', icon: <><circle cx="12" cy="12" r="9" /><path d="M12 11v5" /><path d="M12 8h.01" /></> },
};

export default function ToastViewport() {
    const { flash } = usePage();
    const [toasts, setToasts] = useState([]);
    const nextId = useRef(0);
    const timers = useRef(new Map());

    const dismiss = useCallback((id) => {
        window.clearTimeout(timers.current.get(id));
        timers.current.delete(id);
        setToasts((current) => current.filter((toast) => toast.id !== id));
    }, []);

    const addToast = useCallback((notification) => {
        if (!notification) {
            return;
        }

        const normalized = typeof notification === 'string'
            ? { message: notification, type: 'info' }
            : notification;
        const type = Object.hasOwn(toastStyles, normalized.type) ? normalized.type : 'info';
        const id = ++nextId.current;

        setToasts((current) => [...current, { ...normalized, id, type }].slice(-4));
        timers.current.set(id, window.setTimeout(() => dismiss(id), type === 'error' ? 7000 : 4500));
    }, [dismiss]);

    useEffect(() => {
        if (flash?.toast) {
            addToast(flash.toast);
        }
    }, [addToast, flash?.toast]);

    useEffect(() => {
        const handleToast = (event) => addToast(event.detail);
        window.addEventListener('pospilot:toast', handleToast);

        return () => {
            window.removeEventListener('pospilot:toast', handleToast);
            timers.current.forEach((timer) => window.clearTimeout(timer));
            timers.current.clear();
        };
    }, [addToast]);

    if (toasts.length === 0) {
        return null;
    }

    return (
        <div className="toast-viewport" aria-label="Notifications">
            {toasts.map((toast) => {
                const style = toastStyles[toast.type];

                return (
                    <div key={toast.id} className={`app-toast app-toast-${toast.type}`} role={toast.type === 'error' ? 'alert' : 'status'} aria-live={toast.type === 'error' ? 'assertive' : 'polite'}>
                        <span className="app-toast-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">{style.icon}</svg>
                        </span>
                        <div className="app-toast-copy">
                            <strong>{toast.title || style.title}</strong>
                            <p>{toast.message}</p>
                        </div>
                        <button type="button" className="app-toast-dismiss" aria-label="Dismiss notification" onClick={() => dismiss(toast.id)}>
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"><path d="m6 6 12 12M18 6 6 18" /></svg>
                        </button>
                    </div>
                );
            })}
        </div>
    );
}
