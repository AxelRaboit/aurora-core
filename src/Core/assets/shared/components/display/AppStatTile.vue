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
 * The label takes the room it needs and the number sits at the bottom of the
 * tile, so the alignment no longer depends on the length of the words.
 */
defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], default: 0 },
    icon: { type: [Object, Function], default: null },
    /**
     * A tint when the figure calls for a reaction rather than a reading.
     * Discreet: a dashboard where everything is red no longer says anything.
     */
    tone: { type: String, default: "default" },
});

const TONES = {
    default: "text-primary",
    attention: "text-accent",
};
</script>

<template>
    <div class="aurora-card flex flex-col p-4">
        <div class="flex flex-1 items-start gap-2 text-xs uppercase tracking-wide text-secondary">
            <component :is="icon" v-if="icon" class="mt-0.5 h-4 w-4 shrink-0" :stroke-width="2" />
            <span>{{ label }}</span>
        </div>
        <p class="mt-2 text-2xl font-semibold tabular-nums" :class="TONES[tone] ?? TONES.default">
            {{ value }}
        </p>
    </div>
</template>
