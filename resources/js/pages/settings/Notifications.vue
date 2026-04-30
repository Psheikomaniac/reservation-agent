<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type NotificationSettings } from '@/types';
import { Head } from '@inertiajs/vue3';

defineProps<{
    settings: NotificationSettings;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'Benachrichtigungen', href: '/settings/notifications' },
];
</script>

<template>
    <Head title="Benachrichtigungen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-4 p-6">
            <h1 class="text-2xl font-semibold">Benachrichtigungen</h1>
            <p class="text-sm text-muted-foreground">
                Browser-Push, Sound-Alerts und der tägliche Digest. Die Detail-UI mit dem "Sound testen"-Button und dem Permission-Flow landet in
                #246.
            </p>
            <pre class="rounded-md bg-muted p-3 text-xs">{{ JSON.stringify(settings, null, 2) }}</pre>
        </div>
    </AppLayout>
</template>
