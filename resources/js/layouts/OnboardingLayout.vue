<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <header class="flex h-16 items-center bg-topbar px-6 text-topbar-foreground">
            <span class="text-lg font-semibold">{{ title }}</span>
        </header>
        <main class="mx-auto w-full max-w-2xl px-6 py-10">
            <slot />
        </main>
    </div>
</template>
