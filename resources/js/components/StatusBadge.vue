<script setup lang="ts">
import { statusBadgeClass, statusLabel, type ReservationStatus } from '@/lib/reservationStatus';

const props = defineProps<{ status: ReservationStatus }>();
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass(props.status)">
        {{ statusLabel(props.status) }}
    </span>
</template>
