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
    smtp: {
        host: string | null;
        port: number | null;
        username: string | null;
        from_address: string | null;
        from_name: string | null;
        password_masked: string | null;
    };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'SMTP-Versand', href: '/settings/smtp' }];

const form = useForm({
    smtp_host: props.smtp.host ?? '',
    smtp_port: props.smtp.port ?? 587,
    smtp_username: props.smtp.username ?? '',
    smtp_password: '',
    smtp_from_address: props.smtp.from_address ?? '',
    smtp_from_name: props.smtp.from_name ?? '',
});

const submit = () => {
    form.patch(route('settings.smtp.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('smtp_password'),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="SMTP-Versand" />

        <SettingsLayout>
            <div class="flex flex-col space-y-6">
                <HeadingSmall
                    title="SMTP-Versand"
                    description="Eigener SMTP-Server für ausgehende Gäste-Mails. Ohne eigene Konfiguration wird der globale Versand verwendet."
                />

                <form class="space-y-6" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="smtp_host">Host</Label>
                        <Input id="smtp_host" v-model="form.smtp_host" required />
                        <InputError :message="form.errors.smtp_host" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="smtp_port">Port</Label>
                        <Input id="smtp_port" v-model="form.smtp_port" type="number" min="1" max="65535" required />
                        <InputError :message="form.errors.smtp_port" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="smtp_username">Benutzername</Label>
                        <Input id="smtp_username" v-model="form.smtp_username" required autocomplete="off" />
                        <InputError :message="form.errors.smtp_username" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="smtp_password">Passwort</Label>
                        <Input
                            id="smtp_password"
                            v-model="form.smtp_password"
                            type="password"
                            autocomplete="off"
                            :placeholder="smtp.password_masked ? `Hinterlegt (${smtp.password_masked}) — leer lassen, um zu behalten` : 'Passwort'"
                        />
                        <InputError :message="form.errors.smtp_password" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="smtp_from_address">Absender-Adresse</Label>
                        <Input id="smtp_from_address" v-model="form.smtp_from_address" type="email" required />
                        <InputError :message="form.errors.smtp_from_address" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="smtp_from_name">Absender-Name</Label>
                        <Input id="smtp_from_name" v-model="form.smtp_from_name" required />
                        <InputError :message="form.errors.smtp_from_name" />
                    </div>

                    <Button type="submit" :disabled="form.processing">Speichern</Button>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
