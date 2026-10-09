<script setup>
import { computed } from "vue";
import { initials } from "@/shared/utils/format/initials.js";

const props = defineProps({
    name:      { type: String, default: "" },
    firstName: { type: String, default: "" },
    lastName:  { type: String, default: "" },
    email:     { type: String, default: "" },
    photoUrl:  { type: String, default: "" },
    /** sm | md | lg | xl  OR  a number (pixels) */
    size:      { type: [String, Number], default: "md" },
    /** "soft" = bg-accent-600/20 text-accent-400 (lists, table rows)
     *  "solid" = bg-accent-600 text-white (identity badge, sidemenu) */
    variant:   { type: String, default: "soft" },
    /** A CSS colour that paints the badge instead of the variant: each
     *  person's own colour, where several people are shown side by side and
     *  have to be told apart (the people co-editing a note). */
    color:     { type: String, default: "" },
});

const SIZE_CLASSES = {
    sm: "w-7 h-7 text-xs",
    md: "w-8 h-8 text-xs",
    lg: "w-9 h-9 text-sm",
    xl: "w-12 h-12 text-lg",
};

const VARIANT_CLASSES = {
    soft: "bg-accent-600/20 text-accent-400 font-bold",
    solid: "bg-accent-600 text-white font-semibold",
};

const isNumericSize = computed(() => typeof props.size === "number");

const sizeClass = computed(() =>
    isNumericSize.value ? "" : (SIZE_CLASSES[props.size] ?? SIZE_CLASSES.md),
);

const sizeStyle = computed(() => {
    const style = {};
    if (isNumericSize.value) {
        style.width = `${props.size}px`;
        style.height = `${props.size}px`;
        style.fontSize = `${Math.max(10, Math.round(props.size * 0.42))}px`;
    }
    if (props.color) style.backgroundColor = props.color;

    return Object.keys(style).length ? style : null;
});

const variantClass = computed(() =>
    props.color
        ? "text-white font-semibold"
        : (VARIANT_CLASSES[props.variant] ?? VARIANT_CLASSES.soft),
);

const label = computed(() => initials({
    name: props.name,
    firstName: props.firstName,
    lastName: props.lastName,
    email: props.email,
}));
</script>

<template>
    <div
        class="rounded-full overflow-hidden flex items-center justify-center shrink-0 uppercase"
        :class="[sizeClass, variantClass]"
        :style="sizeStyle"
    >
        <img
            v-if="photoUrl"
            :src="photoUrl"
            :alt="name || `${firstName} ${lastName}`.trim() || email"
            class="w-full h-full object-cover"
        >
        <span v-else>{{ label }}</span>
    </div>
</template>
