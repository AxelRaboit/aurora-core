<script setup>
defineProps({
    /** 0-100 */
    value: { type: Number, required: true },
    /** Show percentage label below the bar */
    showLabel: { type: Boolean, default: false },
    /** Label text - defaults to "{value}%" */
    label: { type: String, default: null },
    color: { type: String, default: "accent" },
    /** sm = h-1.5, md = h-2 */
    size: { type: String, default: "md" },
});

const COLORS = {
    accent: "bg-accent-500",
    emerald: "bg-emerald-500",
    rose: "bg-rose-500",
    amber: "bg-amber-500",
};

const SIZES = { sm: "h-1.5", md: "h-2" };
</script>

<template>
    <div class="space-y-1">
        <div class="w-full bg-line rounded-full overflow-hidden" :class="SIZES[size] ?? SIZES.md">
            <div
                class="rounded-full transition-all duration-200"
                :class="[COLORS[color] ?? COLORS.accent, SIZES[size] ?? SIZES.md]"
                :style="{ width: Math.min(100, Math.max(0, value)) + '%' }"
            />
        </div>
        <p v-if="showLabel" class="text-xs text-muted text-center">
            {{ label ?? value + '%' }}
        </p>
    </div>
</template>
