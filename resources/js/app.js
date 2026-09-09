import { createApp } from 'vue';
import BookingWidget from './widget/BookingWidget.vue';

document.querySelectorAll('[data-booking-widget]').forEach((el) => {
    createApp(BookingWidget, {
        tenantId: Number(el.dataset.tenantId),
        apiBase: el.dataset.apiBase,
        tracking: JSON.parse(el.dataset.tracking || '{}'),
        rescheduleToken: el.dataset.rescheduleToken || undefined,
    }).mount(el);
});
