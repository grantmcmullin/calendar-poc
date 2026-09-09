<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { fetchAvailability, submitBooking, SlotTakenError, type Slot } from './api';
import InviteeForm, { type InviteeInput } from './InviteeForm.vue';
import ConfirmationCard from './ConfirmationCard.vue';

const props = withDefaults(
    defineProps<{
        tenantId: number;
        apiBase: string;
        tracking?: Record<string, unknown>;
        rescheduleToken?: string;
        invitee?: Partial<InviteeInput>;
    }>(),
    { tracking: () => ({}) }
);

type Stage = 'pick' | 'form' | 'submitting' | 'confirmed';

const COMMON_TIMEZONES = [
    'UTC',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'Europe/London',
    'Europe/Paris',
    'Africa/Johannesburg',
    'Asia/Tokyo',
    'Australia/Sydney',
];

const stage = ref<Stage>('pick');
const errorMessage = ref<string | null>(null);
const slotTakenNotice = ref(false);
const rawSlots = ref<Slot[]>([]);
const selectedDay = ref<string | null>(null);
const selectedSlot = ref<Slot | null>(null);
const confirmation = ref<any | null>(null);

const viewerTz = ref(Intl.DateTimeFormat().resolvedOptions().timeZone);

const timezoneOptions = computed(() => Array.from(new Set([viewerTz.value, ...COMMON_TIMEZONES])));

function startOfDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function toDateStr(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

const weekStart = ref(startOfDay(new Date()));

const weekDays = computed(() => {
    const days: Date[] = [];
    for (let i = 0; i < 7; i++) {
        const d = new Date(weekStart.value);
        d.setDate(d.getDate() + i);
        days.push(d);
    }
    return days;
});

function dayKeyFor(iso: string, timeZone: string): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(iso));
}

const slotsByDay = computed(() => {
    const map: Record<string, Slot[]> = {};
    for (const slot of rawSlots.value) {
        if (slot.status !== 'available') continue;
        const key = dayKeyFor(slot.start_time, viewerTz.value);
        (map[key] ??= []).push(slot);
    }
    return map;
});

const selectedSlots = computed(() => (selectedDay.value ? slotsByDay.value[selectedDay.value] ?? [] : []));

function formatSlotTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], { timeZone: viewerTz.value, hour: 'numeric', minute: '2-digit' });
}

async function loadWeek() {
    errorMessage.value = null;
    try {
        const from = toDateStr(weekDays.value[0]);
        const to = toDateStr(weekDays.value[6]);
        rawSlots.value = await fetchAvailability(props.apiBase, props.tenantId, from, to);
    } catch (e) {
        errorMessage.value = 'Something went wrong loading availability. Please try again.';
    }
}

onMounted(loadWeek);

function prevWeek() {
    const d = new Date(weekStart.value);
    d.setDate(d.getDate() - 7);
    weekStart.value = d;
    selectedDay.value = null;
    void loadWeek();
}

function nextWeek() {
    const d = new Date(weekStart.value);
    d.setDate(d.getDate() + 7);
    weekStart.value = d;
    selectedDay.value = null;
    void loadWeek();
}

function pickDay(dateStr: string) {
    selectedDay.value = dateStr;
}

function pickSlot(slot: Slot) {
    selectedSlot.value = slot;
    slotTakenNotice.value = false;
    stage.value = 'form';
}

function backToPick() {
    stage.value = 'pick';
    selectedSlot.value = null;
}

async function handleSubmit(invitee: InviteeInput) {
    if (!selectedSlot.value) return;
    stage.value = 'submitting';
    errorMessage.value = null;
    try {
        if (props.rescheduleToken) {
            const res = await submitBooking(`/manage/${props.rescheduleToken}/reschedule`, {
                start_time: selectedSlot.value.start_time,
            });
            window.location.assign(res.manage_url);
            return;
        }

        const payload = {
            start_time: selectedSlot.value.start_time,
            invitee: { ...invitee, timezone: viewerTz.value },
            tracking: props.tracking,
        };
        const res = await submitBooking(`${props.apiBase}/tenants/${props.tenantId}/bookings`, payload);
        confirmation.value = res;
        stage.value = 'confirmed';
    } catch (e) {
        if (e instanceof SlotTakenError) {
            slotTakenNotice.value = true;
            selectedSlot.value = null;
            stage.value = 'pick';
            await loadWeek();
        } else {
            errorMessage.value = 'Something went wrong submitting your booking. Please try again.';
            stage.value = 'form';
        }
    }
}
</script>

