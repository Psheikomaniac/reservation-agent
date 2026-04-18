<script setup lang="ts">
defineProps<{
    title?: string;
    heading?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center bg-background p-6 md:p-10">
        <div class="w-full max-w-xl">
            <div class="mb-8 space-y-2 text-center">
                <h1 v-if="heading" class="text-2xl font-semibold">{{ heading }}</h1>
                <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
            </div>
            <div class="rounded-lg border border-border bg-card p-6 shadow-sm md:p-8">
                <slot />
            </div>
        </div>
    </div>
</template>
