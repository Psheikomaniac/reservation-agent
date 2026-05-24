<script setup lang="ts">
// Minimal placeholder — the full acceptance form (OnboardingLayout, useForm)
// is built in #399 once OnboardingLayout (#408) exists.
defineProps<{ email: string; token: string; restaurantName: string }>();
</script>

<template>
    <div>{{ restaurantName }} — {{ email }}</div>
</template>
