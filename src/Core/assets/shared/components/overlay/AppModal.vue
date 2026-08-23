<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { X } from "lucide-vue-next";
import { useI18n } from "vue-i18n";
import { useBackButtonClose } from "@/shared/composables/overlay/useBackButtonClose.js";
import AppRetainedSlot from "@/shared/components/overlay/AppRetainedSlot.vue";

const props = defineProps({
    show: { type: Boolean, default: false },
    maxWidth: { type: String, default: "md" },
    closeable: { type: Boolean, default: true },
    // When provided, renders a flex header row (title + optional icon + X close button).
    // Leave empty to manage the header yourself inside the slot.
    title: { type: String, default: null },
    /** Lucide icon component to display to the left of the title. */
    icon: { type: [Object, Function], default: null },
    noPadding: { type: Boolean, default: false },
    scrollable: { type: Boolean, default: true },
    /**
     * On viewports below the Tailwind `md` breakpoint (768px), expand
     * the panel to the full viewport so wide content (canvases, tables,
     * long forms) remains usable on phones. Defaults to false to keep
     * existing modals visually unchanged.
     */
    mobileFullscreen: { type: Boolean, default: false },
    /**
     * Allow clicks on the dark overlay to close the modal. Default `true`
     * matches the historical behavior (lightweight modals = overlay click
     * cancels). Set to `false` for form modals so an accidental click on
     * the backdrop doesn't wipe in-flight user input - ESC + the X button
     * still close.
     */
    closeOnOverlay: { type: Boolean, default: true },
});

const { t } = useI18n();
const emit = defineEmits(["close"]);
const showSlot = ref(props.show);

/**
 * The header, held for the length of the leave transition.
 *
 * Same reason as {@see AppRetainedSlot}, one layer up: a caller that computes its
 * title from the record being shown hands us an empty string the moment that
 * record goes away, and the header disappeared out from under a panel that was
 * still on screen. Held here because a prop cannot be frozen by the slot holder.
 */
const heldTitle = ref(props.title);
const heldIcon = ref(props.icon);

watch(() => [props.show, props.title, props.icon], () => {
    if (props.show) {
        heldTitle.value = props.title;
        heldIcon.value = props.icon;
    }
}, { immediate: true });

watch(() => props.show, (show) => {
    if (show) {
        document.body.style.overflow = "hidden";
        showSlot.value = true;
    } else {
        document.body.style.overflow = "";
        setTimeout(() => { showSlot.value = false; }, 200);
    }
});

const { requestClose } = useBackButtonClose({
    isOpen: () => props.show,
    onClose: () => emit("close"),
});

function close() {
    if (props.closeable) requestClose();
}

function onOverlayClick() {
    if (props.closeOnOverlay) close();
}

function closeOnEscape(event) {
    if (event.key === "Escape") {
        event.preventDefault();
        if (props.show) close();
    }
}

onMounted(() => {
    document.addEventListener("keydown", closeOnEscape);
    if (props.show) document.body.style.overflow = "hidden";
});
onUnmounted(() => {
    document.removeEventListener("keydown", closeOnEscape);
    document.body.style.overflow = "";
});

// Two parallel maps so Tailwind's class scanner sees every literal
// at build time (no runtime string concat - JIT would miss them).
const MAX_WIDTH = {
    sm: "max-w-sm",
    md: "max-w-md",
    lg: "max-w-lg",
    xl: "max-w-xl",
    "2xl": "max-w-2xl",
    "3xl": "max-w-3xl",
    "4xl": "max-w-4xl",
    "5xl": "max-w-5xl",
    "6xl": "max-w-6xl",
    "7xl": "max-w-7xl",
    full: "max-w-full",
};
const MD_MAX_WIDTH = {
    sm: "md:max-w-sm",
    md: "md:max-w-md",
    lg: "md:max-w-lg",
    xl: "md:max-w-xl",
    "2xl": "md:max-w-2xl",
    "3xl": "md:max-w-3xl",
    "4xl": "md:max-w-4xl",
    "5xl": "md:max-w-5xl",
    "6xl": "md:max-w-6xl",
    "7xl": "md:max-w-7xl",
    full: "md:max-w-full",
};
const maxWidthClass = computed(() => MAX_WIDTH[props.maxWidth] ?? "max-w-md");
const mdMaxWidthClass = computed(() => MD_MAX_WIDTH[props.maxWidth] ?? "md:max-w-md");

