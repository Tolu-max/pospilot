export function showToast(message, type = 'success') {
    if (typeof window === 'undefined') {
        return;
    }

    window.dispatchEvent(new CustomEvent('pospilot:toast', {
        detail: typeof message === 'string' ? { message, type } : message,
    }));
}
