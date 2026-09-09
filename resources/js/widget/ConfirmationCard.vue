<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    startTime: string;
    timezone: string;
    manageUrl?: string;
}>();

const formatted = computed(() =>
    new Date(props.startTime).toLocaleString([], {
        timeZone: props.timezone,
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    })
);
</script>

<template>
    <div data-test="confirmation" class="rounded-widget border border-gray-200 bg-white p-6 text-center">
        <h2 class="text-lg font-semibold text-gray-900">You're booked!</h2>
        <p class="mt-2 text-sm text-gray-600">{{ formatted }}</p>
        <a
            v-if="manageUrl"
            :href="manageUrl"
            data-test="manage-link"
            class="mt-4 inline-block text-sm font-medium text-primary underline"
        >
            Manage this booking
        </a>
    </div>
</template>
