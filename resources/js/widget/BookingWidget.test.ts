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
});
