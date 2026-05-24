<script setup lang="ts">
// Minimal placeholder — the styled error page is built in #399.
defineProps<{ reason: string }>();
</script>

<template>
    <div>{{ reason }}</div>
</template>
