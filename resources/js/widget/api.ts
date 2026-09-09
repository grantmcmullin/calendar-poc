export interface Slot { status: string; start_time: string; end_time: string }

export async function fetchAvailability(apiBase: string, tenantId: number, from: string, to: string): Promise<Slot[]> {
    const res = await fetch(`${apiBase}/tenants/${tenantId}/availability?from=${from}&to=${to}`);
    if (!res.ok) throw new Error(`availability failed: ${res.status}`);
    return (await res.json()).collection;
}

export class SlotTakenError extends Error {}

export async function submitBooking(url: string, payload: unknown): Promise<any> {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
    });
    if (res.status === 409) throw new SlotTakenError();
    if (!res.ok) throw new Error(`booking failed: ${res.status}`);
    return res.json();
}
