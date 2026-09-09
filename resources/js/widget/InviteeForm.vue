<script setup lang="ts">
import { reactive, watch } from 'vue';

export interface InviteeInput {
    first_name: string;
    last_name: string;
    email: string;
    phone: string;
}

const props = withDefaults(
    defineProps<{
        initial?: Partial<InviteeInput>;
        disabled?: boolean;
    }>(),
    { disabled: false }
);

const emit = defineEmits<{ submit: [invitee: InviteeInput] }>();

const form = reactive<InviteeInput>({
    first_name: props.initial?.first_name ?? '',
    last_name: props.initial?.last_name ?? '',
    email: props.initial?.email ?? '',
    phone: props.initial?.phone ?? '',
});

watch(
    () => props.initial,
    (initial) => {
        if (!initial) return;
        form.first_name = initial.first_name ?? form.first_name;
        form.last_name = initial.last_name ?? form.last_name;
        form.email = initial.email ?? form.email;
        form.phone = initial.phone ?? form.phone;
    }
);

function onSubmit() {
    if (props.disabled) return;
    emit('submit', { ...form });
}
</script>

<template>
    <form data-test="submit" class="space-y-3" @submit.prevent="onSubmit">
        <div>
            <label class="block text-sm font-medium text-gray-700" for="invitee-first-name">First name</label>
            <input
                id="invitee-first-name"
                v-model="form.first_name"
                data-test="first-name"
                type="text"
                required
                :disabled="disabled"
                class="mt-1 block w-full rounded-widget border border-gray-300 px-3 py-2 text-sm"
            />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700" for="invitee-last-name">Last name</label>
            <input
                id="invitee-last-name"
                v-model="form.last_name"
                data-test="last-name"
                type="text"
                required
                :disabled="disabled"
                class="mt-1 block w-full rounded-widget border border-gray-300 px-3 py-2 text-sm"
            />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700" for="invitee-email">Email</label>
            <input
                id="invitee-email"
                v-model="form.email"
                data-test="email"
                type="email"
                required
                :disabled="disabled"
                class="mt-1 block w-full rounded-widget border border-gray-300 px-3 py-2 text-sm"
            />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700" for="invitee-phone">Phone</label>
            <input
                id="invitee-phone"
                v-model="form.phone"
                data-test="phone"
                type="tel"
                required
                :disabled="disabled"
                class="mt-1 block w-full rounded-widget border border-gray-300 px-3 py-2 text-sm"
            />
        </div>
        <button
            type="submit"
            :disabled="disabled"
            class="w-full rounded-widget bg-primary px-4 py-2 text-sm font-semibold text-primary-contrast disabled:opacity-60"
        >
            {{ disabled ? 'Booking…' : 'Confirm booking' }}
        </button>
    </form>
</template>
