<script setup>
/**
 * A dashboard figure, with what it counts.
 *
 * **The same tile for every panel.** Each one redrew it, and the detail lost
 * along the way was the alignment: a label that wrapped onto two lines
 * pushed its number one line down, and two tiles side by side showed their
 * figures at two different heights. A row of tiles is a repeated object:
 * same edges, same baselines.
 *
 * **Aligned by the row, not by the tile.** The tile spans three rows of its
 * parent grid and takes them as a subgrid: label, figure, caption. Every
 * tile of a row therefore shares the same three tracks - a label that wraps
 * moves all the figures of its row down together, and a caption under one
 * figure leaves the others where they are instead of lifting them. A row
 * where no tile has a caption gets an empty track, which takes no room.
 * Outside a grid, the subgrid falls back to plain rows: the tile still reads
 * top to bottom.
 *
 * The figure is the largest thing on the dashboard, on purpose: it is what
 * the eye looks for, and the label is there to say what it is, not to be
 * read first (visual redesign of the suite, 09/10/2026).
 */
defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], default: 0 },
    icon: { type: [Object, Function], default: null },
    /**
     * A tint when the figure calls for a reaction rather than a reading.
     * Discreet: a dashboard where everything is red no longer says anything.
     * `danger` is for what is late or broken, and only while it is - the
     * caller passes it when the figure is above zero, not at rest.
     */
    tone: { type: String, default: "default" },
    /** One short line under the figure: what part of it matters. */
    caption: { type: String, default: "" },
    /**
     * Where the figure leads, when it leads somewhere. The whole tile is the
     * link rather than a wrapper around it, so the hover lands on the card's
     * own border instead of a ring drawn outside it.
     */
    href: { type: String, default: "" },
});

const TONES = {
    default: "text-primary",
    attention: "text-accent",
    danger: "text-danger",
};
</script>

<template>
    <component
        :is="href ? 'a' : 'div'"
        :href="href || undefined"
        class="aurora-card row-span-3 grid grid-rows-subgrid gap-y-0 p-4 no-underline sm:p-5"
        :class="href ? 'transition-colors hover:border-accent/40 hover:bg-surface-2/40' : ''"
    >
        <div class="flex items-start gap-2 text-xs font-semibold uppercase tracking-wider text-secondary">
            <component :is="icon" v-if="icon" class="mt-px h-4 w-4 shrink-0" :stroke-width="2" />
            <span class="min-w-0">{{ label }}</span>
        </div>
        <p class="mt-3.5 text-[2.125rem] font-semibold leading-none tracking-tight tabular-nums" :class="TONES[tone] ?? TONES.default">
            {{ value }}
        </p>
        <p v-if="caption" class="mt-2 text-xs text-secondary">{{ caption }}</p>
    </component>
</template>
