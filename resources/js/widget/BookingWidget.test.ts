import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BookingWidget from './BookingWidget.vue';

const slots = {
    collection: [
        { status: 'available', start_time: '2026-09-14T14:00:00Z', end_time: '2026-09-14T14:30:00Z' },
        { status: 'available', start_time: '2026-09-14T14:30:00Z', end_time: '2026-09-14T15:00:00Z' },
    ],
};

function fetchMock(responses: Array<{ status: number; body: unknown }>) {
    const fn = vi.fn();
    for (const r of responses) {
        fn.mockResolvedValueOnce({ ok: r.status < 400, status: r.status, json: async () => r.body });
    }
    vi.stubGlobal('fetch', fn);
    return fn;
}

describe('BookingWidget', () => {
    beforeEach(() => vi.unstubAllGlobals());

    it('renders available time slots for the selected day', async () => {
        fetchMock([{ status: 200, body: slots }]);
        const wrapper = mount(BookingWidget, { props: { tenantId: 1, apiBase: '/api/v1', tracking: {} } });
        await flushPromises();

        await wrapper.find('[data-test="day-2026-09-14"]').trigger('click');
        expect(wrapper.findAll('[data-test="slot"]').length).toBe(2);
    });

    it('books a slot and shows confirmation', async () => {
        fetchMock([
            { status: 200, body: slots },
            { status: 201, body: { uuid: 'u1', start_time: '2026-09-14T14:00:00Z', reschedule_url: '/manage/tok' } },
        ]);
        const wrapper = mount(BookingWidget, { props: { tenantId: 1, apiBase: '/api/v1', tracking: { utm_content: 'x' } } });
        await flushPromises();
        await wrapper.find('[data-test="day-2026-09-14"]').trigger('click');
        await wrapper.findAll('[data-test="slot"]')[0].trigger('click');
        await wrapper.find('[data-test="first-name"]').setValue('Jane');
        await wrapper.find('[data-test="last-name"]').setValue('Doe');
        await wrapper.find('[data-test="email"]').setValue('jane@example.com');
        await wrapper.find('[data-test="phone"]').setValue('+15550001111');
        await wrapper.find('[data-test="submit"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-test="confirmation"]').exists()).toBe(true);
    });

    it('recovers from a 409 by refetching slots and showing a notice', async () => {
        fetchMock([
            { status: 200, body: slots },
            { status: 409, body: { message: 'This slot is no longer available.' } },
            { status: 200, body: { collection: [slots.collection[1]] } }, // refetch
        ]);
        const wrapper = mount(BookingWidget, { props: { tenantId: 1, apiBase: '/api/v1', tracking: {} } });
        await flushPromises();
        await wrapper.find('[data-test="day-2026-09-14"]').trigger('click');
        await wrapper.findAll('[data-test="slot"]')[0].trigger('click');
        await wrapper.find('[data-test="first-name"]').setValue('Jane');
        await wrapper.find('[data-test="last-name"]').setValue('Doe');
        await wrapper.find('[data-test="email"]').setValue('jane@example.com');
        await wrapper.find('[data-test="phone"]').setValue('+15550001111');
        await wrapper.find('[data-test="submit"]').trigger('submit');
        await flushPromises();

        expect(wrapper.find('[data-test="slot-taken-notice"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-test="slot"]').length).toBe(1);
    });

    it('re-keys the day strip when the viewer timezone override shifts a slot to a different calendar day', async () => {
        const dayKeyIn = (iso: string, timeZone: string) =>
            new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(
                new Date(iso)
            );

        // A slot 30 minutes after the local midnight that starts the widget's window.
        // In a timezone far enough behind the machine's local zone, that same instant
        // falls on the previous calendar day — the day strip must follow it there.
        const today = new Date();
        const weekStart = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        const slotStart = new Date(weekStart.getTime() + 30 * 60 * 1000).toISOString();

        const defaultTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        const overrideTz = 'America/Los_Angeles';
        const keyDefault = dayKeyIn(slotStart, defaultTz);
        const keyOverride = dayKeyIn(slotStart, overrideTz);
        expect(keyOverride).not.toBe(keyDefault);

        fetchMock([
            { status: 200, body: { collection: [{ status: 'available', start_time: slotStart, end_time: slotStart }] } },
        ]);
        const wrapper = mount(BookingWidget, { props: { tenantId: 1, apiBase: '/api/v1', tracking: {} } });
        await flushPromises();

        expect(wrapper.find(`[data-test="day-${keyDefault}"] [data-test="day-has-slots"]`).exists()).toBe(true);

        await wrapper.find('[data-test="timezone-select"]').setValue(overrideTz);
        await flushPromises();

        // The whole strip re-keyed itself into the override tz's calendar: the dot
        // follows the slot to its new day-key instead of staying on the stale one.
        expect(wrapper.find(`[data-test="day-${keyDefault}"] [data-test="day-has-slots"]`).exists()).toBe(false);
        expect(wrapper.find(`[data-test="day-${keyOverride}"] [data-test="day-has-slots"]`).exists()).toBe(true);

        await wrapper.find(`[data-test="day-${keyOverride}"]`).trigger('click');
        expect(wrapper.findAll('[data-test="slot"]').length).toBe(1);
    });

    it('abandons a pending slot when week navigation is used mid-form', async () => {
        const fetchFn = fetchMock([
            { status: 200, body: slots },
            { status: 200, body: { collection: [] } }, // refetch triggered by next-week while in the form stage
        ]);
        const wrapper = mount(BookingWidget, { props: { tenantId: 1, apiBase: '/api/v1', tracking: {} } });
        await flushPromises();
        await wrapper.find('[data-test="day-2026-09-14"]').trigger('click');
        await wrapper.findAll('[data-test="slot"]')[0].trigger('click');

        expect(wrapper.find('[data-test="first-name"]').exists()).toBe(true);

        await wrapper.find('[data-test="next-week"]').trigger('click');
        await flushPromises();

        // Navigating away mid-form drops the stale selectedSlot and returns to 'pick' —
        // InviteeForm must not stay mounted against a slot from the abandoned week.
        expect(wrapper.find('[data-test="first-name"]').exists()).toBe(false);
        expect(fetchFn).toHaveBeenCalledTimes(2);
    });
});
