<script setup>
import { computed } from "vue";
import { Info, AlertTriangle, AlertCircle, CheckCircle2, Trash2, X } from "lucide-vue-next";

const props = defineProps({
    variant: { type: String, default: "info" },
    icon: { type: [String, Boolean], default: true },
    /**
     * A cross to set it aside. The message does not decide for how long: it
     * emits `dismiss`, and the caller hides it - for the visit, most often, so
     * that it comes back on reload.
     */
    dismissible: { type: Boolean, default: false },
    /**
     * The name of the cross, for the tooltip and screen readers. Passed by the
     * caller rather than translated here: the message is mounted everywhere,
     * including in tests that do not install the translations.
     */
    dismissLabel: { type: String, default: "" },
});

const emit = defineEmits(["dismiss"]);

const VARIANTS = {
    // Explaining is not alerting.
    //
    // The five other variants are state colours: something happened, and the
    // colour says what. A sentence that explains how the screen in front of you
    // works announces nothing, and a blue box makes it shout louder than it
    // speaks. The workspace client card had written its own grey block for this
    // reason - rightly so in substance, and the only one of its kind in the
    // whole code.
    neutral: "border-line bg-surface-2/40 text-muted",
    info: "border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-300",
    success: "border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300",
    warning: "border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300",
    danger: "border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-700 dark:bg-rose-950/40 dark:text-rose-300",
    trash: "border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-700 dark:bg-rose-950/40 dark:text-rose-300",
};

const DEFAULT_ICONS = {
    neutral: Info,
    info: Info,
    success: CheckCircle2,
    warning: AlertTriangle,
    danger: AlertCircle,
    trash: Trash2,
};

const variantClass = computed(() => VARIANTS[props.variant] ?? VARIANTS.info);
const IconComponent = computed(() => (props.icon === false ? null : DEFAULT_ICONS[props.variant] ?? Info));
</script>

<template>
    <div class="flex items-start gap-3 rounded-lg border px-4 py-3 text-sm" :class="variantClass">
        <slot name="icon">
            <component :is="IconComponent" v-if="IconComponent" class="w-4 h-4 shrink-0 mt-0.5" :stroke-width="2" />
        </slot>
        <div class="flex-1 min-w-0">
            <slot />
        </div>
        <div v-if="$slots.actions" class="flex items-center gap-2 shrink-0">
            <slot name="actions" />
        </div>
        <button
            v-if="dismissible"
            type="button"
            class="-mr-1.5 -mt-1 shrink-0 rounded-md p-1.5 opacity-70 transition-opacity hover:opacity-100 focus-visible:opacity-100"
            :title="dismissLabel || undefined"
            :aria-label="dismissLabel || undefined"
            v-on:click="emit('dismiss')"
        >
            <X class="h-4 w-4" :stroke-width="2" />
        </button>
    </div>
</template>
