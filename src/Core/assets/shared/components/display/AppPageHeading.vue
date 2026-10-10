<script setup>
/**
 * The heading of a screen, at the top of its content: its name in large type,
 * one line under it, and the screen's own commands on the right.
 *
 * The top bar already names the page, in small type, for whoever scrolls; this
 * is the name as the page opens, the way a document has a title above its
 * first paragraph (visual redesign of the suite, 10/10/2026, after the
 * validated screen mockups). The line under it says what the screen holds -
 * its figures when it has some, otherwise what it is for.
 *
 * An `h2`: the page header's strip holds the page's heading already, and a
 * second top-level heading would leave the document outline with two tops.
 *
 * The default slot sits under that line, for what qualifies the thing the
 * screen shows rather than counts it: a slug, the chips of its capabilities.
 *
 * The commands wrap under the title on a phone, full width, as everywhere else
 * a page's commands sit (`AppListToolbar`, `AppModalFooter`).
 */
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: "" },
});
</script>

<template>
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between" data-page-heading>
        <div class="min-w-0">
            <h2 class="text-[1.625rem] font-semibold leading-tight tracking-tight text-primary">{{ title }}</h2>
            <p v-if="subtitle" class="mt-1 text-[0.8125rem] tabular-nums text-secondary">{{ subtitle }}</p>
            <div v-if="$slots.default" class="mt-2 flex flex-wrap items-center gap-1.5">
                <slot />
            </div>
        </div>
        <div
            v-if="$slots.actions"
            class="flex flex-col gap-2 sm:flex-row sm:items-center *:w-full sm:*:w-auto"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
