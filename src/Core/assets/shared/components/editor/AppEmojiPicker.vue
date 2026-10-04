<script setup>
/**
 * The handful of emojis a report is written with - 👀 🔥 ✅ ❌ 👉 - one click
 * away rather than hunted for in the system's palette.
 *
 * Emits the character; the editor puts it where the caret was. The buttons
 * keep the focus where it is (`mousedown.prevent`), so the caret is still
 * there when the pick arrives.
 */
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Smile } from "lucide-vue-next";

const emit = defineEmits(["pick"]);

const { t } = useI18n();

const GROUPS = [
    { key: "marks", emojis: ["👀", "🔥", "✅", "❌", "⚠️", "💡", "⭐", "✨", "📎", "📌", "🎯", "🏆"] },
    { key: "hands", emojis: ["👉", "👇", "👆", "👈", "👍", "🙌", "👏", "🤝", "💪", "✍️", "🫶", "🙏"] },
    { key: "work", emojis: ["📸", "🎬", "📱", "💻", "📈", "📉", "📊", "🗓️", "🕒", "💬", "📣", "📍"] },
    { key: "feelings", emojis: ["😊", "😍", "🤩", "😉", "🥳", "😮", "🤔", "💛", "❤️", "💜", "🚀", "🌱"] },
];

const open = ref(false);
const root = ref(null);

function pick(emoji) {
    emit("pick", emoji);
    open.value = false;
}

function closeOutside(event) {
    if (open.value && !root.value?.contains(event.target)) open.value = false;
}

onMounted(() => document.addEventListener("mousedown", closeOutside));
onBeforeUnmount(() => document.removeEventListener("mousedown", closeOutside));
</script>

<template>
    <div ref="root" class="relative inline-block">
        <button
            type="button"
            class="inline-flex h-8 items-center gap-1.5 rounded-md border border-line px-2 text-xs text-secondary hover:text-primary"
            :aria-expanded="open"
            :title="t('backend.editor.emoji.open')"
            v-on:mousedown.prevent
            v-on:click="open = !open"
        >
            <Smile class="h-4 w-4" :stroke-width="2" /> {{ t("backend.editor.emoji.label") }}
        </button>
        <div
            v-if="open"
            class="absolute right-0 z-30 mt-1 w-72 rounded-lg border border-line bg-surface p-2 shadow-lg"
            role="dialog"
            :aria-label="t('backend.editor.emoji.label')"
        >
            <div v-for="group in GROUPS" :key="group.key" class="mb-1 last:mb-0">
                <p class="m-0 px-1 text-[10px] font-medium uppercase tracking-wide text-muted">{{ t(`backend.editor.emoji.groups.${group.key}`) }}</p>
                <div class="grid grid-cols-6 gap-0.5">
                    <button
                        v-for="emoji in group.emojis"
                        :key="emoji"
                        type="button"
                        class="rounded-md p-1 text-xl leading-none hover:bg-surface-2"
                        :aria-label="emoji"
                        v-on:mousedown.prevent
                        v-on:click="pick(emoji)"
                    >
                        {{ emoji }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
