<script setup>
/**
 * Enlarging a picture, full screen, wherever pictures are shown.
 *
 * **It renders no tile.** The pictures come from somewhere else - a Twig
 * template, a Markdown page - and this is the layer on top: without it they
 * are still there, just not enlargeable. That is what keeps a gallery
 * visible to a crawler, to a reader with no JavaScript and to the print
 * stylesheet, and it is why this component owns none of the markup.
 *
 * It finds its triggers by one delegated listener on an attribute the caller
 * names, rather than by owning the tiles: one listener however many pictures
 * there are, and two sets of pictures on one page keep their own order by
 * using two attributes.
 *
 * Keyboard throughout, because that is what a full-screen viewer is for once
 * it is open: arrows to move, Escape to leave, and focus put on the dialog so
 * those keys arrive without a click first.
 */
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronLeft, ChevronRight, X } from "lucide-vue-next";

const props = defineProps({
    /**
     * The pictures, in the order the tiles were drawn: `{url, alt, caption}`.
     * The index carried by a trigger is an index into this list.
     */
    items: { type: Array, default: () => [] },
    /**
     * Which attribute marks a trigger.
     *
     * A page can hold two sets of enlargeable pictures - a gallery and the
     * media zones of a grid - and each has its own order, so each needs its
     * own overlay. One attribute for both would have every click open both
     * overlays, on two different pictures.
     */
    trigger: { type: String, default: "data-gallery-open" },
});

const { t } = useI18n();

const index = ref(null);
const dialog = ref(null);

const open = computed(() => null !== index.value);
const current = computed(() => props.items[index.value] ?? null);

function show(at) {
    if (at < 0 || at >= props.items.length) {
        return;
    }

    index.value = at;
    // The overlay covers the page; letting the page keep scrolling behind it
    // moves the gallery out from under the reader while they look at one picture.
    document.body.style.overflow = "hidden";
}

function close() {
    index.value = null;
    document.body.style.overflow = "";
}

/** Clamped rather than wrapping: the ends of a gallery are the ends of it. */
function step(by) {
    const next = index.value + by;

    if (next >= 0 && next < props.items.length) {
        index.value = next;
    }
}

function onDocumentClick(event) {
    const trigger = event.target.closest?.(`[${props.trigger}]`);
    if (!trigger) {
        return;
    }

    event.preventDefault();
    show(Number(trigger.getAttribute(props.trigger)));
}

function onKeydown(event) {
    if (!open.value) {
        return;
    }

    const handled = {
        Escape: close,
        ArrowLeft: () => step(-1),
        ArrowRight: () => step(1),
    }[event.key];

    if (handled) {
        event.preventDefault();
        handled();
    }
}

onMounted(() => {
    document.addEventListener("click", onDocumentClick);
    document.addEventListener("keydown", onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener("click", onDocumentClick);
    document.removeEventListener("keydown", onKeydown);
    // Leaving with the overlay open would leave the page unscrollable.
    document.body.style.overflow = "";
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-150"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                ref="dialog"
                role="dialog"
                aria-modal="true"
                tabindex="-1"
                class="fixed inset-0 z-100 flex flex-col bg-black/90 p-2 outline-none sm:p-4"
                v-on:click.self="close"
            >
                <div class="flex items-center justify-between gap-4 text-white/70">
                    <span class="text-sm tabular-nums">{{ index + 1 }} / {{ items.length }}</span>
                    <button
                        type="button"
                        class="rounded-md p-2 transition-colors hover:bg-white/10 hover:text-white"
                        :aria-label="t('frontend.gallery.close')"
                        v-on:click="close"
                    >
                        <X class="h-5 w-5" :stroke-width="2" />
                    </button>
                </div>

                <div class="flex min-h-0 flex-1 items-center gap-2">
                    <button
                        type="button"
                        class="shrink-0 rounded-md p-2 text-white/70 transition-colors hover:bg-white/10 hover:text-white disabled:opacity-30"
                        :disabled="0 === index"
                        :aria-label="t('frontend.gallery.previous')"
                        v-on:click="step(-1)"
                    >
                        <ChevronLeft class="h-6 w-6" :stroke-width="2" />
                    </button>

                    <!-- `object-contain`, unlike the tile: here the whole
                         picture is the point, and cropping it would defeat
                         opening it. -->
                    <!-- `h-full min-h-0`: without them the figure is a flex
                         item whose height comes from its content, so a tall
                         picture pushed it past the row and out of the screen -
                         and `max-h-full` on the image resolved against a box
                         that had already overflowed. -->
                    <figure class="m-0 flex h-full min-h-0 min-w-0 flex-1 flex-col items-center gap-3">
                        <img
                            v-if="current"
                            :src="current.url"
                            :alt="current.alt"
                            class="max-h-full min-h-0 w-auto max-w-full flex-1 rounded-lg object-contain"
                        >
                        <figcaption v-if="current?.caption" class="shrink-0 text-center text-sm text-white/70">
                            {{ current.caption }}
                        </figcaption>
                    </figure>

                    <button
                        type="button"
                        class="shrink-0 rounded-md p-2 text-white/70 transition-colors hover:bg-white/10 hover:text-white disabled:opacity-30"
                        :disabled="index === items.length - 1"
                        :aria-label="t('frontend.gallery.next')"
                        v-on:click="step(1)"
                    >
                        <ChevronRight class="h-6 w-6" :stroke-width="2" />
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
