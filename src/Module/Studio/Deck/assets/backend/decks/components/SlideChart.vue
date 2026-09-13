<script setup>
/**
 * A chart on a slide, in the deck's colours rather than the back office's.
 *
 * `AppChart` exists and is not used here on purpose: it reads its palette off
 * `document.documentElement`, which is right for a dashboard and wrong for a
 * slide. A deck set in `Paper` is routinely composed inside a dark back office,
 * and printed on white from a browser in either theme; the only colours that
 * mean anything here are the ones the frame is drawing with.
 *
 * **Animation off.** The print page opens, waits a frame and calls
 * `window.print()`, and a chart that fades in over 400ms prints as whatever it
 * had drawn by then, which is usually nothing.
 */
import { computed } from "vue";
import { Bar, Doughnut, Line } from "vue-chartjs";
import {
    ArcElement,
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Filler,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from "chart.js";
import { cells } from "../cells.js";
import { fade, figure, ramp } from "../colour.js";

// Registering twice is a no-op: Chart.js keeps a registry by id, and this
// component and `AppChart` can both be on screen without arguing.
ChartJS.register(
    ArcElement,
    BarElement,
    CategoryScale,
    Filler,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
);

const props = defineProps({
    /** One row per line: a label, a pipe, a figure. */
    rows: { type: Array, default: () => [] },
    kind: { type: String, default: "bar" },
    ink: { type: String, default: "#e6e9ef" },
    accent: { type: String, default: "#58a6ff" },
});

const KINDS = { bar: Bar, line: Line, doughnut: Doughnut };

/** Anything unknown draws as bars rather than as nothing. */
const component = computed(() => KINDS[props.kind] ?? Bar);

const points = computed(() =>
    props.rows
        .map((row) => {
            const [label, value] = cells(row);

            return { label, value: figure(value) };
        })
        .filter((point) => point.label !== ""),
);

const data = computed(() => {
    const values = points.value.map((point) => point.value);

    return {
        labels: points.value.map((point) => point.label),
        datasets: [
            {
                data: values,
                // A doughnut needs one tone per slice to be read at all; a bar
                // chart of one series does not, and a rainbow there would say
                // that the bars differ in kind rather than in size.
                backgroundColor:
                    props.kind === "doughnut"
                        ? ramp(props.accent, values.length)
                        : fade(props.accent, props.kind === "line" ? 0.18 : 0.85),
                borderColor: props.accent,
                borderWidth: props.kind === "doughnut" ? 0 : 2,
                fill: props.kind === "line",
                tension: 0.3,
                pointBackgroundColor: props.accent,
                borderRadius: props.kind === "bar" ? 3 : 0,
            },
        ],
    };
});

const options = computed(() => {
    const label = fade(props.ink, 0.75);
    const grid = fade(props.ink, 0.12);

    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        // Nobody hovers a slide on a wall, and the tooltip is drawn in the back
        // office's colours whatever this component does.
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        layout: { padding: 0 },
        scales:
            props.kind === "doughnut"
                ? undefined
                : {
                    x: { ticks: { color: label }, grid: { display: false }, border: { color: grid } },
                    y: { ticks: { color: label }, grid: { color: grid }, border: { display: false }, beginAtZero: true },
                },
    };
});
</script>

<template>
    <div class="slide-chart">
        <component :is="component" :data="data" :options="options" />
    </div>
</template>

<style scoped>
/* Une hauteur explicite : `maintainAspectRatio: false` demande au conteneur de
   décider, et un conteneur en `flex: 1` dans une boîte dont la hauteur vient
   d'un rapport 16/9 n'en a pas à donner tant que le canevas n'a pas de taille. */
.slide-chart {
    flex: 1;
    min-height: 0;
    position: relative;
}
</style>
