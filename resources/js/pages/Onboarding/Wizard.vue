<script setup lang="ts">
// Minimal placeholder — the full step UI is built in #406.
defineProps<{
    restaurant: Record<string, unknown>;
    tables: unknown[];
    tonalities: string[];
    progress: Record<string, unknown>;
}>();
</script>

<template>
    <div>{{ restaurant.name }}</div>
</template>
