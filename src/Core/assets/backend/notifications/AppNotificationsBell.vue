<script setup>
import { Bell, Check, X, Trash2 } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { useNotifications } from "./composables/useNotifications.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateNumeric } = useDateFormat();

const props = defineProps({
    listPath: { type: String, required: true },
    markReadPath: { type: String, required: true },
    markAllReadPath: { type: String, required: true },
    deletePath: { type: String, default: "" },
    deleteAllPath: { type: String, default: "" },
});

const { t } = useI18n();
const {
    entries,
    unreadCount,
    open,
    toggle,
    markRead,
    markAllRead,
    deleteOne,
    deleteAll,
} = useNotifications({
    list: props.listPath,
    markRead: props.markReadPath,
    markAllRead: props.markAllReadPath,
    deletePath: props.deletePath,
    deleteAllPath: props.deleteAllPath,
});

function formatDate(iso) {
    const date = new Date(iso);
    const diffSeconds = Math.round((Date.now() - date.getTime()) / 1000);
    if (diffSeconds < 60) return t("backend.notifications.just_now");
    if (diffSeconds < 3600) return t("backend.notifications.minutes_ago", { n: Math.floor(diffSeconds / 60) });
    if (diffSeconds < 86400) return t("backend.notifications.hours_ago", { n: Math.floor(diffSeconds / 3600) });
    return formatDateNumeric(date.toISOString());
}

function onItemClick(entry) {
    if (!entry.readAt) markRead(entry);
    if (entry.url) window.location.href = entry.url;
}
</script>

<template>
    <div class="relative">
        <AppIconButton
            class="relative"
            :title="t('backend.notifications.title')"
            v-on:click="toggle"
        >
            <Bell class="w-5 h-5" :stroke-width="2" />
            <span
                v-if="unreadCount > 0"
                class="absolute -top-1 -right-1 inline-flex items-center justify-center w-4 h-4 rounded-full bg-rose-500 text-white text-[10px] font-bold"
            >
                {{ unreadCount > 9 ? "9+" : unreadCount }}
            </span>
        </AppIconButton>

        <Teleport to="body">
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-start justify-center pt-20 px-2 sm:px-4"
            >
                <div class="absolute inset-0 bg-black/50" v-on:click="toggle" />
                <div class="relative w-full max-w-2xl bg-surface border border-line rounded-xl shadow-2xl flex flex-col max-h-[70vh]">
                    <div class="flex items-center justify-between px-4 py-3 border-b border-line shrink-0">
                        <span class="text-sm font-semibold text-primary">{{ t('backend.notifications.title') }}</span>
                        <div class="flex items-center gap-1">
                            <AppIconButton v-if="unreadCount > 0" :title="t('backend.notifications.mark_all_read')" v-on:click="markAllRead">
                                <Check class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton v-if="deletePath && entries.length" :title="t('backend.notifications.delete_all')" v-on:click="deleteAll">
                                <Trash2 class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton :title="t('backend.notifications.close')" v-on:click="toggle">
                                <X class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </div>
                    <div class="overflow-y-auto scrollbar-thin flex-1">
                        <AppNoData v-if="!entries.length" :message="t('backend.notifications.empty')" />
                        <div
                            v-for="entry in entries"
                            :key="entry.id"
                            class="group flex items-start border-b border-line/40 last:border-b-0 transition-colors"
                            :class="entry.readAt ? 'hover:bg-surface-2' : 'bg-accent-600/5 hover:bg-accent-600/10'"
                        >
                            <button
                                type="button"
                                class="flex-1 text-left px-4 py-3"
                                v-on:click="onItemClick(entry)"
                            >
                                <p class="text-sm font-medium text-primary truncate">{{ entry.title }}</p>
                                <p v-if="entry.body" class="text-xs text-secondary line-clamp-2 mt-0.5">{{ entry.body }}</p>
                                <p class="text-xs text-muted mt-1">{{ formatDate(entry.createdAt) }}</p>
                            </button>
                            <button
                                v-if="deletePath"
                                type="button"
                                class="opacity-0 group-hover:opacity-100 shrink-0 p-3 text-muted hover:text-rose-500 transition-all"
                                :title="t('backend.notifications.delete')"
                                v-on:click.stop="deleteOne(entry)"
                            >
                                <X class="w-3.5 h-3.5" :stroke-width="2" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
