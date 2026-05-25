export interface AWPDBootData {
    apiUrl: string;
    nonce: string;
    version: string;
    homeUrl: string;
}

export interface AWPDApi {
    api: {
        get: <T = unknown>(
            path: string,
            params?: Record<string, string | number | undefined>
        ) => Promise<T>;
        post: <T = unknown>(path: string, body?: unknown) => Promise<T>;
    };
    data: AWPDBootData;
}

declare global {
    interface Window {
        AWPD: AWPDApi;
        AWPDData?: AWPDBootData;
    }
}

const boot: AWPDBootData = window.AWPDData ?? { apiUrl: '', nonce: '', version: '', homeUrl: '' };

if (!window.AWPDData) {
    console.warn('[AWPD] window.AWPDData missing — admin assets loaded out of order?');
}

function buildUrl(path: string, params?: Record<string, string | number | undefined>): string {
    const base = boot.apiUrl.replace(/\/$/, '');
    const cleanPath = path.startsWith('/') ? path : '/' + path;
    const url = new URL(base + cleanPath, window.location.origin);
    if (params) {
        for (const [k, v] of Object.entries(params)) {
            if (v !== undefined && v !== '') {
                url.searchParams.set(k, String(v));
            }
        }
    }
    return url.toString();
}

async function request<T>(
    method: 'GET' | 'POST',
    path: string,
    opts: { params?: Record<string, string | number | undefined>; body?: unknown } = {}
): Promise<T> {
    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        'X-WP-Nonce': boot.nonce,
    };
    const init: RequestInit = { method, headers };
    if (opts.body !== undefined) {
        init.body = JSON.stringify(opts.body);
    }
    const url = buildUrl(path, opts.params);
    const res = await fetch(url, init);
    let json: unknown = null;
    try {
        json = await res.json();
    } catch {
        json = null;
    }
    if (!res.ok) {
        const message =
            json && typeof json === 'object' && 'message' in json
                ? String((json as { message: unknown }).message)
                : `Request failed (${res.status})`;
        throw new Error(message);
    }
    return json as T;
}

window.AWPD = {
    api: {
        get: <T>(path: string, params?: Record<string, string | number | undefined>) =>
            request<T>('GET', path, { params }),
        post: <T>(path: string, body?: unknown) => request<T>('POST', path, { body }),
    },
    data: boot,
};
