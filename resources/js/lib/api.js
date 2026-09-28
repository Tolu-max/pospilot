export class ApiError extends Error {
    constructor(message, status, errors = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

export async function api(path, { method = 'GET', body, formData = false } = {}) {
    const headers = { Accept: 'application/json' };
    const options = { method, credentials: 'same-origin', headers };

    if (method !== 'GET' && method !== 'HEAD') {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrf) headers['X-CSRF-TOKEN'] = csrf;
        if (body !== undefined) {
            if (formData) options.body = body;
            else {
                headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
        }
    }

    const response = await fetch(path, options);
    if (response.status === 204) return null;
    const contentType = response.headers.get('content-type') || '';
    if (!contentType.includes('application/json')) {
        if (response.url.includes('/confirm-password')) {
            window.location.assign(response.url);
            throw new ApiError('Please confirm your password to continue.', response.status);
        }
        if (response.url.includes('/login') || response.url.includes('/verify-email')) {
            window.location.assign(response.url);
            throw new ApiError('Your session needs attention. Please sign in and try again.', response.status);
        }
        throw new ApiError(response.ok ? 'The server returned an unexpected response.' : 'We could not complete that request. Please try again.', response.status);
    }

    const result = await response.json();
    if (!response.ok) {
        throw new ApiError(result.message || 'We could not complete that request. Please check the details and try again.', response.status, result.errors || {});
    }

    return result;
}

export function money(value) {
    if (value === null || value === undefined || value === '') return '—';
    const [whole, fraction = '00'] = String(value).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `₦${grouped}.${fraction.padEnd(2, '0').slice(0, 2)}`;
}

export function dateTime(value) {
    if (!value) return 'Not recorded';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' });
}

export function today() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}
