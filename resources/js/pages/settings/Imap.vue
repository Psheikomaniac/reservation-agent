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
    imap: {
        host: string | null;
        username: string | null;
        password_masked: string | null;
    };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'IMAP-Empfang', href: '/settings/imap' }];

const form = useForm({
    imap_host: props.imap.host ?? '',
    imap_username: props.imap.username ?? '',
    imap_password: '',
});

const submit = () => {
    form.patch(route('settings.imap.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('imap_password'),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="IMAP-Empfang" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall title="IMAP-Empfang" description="Eigenes IMAP-Postfach, aus dem Reservierungsanfragen abgeholt werden." />

                <form class="space-y-6" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="imap_host">Host</Label>
                        <Input id="imap_host" v-model="form.imap_host" required />
                        <InputError :message="form.errors.imap_host" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="imap_username">Benutzername</Label>
                        <Input id="imap_username" v-model="form.imap_username" required autocomplete="off" />
                        <InputError :message="form.errors.imap_username" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="imap_password">Passwort</Label>
                        <Input
                            id="imap_password"
                            v-model="form.imap_password"
                            type="password"
                            autocomplete="off"
                            :placeholder="imap.password_masked ? `Hinterlegt (${imap.password_masked}) — leer lassen, um zu behalten` : 'Passwort'"
                        />
                        <InputError :message="form.errors.imap_password" />
                    </div>

                    <Button type="submit" :disabled="form.processing">Speichern</Button>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
