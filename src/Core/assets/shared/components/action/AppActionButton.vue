<script setup>
/**
 * A full-width action: what it does, and why you would.
 *
 * For the places where actions are listed rather than crowded into a row —
 * an action sheet, a menu of things that can be done to one record. An icon
 * button says what it does only to whoever already knows; this says it in
 * words, and has room to say what it is for.
 *
 * **The title is bold only when there is a description.** Bold is contrast,
 * not importance: it separates the name of the action from the sentence under
 * it. With nothing underneath there is nothing to separate, and a lone bold
 * line is just shouting. This is deliberate and is what the component is for —
 * a caller that passes no description gets a plain row and needs no second
 * component.
 *
 * Colours mirror `AppIconButton`, so an action keeps the meaning it had when it
 * was a glyph: rose destroys, amber is careful, accent is ordinary.
 *
 * Renders an `<a>` when given `href`, a `<button>` otherwise — some actions are
 * navigations (impersonating a user) and should be openable in a new tab.
 */
const props = defineProps({
    /** The action, named as an imperative. Never empty. */
    title: { type: String, required: true },
    /** What it is for, in a sentence. Optional — see the note on bold above. */
    description: { type: String, default: "" },
    /** Mirrors AppIconButton: default | sky | accent | rose | emerald | amber. */
    color: { type: String, default: "default" },
    /** Renders a link instead of a button. */
    href: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const colors = {
    default: { text: "text-primary", icon: "text-secondary", bg: "hover:bg-surface-2" },
    sky: { text: "text-primary", icon: "text-sky-400", bg: "hover:bg-surface-2" },
    accent: { text: "text-primary", icon: "text-accent-400", bg: "hover:bg-surface-2" },
    amber: { text: "text-primary", icon: "text-amber-400", bg: "hover:bg-surface-2" },
    emerald: { text: "text-primary", icon: "text-emerald-400", bg: "hover:bg-emerald-500/10" },
    rose: { text: "text-rose-400", icon: "text-rose-400", bg: "hover:bg-rose-500/10" },
};

const resolved = colors[props.color] ?? colors.default;
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        v-bind="href ? { href } : { type: 'button', disabled }"
        class="w-full flex items-start gap-3 rounded-lg px-3 py-2.5 text-left transition-colors no-underline disabled:cursor-not-allowed disabled:opacity-50"
        :class="[resolved.bg, resolved.text]"
    >
        <span v-if="$slots.icon" class="shrink-0 pt-0.5" :class="resolved.icon">
            <slot name="icon" />
        </span>

        <span class="min-w-0 flex-1">
            <span class="block text-sm" :class="description ? 'font-semibold' : ''">
                {{ title }}
            </span>
            <span v-if="description" class="mt-0.5 block text-xs text-muted">
                {{ description }}
            </span>
        </span>
    </component>
</template>