const panelClass = computed(() => [
    // On mobile-fullscreen, drop the responsive max-width below md so
    // the panel fills the viewport. Above md the requested cap kicks
    // back in as a normal Tailwind utility.
    props.mobileFullscreen
        ? `max-w-full ${mdMaxWidthClass.value}`
        : maxWidthClass.value,
    // The corner radius and the border live here and nowhere else. They used to
    // be on the static class as well, and `rounded-none` lost: two utilities on
    // one property are settled by their order in the compiled stylesheet, not by
    // their order in the attribute, and `.rounded-xl` is emitted after
    // `.rounded-none`. So a fullscreen panel kept its rounded corners and its
    // hairline border against the edge of the screen, with nothing behind either.
    props.mobileFullscreen ? "rounded-none border-0 md:rounded-xl md:border" : "rounded-xl border",
    props.noPadding ? "overflow-hidden" : "",
    props.scrollable
        ? props.mobileFullscreen
            ? "max-h-screen md:max-h-[90vh]"
            : "max-h-[90vh]"
        : "",
]);

const wrapperClass = computed(() =>
    props.mobileFullscreen
        ? "fixed inset-0 z-50 flex items-stretch md:items-center justify-center md:px-4"
        : "fixed inset-0 z-50 flex items-center justify-center px-4",
);

const contentClass = computed(() => [
    props.noPadding ? "" : "px-6 space-y-4",
    props.scrollable ? "overflow-y-auto scrollbar-thin flex-1 py-6" : "py-6",
]);
</script>

<template>
    <Teleport to="body">
        <div v-if="showSlot" :class="wrapperClass">
            <Transition
                enter-active-class="ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="show" class="fixed inset-0 bg-black/60" v-on:click="onOverlayClick" />
            </Transition>

            <Transition
                enter-active-class="ease-out duration-200"
                enter-from-class="opacity-0 scale-95"
                enter-to-class="opacity-100 scale-100"
                leave-active-class="ease-in duration-150"
                leave-from-class="opacity-100 scale-100"
                leave-to-class="opacity-0 scale-95"
            >
                <div
                    v-show="show"
                    role="dialog"
                    aria-modal="true"
                    class="relative z-10 w-full bg-surface border-line shadow-xl flex flex-col overflow-hidden"
                    :class="panelClass"
                >
                    <!-- Header -->
                    <div v-if="heldTitle" class="shrink-0 flex items-center justify-between gap-4 px-6 pt-6 pb-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <component :is="heldIcon" v-if="heldIcon" class="w-4 h-4 shrink-0 text-muted" :stroke-width="2" />
                            <h2 class="text-lg font-semibold text-primary truncate">{{ heldTitle }}</h2>
                        </div>
                        <button
                            v-if="closeable"
                            type="button"
                            class="shrink-0 p-1.5 text-muted hover:text-primary hover:bg-surface-2 rounded-lg transition-colors"
                            :aria-label="t('shared.common.close')"
                            v-on:click="close"
                        >
                            <X class="w-4 h-4" :stroke-width="2" />
                        </button>
                    </div>


                    <!-- Scrollable content -->
                    <div :class="contentClass">
                        <AppRetainedSlot :live="show">
                            <slot />
                        </AppRetainedSlot>
                    </div>

                    <!-- Sticky footer -->
                    <div v-if="$slots.footer" class="shrink-0 px-6 pb-6 pt-3 border-t border-line">
                        <AppRetainedSlot :live="show">
                            <slot name="footer" />
                        </AppRetainedSlot>
                    </div>
                </div>
            </Transition>
        </div>
    </Teleport>
</template>
