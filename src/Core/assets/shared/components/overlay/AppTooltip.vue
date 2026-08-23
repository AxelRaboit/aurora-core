<script setup>
import { ref, computed, onBeforeUnmount, nextTick } from "vue";

/**
 * Floating tooltip with a title + optional description, teleported to <body>
 * so it can never be clipped by an `overflow: hidden` ancestor.
 *
 * Usage:
 *   <AppTooltip title="Projects" description="Manage projects and tasks">
 *     <button>...</button>
 *   </AppTooltip>
 *
 * The default slot is the trigger element. The `content` slot can be used to
 * render fully custom HTML inside the tooltip - when provided it replaces the
 * title/description rendering.
 */
const props = defineProps({
    title: { type: String, default: "" },
    description: { type: String, default: "" },
    /** Preferred placement; auto-flips to the opposite side if the tooltip would overflow the viewport. */
    placement: {
        type: String,
        default: "right",
        validator: (value) => ["right", "left", "top", "bottom"].includes(value),
    },
    /** Show delay in ms - prevents flicker when sweeping over multiple items. */
    delay: { type: Number, default: 200 },
    /** Disable entirely (e.g. when sidemenu is expanded and labels are already visible). */
    disabled: { type: Boolean, default: false },
});

const visible = ref(false);
const triggerEl = ref(null);
const tooltipEl = ref(null);
const tooltipStyle = ref({});
const resolvedPlacement = ref(props.placement);

let showTimer = null;

const hasContent = computed(
    () => Boolean(props.title) || Boolean(props.description),
);

function clearShowTimer() {
    if (showTimer) {
        clearTimeout(showTimer);
        showTimer = null;
    }
}

async function show() {
    if (props.disabled || !hasContent.value) return;
    clearShowTimer();
    showTimer = setTimeout(async () => {
        visible.value = true;
        await nextTick();
        position();
    }, props.delay);
}

function hide() {
    clearShowTimer();
    visible.value = false;
}

function position() {
    if (!triggerEl.value || !tooltipEl.value) return;
    // The wrapper uses `display: contents` so it has no layout box of its own -
    // measure the first real child element instead (the actual nav button/link).
    const anchor = triggerEl.value.firstElementChild ?? triggerEl.value;
    const triggerRect = anchor.getBoundingClientRect();
    const tooltipRect = tooltipEl.value.getBoundingClientRect();
    const gap = 8;
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;

    // Try the preferred placement, then flip if it would overflow.
    const placements = {
        right: () => ({
            left: triggerRect.right + gap,
            top: triggerRect.top + triggerRect.height / 2 - tooltipRect.height / 2,
        }),
        left: () => ({
            left: triggerRect.left - tooltipRect.width - gap,
            top: triggerRect.top + triggerRect.height / 2 - tooltipRect.height / 2,
        }),
        top: () => ({
            left: triggerRect.left + triggerRect.width / 2 - tooltipRect.width / 2,
            top: triggerRect.top - tooltipRect.height - gap,
        }),
        bottom: () => ({
            left: triggerRect.left + triggerRect.width / 2 - tooltipRect.width / 2,
            top: triggerRect.bottom + gap,
        }),
    };

    const opposite = { right: "left", left: "right", top: "bottom", bottom: "top" };
    const fitsViewport = (pos) =>
        pos.left >= 0 &&
        pos.top >= 0 &&
        pos.left + tooltipRect.width <= viewportWidth &&
        pos.top + tooltipRect.height <= viewportHeight;

    let chosen = props.placement;
    let pos = placements[chosen]();
    if (!fitsViewport(pos)) {
        const flipped = opposite[chosen];
        const flippedPos = placements[flipped]();
        if (fitsViewport(flippedPos)) {
            chosen = flipped;
            pos = flippedPos;
        }
    }

    // Final clamp inside the viewport so a too-tall or too-wide tooltip stays visible.
    const left = Math.max(4, Math.min(pos.left, viewportWidth - tooltipRect.width - 4));
    const top = Math.max(4, Math.min(pos.top, viewportHeight - tooltipRect.height - 4));

    resolvedPlacement.value = chosen;
    tooltipStyle.value = { left: `${left}px`, top: `${top}px` };
}

onBeforeUnmount(() => clearShowTimer());
</script>

<template>
    <!--
        `display: contents` so wrapping a row in a tooltip does not add a box to
        its parent's layout - the trigger disappears and the slot's own element
        takes its place.

        The catch, for anyone laying out a list of these: **margins on this div
        do nothing**, because it generates no box. A parent using `space-y-*`
        silently gets no gaps at all, which is what happened to the whole side
        menu. Lay these out with `gap` instead - a `display: contents` child's
        own children are promoted to flex items and gaps do reach them.
    -->
    <div
        ref="triggerEl"
        class="contents"
        v-on:mouseenter="show"
        v-on:mouseleave="hide"
        v-on:focusin="show"
        v-on:focusout="hide"
    >
        <slot />
    </div>

    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="visible"
                ref="tooltipEl"
                role="tooltip"
                class="fixed pointer-events-none z-100 px-3 py-2 max-w-xs rounded-lg bg-surface-3 border border-line shadow-xl text-sm"
                :style="tooltipStyle"
            >
                <slot name="content">
                    <p v-if="title" class="font-semibold text-primary leading-tight">{{ title }}</p>
                    <p v-if="description" class="text-xs text-secondary mt-0.5 leading-snug">{{ description }}</p>
                </slot>
            </div>
        </Transition>
    </Teleport>
</template>
