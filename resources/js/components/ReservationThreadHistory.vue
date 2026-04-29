<script setup lang="ts">
import { formatDateTime } from '@/lib/format-datetime';
import type { ThreadMessage } from '@/types';
import { ArrowDownLeft, ArrowUpRight } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    messages: ThreadMessage[] | null | undefined;
    timezone?: string | null;
    loading?: boolean;
}

const props = defineProps<Props>();

const list = computed(() => props.messages ?? []);

function timestamp(message: ThreadMessage): string | null {
    return message.direction === 'out' ? message.sent_at : message.received_at;
}
</script>

<template>
    <div data-testid="reservation-thread-history" class="space-y-3">
        <div
            v-if="loading && list.length === 0"
            class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
            data-testid="thread-history-loading"
        >
            Lade Nachrichtenverlauf …
        </div>

        <div
            v-else-if="list.length === 0"
            class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
            data-testid="thread-history-empty"
        >
            Noch keine Nachrichten zu dieser Reservierung.
        </div>

        <ol v-else class="space-y-3" data-testid="thread-history-list">
            <li
                v-for="message in list"
                :key="message.id"
                class="space-y-1.5 rounded-md border border-border bg-muted/20 p-3"
                :data-testid="`thread-message-${message.id}`"
                :data-direction="message.direction"
            >
                <div class="flex items-start justify-between gap-2 text-xs text-muted-foreground">
                    <div class="flex items-center gap-1.5">
                        <ArrowUpRight
                            v-if="message.direction === 'out'"
                            class="size-3.5 text-emerald-700 dark:text-emerald-400"
                            aria-label="Outbound"
                        />
                        <ArrowDownLeft v-else class="size-3.5 text-sky-700 dark:text-sky-400" aria-label="Inbound" />
                        <span>{{ message.from_address }}</span>
                    </div>
                    <time :datetime="timestamp(message) ?? ''">{{ formatDateTime(timestamp(message), { timeZone: timezone ?? undefined }) }}</time>
                </div>

                <p class="text-sm font-medium">{{ message.subject }}</p>
                <p class="whitespace-pre-line text-sm text-foreground">{{ message.body_plain }}</p>

                <p v-if="message.direction === 'out' && message.approved_by" class="text-xs text-muted-foreground">
                    Freigegeben von {{ message.approved_by }}
                </p>
            </li>
        </ol>
    </div>
</template>
