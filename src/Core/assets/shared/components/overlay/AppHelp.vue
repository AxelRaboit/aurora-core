<script setup>
import { computed, ref } from "vue";
import { HelpCircle } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import {
    HELP_KIND_CLASSES,
    HELP_KIND_ICONS,
    HelpKind,
    helpTopic,
} from "@/shared/help/helpTopics.js";

/**
 * A small button beside a label that opens what the screen could not say in a
 * hint.
 *
 * Deliberately a modal rather than a tooltip: a tooltip cannot be read at
 * one's own pace, cannot hold three paragraphs, and is unreachable on a
 * touchscreen. This is for the questions a field raises and a single line
 * cannot answer.
 *
 * Two ways to use it. Either name a `topic` from the registry, and the title,
 * sections and kind come from the translations - the normal case, and the one
 * that survives translation. Or pass a `title` and your own content in the
 * slot, for a one-off that is not worth registering.
 */
const props = defineProps({
    /** Topic id from `helpTopics.js`. Unknown ids render nothing at all. */
    topic: { type: String, default: "" },
    /** Title for a slot-driven help, ignored when `topic` is given. */
    title: { type: String, default: "" },
    /** Overrides the topic's kind, or sets it for a slot-driven help. */
    kind: { type: String, default: "" },
    /** Accessible name of the button. Falls back to a generic "help". */
    label: { type: String, default: "" },
});

const { t, te } = useI18n();
const open = ref(false);

const registered = computed(() => (props.topic ? helpTopic(props.topic) : null));

// A slot-driven help needs a title to have a modal header; a topic-driven one
// finds it in the translations. Without either there is nothing to show, and
// rendering a button that opens an empty box would be worse than rendering
// nothing.
const usable = computed(() => null !== registered.value || "" !== props.title);

const kind = computed(() => props.kind || registered.value?.kind || HelpKind.Field);
const icon = computed(() => HELP_KIND_ICONS[kind.value] ?? HELP_KIND_ICONS[HelpKind.Field]);
const accent = computed(() => HELP_KIND_CLASSES[kind.value] ?? HELP_KIND_CLASSES[HelpKind.Field]);

const prefix = computed(() => `suite.help.${props.topic}`);
const heading = computed(() => (registered.value ? t(`${prefix.value}.title`) : props.title));

/**
 * The sections, skipping any whose body has no translation.
 *
 * A topic declared with a section nobody has written yet would otherwise
 * render the raw key, which looks like a bug to the reader and hides one from
 * the developer. Missing content simply does not appear.
 */
const sections = computed(() => {
    if (!registered.value) return [];

    return (registered.value.sections ?? [])
        .filter((name) => te(`${prefix.value}.${name}_body`))
        .map((name) => ({
            key: name,
            title: te(`${prefix.value}.${name}_title`) ? t(`${prefix.value}.${name}_title`) : "",
            body: t(`${prefix.value}.${name}_body`),
        }));
});

const intro = computed(() =>
    registered.value && te(`${prefix.value}.intro`) ? t(`${prefix.value}.intro`) : "",
);
</script>

<template>
    <span v-if="usable" class="inline-flex align-middle">
        <button
            type="button"
            class="inline-flex items-center justify-center rounded-full transition-colors cursor-pointer"
            :class="accent"
            :aria-label="label || heading || t('shared.common.help')"
            v-on:click.stop.prevent="open = true"
        >
            <HelpCircle class="w-3.5 h-3.5" :stroke-width="2" />
        </button>

        <AppModal
            :show="open"
            :title="heading"
            :icon="icon"
            max-width="lg"
            v-on:close="open = false"
        >
            <div class="space-y-4 text-sm">
                <p v-if="intro" class="text-secondary">{{ intro }}</p>

                <div v-for="section in sections" :key="section.key" class="space-y-1">
                    <p v-if="section.title" class="font-medium text-primary">{{ section.title }}</p>
                    <p class="text-secondary whitespace-pre-line">{{ section.body }}</p>
                </div>

                <slot />
            </div>
        </AppModal>
    </span>
</template>
