<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Doughnut, Bar, Line } from "vue-chartjs";
import {
    Chart as ChartJS,
    ArcElement,
    Tooltip,
    Legend,
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    Filler,
} from "chart.js";

ChartJS.register(
    ArcElement,
    Tooltip,
    Legend,
    CategoryScale,
    LinearScale,
    BarElement,
    LineElement,
    PointElement,
    Filler,
);

const props = defineProps({
    type: { type: String, required: true, validator: (type) => ["doughnut", "bar", "line"].includes(type) },
    data: { type: Object, required: true },
    options: { type: Object, default: () => ({}) },
});

const component = computed(() => ({ doughnut: Doughnut, bar: Bar, line: Line })[props.type]);

/**
 * Chart.js paints into a canvas, so it cannot read a CSS custom property: every
 * colour has to arrive as a resolved string. The defaults here used to be six
 * hardcoded hex values from the dark palette, which meant a light theme drew
 * grey-on-white legends and a near-black tooltip.
 *
 * They are read off the document instead, once on mount and again whenever the
 * theme changes, so the chart follows `--th-*` like everything rendered in DOM
 * does. This is the whole reason `AppShareBar` exists for compositions: a bar
 * made of elements gets this for free, and only axes and time series are worth
 * paying a canvas for.
 */
const ink = ref({ secondary: "#9CA3AF", primary: "#F3F4F6", surface: "#111827", line: "#374151" });

function readTokens() {
    const style = getComputedStyle(document.documentElement);
    const token = (name, fallback) => style.getPropertyValue(name).trim() || fallback;

    ink.value = {
        secondary: token("--th-secondary", ink.value.secondary),
        primary: token("--th-primary", ink.value.primary),
        surface: token("--th-surface-3", ink.value.surface),
        line: token("--color-border", ink.value.line),
    };
}

let observer = null;

onMounted(() => {
    readTokens();
    // The theme is a class on the root element, so watching its attributes is
    // how a canvas learns the palette moved under it.
    observer = new MutationObserver(readTokens);
    observer.observe(document.documentElement, { attributeFilter: ["class"] });
});

onBeforeUnmount(() => observer?.disconnect());

const baseOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        // A single series needs no legend: its name is the chart's title, and
        // the legend only repeated it (UI audit of 07/10/2026). A doughnut
        // keeps its own, which names the slices.
        legend: {
            display: "doughnut" === props.type || (props.data?.datasets?.length ?? 0) > 1,
            labels: { color: ink.value.secondary, font: { size: 11 } },
        },
        tooltip: {
            backgroundColor: ink.value.surface,
            titleColor: ink.value.primary,
            bodyColor: ink.value.secondary,
            borderColor: ink.value.line,
            borderWidth: 1,
        },
    },
    scales:
        "doughnut" === props.type
            ? undefined
            : {
                // Labels stay horizontal and skip what does not fit, rather
                // than tilting twelve month names.
                x: { ticks: { color: ink.value.secondary, maxRotation: 0, autoSkip: true, autoSkipPadding: 12 }, grid: { display: false }, border: { color: ink.value.line } },
                y: { ticks: { color: ink.value.secondary, precision: 0 }, grid: { color: ink.value.line }, border: { color: ink.value.line }, beginAtZero: true },
            },
}));

const mergedOptions = computed(() => ({ ...baseOptions.value, ...props.options }));
</script>

<template>
    <component :is="component" :data="data" :options="mergedOptions" />
</template>
