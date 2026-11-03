export interface ServerTimings {
    embed: number | null;
    db: number | null;
}

export type SearchOutcome<T> =
    | { ok: true; data: T[]; timings: ServerTimings; elapsedMs: number }
    | { ok: false; message: string; status: number | null };

export function parseServerTiming(header: string | null): ServerTimings {
    const timings: ServerTimings = { embed: null, db: null };

    for (const entry of (header ?? '').split(',')) {
        const [name, ...params] = entry.trim().split(';');
        const duration = params.map((param) => param.trim()).find((param) => param.startsWith('dur='));

        if ((name === 'embed' || name === 'db') && duration !== undefined) {
            const value = Number(duration.slice(4));
            timings[name] = Number.isFinite(value) ? value : null;
        }
    }

    return timings;
}

interface ErrorBody {
    message?: string;
    errors?: Record<string, string[]>;
}

function errorMessage(status: number, body: ErrorBody | null): string {
    const first = body?.errors !== undefined ? Object.values(body.errors)[0]?.[0] : undefined;

    if (status === 429) {
        return 'Too many requests, try again in a minute.';
    }

    return first ?? body?.message ?? `The server answered ${status}.`;
}

export async function searchJson<T>(url: string, params: Record<string, string>): Promise<SearchOutcome<T>> {
    const started = performance.now();

    try {
        const response = await fetch(`${url}?${new URLSearchParams(params).toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            const body = (await response.json().catch(() => null)) as ErrorBody | null;

            return { ok: false, message: errorMessage(response.status, body), status: response.status };
        }

        const body = (await response.json()) as { data: T[] };

        return {
            ok: true,
            data: body.data,
            timings: parseServerTiming(response.headers.get('Server-Timing')),
            elapsedMs: performance.now() - started,
        };
    } catch {
        return { ok: false, message: 'Connection lost.', status: null };
    }
}
