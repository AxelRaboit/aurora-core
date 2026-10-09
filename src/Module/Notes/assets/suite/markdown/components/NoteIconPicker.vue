<script setup>
/**
 * Picks a note's emoji (09/10/2026), the way Notion does: a search in the
 * reader's language - « fusée », « rocket » -, the ones used lately, every
 * emoji by group, and a way to remove it.
 *
 * The recent ones are this browser's: a convenience, nothing to share.
 */
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import AppSearchInput from "@shared/components/form/input/AppSearchInput.vue";
import { loadEmojiData, searchEmoji } from "@notes/suite/markdown/composables/noteEmoji.js";

const props = defineProps({
    /** The emoji in place, to mark it and to offer removing it. */
    current: { type: String, default: null },
});

const emit = defineEmits(["pick"]);

const { t, locale } = useI18n();
const RECENT_KEY = "aurora.notes.recentEmoji";
const RECENT_COUNT = 16;

const data = ref(null);
const query = ref("");
const recent = ref([]);
const search = ref(null);

onMounted(async () => {
    try {
        recent.value = JSON.parse(localStorage.getItem(RECENT_KEY) ?? "[]").slice(0, RECENT_COUNT);
    } catch {
        recent.value = [];
    }
    data.value = await loadEmojiData(String(locale.value ?? "fr").slice(0, 2));
    search.value?.focus();
});

const found = computed(() => (data.value && query.value.trim() ? searchEmoji(data.value, query.value, 120) : []));

const groups = computed(() => {
    if (!data.value) return [];

    return data.value.groups.map((group) => ({
        ...group,
        emoji: data.value.list.filter((item) => item.group === group.key),
    }));
});

function pick(emoji) {
    if (null !== emoji) {
        const next = [emoji, ...recent.value.filter((one) => one !== emoji)].slice(0, RECENT_COUNT);
        recent.value = next;
        try {
            localStorage.setItem(RECENT_KEY, JSON.stringify(next));
        } catch {
            // Remembered for this visit only.
        }
    }
    emit("pick", emoji);
}

function randomEmoji() {
    const list = data.value?.list ?? [];
    if (0 === list.length) return;
    pick(list[Math.floor(Math.random() * list.length)].emoji);
}
</script>

<template>
    <div data-note-icon-picker class="flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2 rounded-lg border border-line bg-surface p-2 shadow-xl">
        <div class="flex items-center gap-2">
            <AppSearchInput
                ref="search"
                v-model="query"
                class="flex-1"
                :placeholder="t('notes.markdown.icon.search')"
                :debounce="0"
            />
            <button
                type="button"
                class="shrink-0 rounded-md px-2 py-1 text-xs text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                :title="t('notes.markdown.icon.random')"
                :aria-label="t('notes.markdown.icon.random')"
                v-on:click="randomEmoji"
            >
                🎲
            </button>
            <button
                v-if="current"
                type="button"
                data-note-icon-remove
                class="shrink-0 rounded-md px-2 py-1 text-xs text-muted transition-colors hover:bg-surface-2 hover:text-primary"
                v-on:click="pick(null)"
            >
                {{ t('notes.markdown.icon.remove') }}
            </button>
        </div>

        <div class="max-h-72 overflow-y-auto pr-1">
            <p v-if="!data" class="py-6 text-center text-xs text-muted">…</p>

            <template v-else-if="query.trim()">
                <div v-if="found.length" class="grid grid-cols-8 gap-0.5">
                    <button
                        v-for="item in found"
                        :key="item.emoji"
                        type="button"
                        data-note-icon-choice
                        class="flex h-9 items-center justify-center rounded-md text-xl transition-colors hover:bg-surface-2"
                        :class="item.emoji === current ? 'bg-surface-2' : ''"
                        :title="item.label"
                        v-on:click="pick(item.emoji)"
                    >
                        {{ item.emoji }}
                    </button>
                </div>
                <p v-else class="py-6 text-center text-xs text-muted">{{ t('notes.markdown.icon.none') }}</p>
            </template>

            <template v-else>
                <template v-if="recent.length">
                    <p class="mb-1 mt-1 px-1 text-2xs font-semibold uppercase tracking-wide text-muted">{{ t('notes.markdown.icon.recent') }}</p>
                    <div class="grid grid-cols-8 gap-0.5">
                        <button
                            v-for="emoji in recent"
                            :key="`recent-${emoji}`"
                            type="button"
                            class="flex h-9 items-center justify-center rounded-md text-xl transition-colors hover:bg-surface-2"
                            v-on:click="pick(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                </template>
                <template v-for="group in groups" :key="group.key">
                    <p class="mb-1 mt-2 px-1 text-2xs font-semibold uppercase tracking-wide text-muted">{{ group.label }}</p>
                    <div class="grid grid-cols-8 gap-0.5">
                        <button
                            v-for="item in group.emoji"
                            :key="item.emoji"
                            type="button"
                            data-note-icon-choice
                            class="flex h-9 items-center justify-center rounded-md text-xl transition-colors hover:bg-surface-2"
                            :class="item.emoji === current ? 'bg-surface-2' : ''"
                            :title="item.label"
                            v-on:click="pick(item.emoji)"
                        >
                            {{ item.emoji }}
                        </button>
                    </div>
                </template>
            </template>
        </div>
    </div>
</template>
