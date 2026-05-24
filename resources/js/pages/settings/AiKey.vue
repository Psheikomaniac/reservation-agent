<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';

interface Props {
    configured: boolean;
    masked: string | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'OpenAI-Schlüssel', href: '/settings/ai-key' }];

const form = useForm({
    openai_api_key: '',
});

const submit = () => {
    form.patch(route('settings.ai-key.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('openai_api_key'),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="OpenAI-Schlüssel" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="OpenAI-Schlüssel"
                    description="Eigener OpenAI-API-Schlüssel für dieses Restaurant. Ohne eigenen Schlüssel wird der globale Schlüssel verwendet."
                />

                <form class="space-y-6" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="openai_api_key">API-Schlüssel</Label>
                        <Input
                            id="openai_api_key"
                            v-model="form.openai_api_key"
                            type="password"
                            autocomplete="off"
                            :placeholder="configured ? `Hinterlegt (${masked}) — leer lassen, um zu behalten` : 'sk-…'"
                        />
                        <InputError :message="form.errors.openai_api_key" />
                    </div>

                    <Button type="submit" :disabled="form.processing">Speichern</Button>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
