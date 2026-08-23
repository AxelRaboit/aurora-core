<script setup>
import AppTooltip from "@/shared/components/overlay/AppTooltip.vue";

/**
 * Button-flavoured sibling of AppNavLink - used for action rows in the sidemenu
 * (theme toggle, search palette trigger, logout-like buttons, etc.).
 *
 * Same `.si` styling, same tooltip behaviour, but renders a <button> instead of an <a>.
 */
defineOptions({ inheritAttrs: false });

defineProps({
    /** Tailwind hover/text colour for the icon and row when not active. Examples: "primary" | "emerald" | "amber" | "rose". */
    hoverColor: { type: String, default: "primary" },
    type: { type: String, default: "button" },
    tooltipTitle: { type: String, default: "" },
    tooltipDescription: { type: String, default: "" },
    tooltipPlacement: {
        type: String,
        default: "right",
        validator: (value) => ["right", "left", "top", "bottom"].includes(value),
    },
});

const HOVER_CLASSES = {
    primary: "text-secondary hover:text-primary hover:bg-surface-2",
    emerald: "text-secondary hover:text-emerald-400 hover:bg-emerald-500/10",
    amber: "text-secondary hover:text-amber-400 hover:bg-amber-500/10",
    rose: "text-secondary hover:text-rose-400 hover:bg-rose-600/10",
    accent: "text-secondary hover:text-accent-400 hover:bg-accent-600/10",
};
</script>

<template>
    <AppTooltip :title="tooltipTitle" :description="tooltipDescription" :placement="tooltipPlacement">
        <button
            v-bind="$attrs"
            :type="type"
            class="si flex items-center rounded-lg text-sm font-medium transition-colors w-full group relative"
            :class="HOVER_CLASSES[hoverColor] ?? HOVER_CLASSES.primary"
        >
            <slot />
        </button>
    </AppTooltip>
</template>