<template>
    <div class="mx-auto w-full max-w-md rounded-widget border border-gray-200 bg-white p-4 shadow-sm">
        <div v-if="errorMessage" data-test="error-banner" class="mb-4 flex items-center justify-between gap-3 rounded-widget bg-red-50 p-3 text-sm text-red-700">
            <span>{{ errorMessage }}</span>
            <button type="button" data-test="retry" class="font-semibold underline" @click="loadWeek">Retry</button>
        </div>

        <div v-if="slotTakenNotice" data-test="slot-taken-notice" class="mb-4 rounded-widget bg-amber-50 p-3 text-sm text-amber-800">
            That time was just booked by someone else. Please pick another time.
        </div>

        <ConfirmationCard
            v-if="stage === 'confirmed' && confirmation"
            :start-time="confirmation.start_time"
            :timezone="viewerTz"
            :manage-url="confirmation.reschedule_url"
        />

        <template v-else>
            <div class="mb-3 flex items-center justify-between gap-2">
                <button type="button" data-test="prev-week" class="rounded-widget px-2 py-1 text-gray-500 hover:bg-gray-100" @click="prevWeek">
                    &lsaquo;
                </button>
                <select v-model="viewerTz" data-test="timezone-select" class="rounded-widget border border-gray-300 px-2 py-1 text-xs text-gray-700">
                    <option v-for="tz in timezoneOptions" :key="tz" :value="tz">{{ tz }}</option>
                </select>
                <button type="button" data-test="next-week" class="rounded-widget px-2 py-1 text-gray-500 hover:bg-gray-100" @click="nextWeek">
                    &rsaquo;
                </button>
            </div>

            <div class="mb-4 grid grid-cols-7 gap-1">
                <button
                    v-for="day in weekDays"
                    :key="toDateStr(day)"
                    type="button"
                    :data-test="`day-${toDateStr(day)}`"
                    class="relative flex flex-col items-center gap-1 rounded-widget px-1 py-2 text-xs"
                    :class="
                        selectedDay === toDateStr(day)
                            ? 'bg-primary text-primary-contrast'
                            : 'bg-gray-50 text-gray-700 hover:bg-gray-100'
                    "
                    @click="pickDay(toDateStr(day))"
                >
                    <span>{{ day.getDate() }}</span>
                    <span
                        v-if="(slotsByDay[toDateStr(day)] ?? []).length"
                        data-test="day-has-slots"
                        class="h-1 w-1 rounded-full"
                        :class="selectedDay === toDateStr(day) ? 'bg-primary-contrast' : 'bg-primary'"
                    ></span>
                </button>
            </div>

            <template v-if="stage === 'pick'">
                <div v-if="selectedDay" class="space-y-2">
                    <button
                        v-for="slot in selectedSlots"
                        :key="slot.start_time"
                        type="button"
                        data-test="slot"
                        class="block w-full rounded-widget border border-gray-200 px-3 py-2 text-left text-sm hover:border-primary"
                        @click="pickSlot(slot)"
                    >
                        {{ formatSlotTime(slot.start_time) }}
                    </button>
                    <p v-if="selectedSlots.length === 0" class="text-sm text-gray-500">No available times this day.</p>
                </div>
                <p v-else class="text-sm text-gray-500">Select a day to see available times.</p>
            </template>

            <template v-else-if="stage === 'form' || stage === 'submitting'">
                <InviteeForm :initial="invitee" :disabled="stage === 'submitting'" @submit="handleSubmit" />
                <button type="button" class="mt-2 text-sm text-gray-500 underline" @click="backToPick">Back</button>
            </template>
        </template>
    </div>
</template>
