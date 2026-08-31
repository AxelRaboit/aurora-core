<script setup>
/**
 * The chrome every module panel wears, so six of them do not each invent one.
 *
 * A panel is what a module hangs under its links in the side menu when a list
 * of destinations cannot express what the reader needs - a folder tree, a note
 * list. What they have in common is exactly this: a small heading, one optional
 * action beside it, and the three states of something that fetched its own data.
 *
 * It renders **nothing at all** when the fetch failed, and nothing when the
 * caller says the page already owns this surface. Both are the same judgement:
 * a panel that cannot do its job should take up no room, because the links
 * above it are the navigation and they are unaffected.
 */
import { useI18n } from "vue-i18n";

defineProps({
    /** Heading, already translated. */
    title: { type: String, required: true },
    loading: { type: Boolean, default: false },
    failed: { type: Boolean, default: false },
    /** Loaded, and there is nothing to show. */
    empty: { type: Boolean, default: false },
    /** What to say then, already translated. */
    emptyLabel: { type: String, default: "" },
});

const { t } = useI18n();
</script>

<template>
    <section v-if="!failed" class="mt-2 border-t border-line pt-2">
        <header class="flex items-center gap-1.5 px-3 pb-1">
            <h2
                class="flex-1 truncate text-xs font-semibold uppercase tracking-wide text-muted"
            >
                {{ title }}
            </h2>
            <slot name="action" />
        </header>

        <p v-if="loading" class="px-3 py-1 text-xs text-muted">
            {{ t("shared.common.loading") }}
        </p>
        <p v-else-if="empty" class="px-3 py-1 text-xs text-muted">
            {{ emptyLabel }}
        </p>
        <div v-else class="flex flex-col gap-0.5">
            <slot />
        </div>
    </section>
</template>
