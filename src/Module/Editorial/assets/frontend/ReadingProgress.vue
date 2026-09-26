<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";

/**
 * A thin bar pinned to the top of the viewport, filled by how far the reader
 * has scrolled through the whole page.
 *
 * The whole document's height rather than `<article>`'s own: the reader has
 * no way to tell where the article ends and the comment thread begins, and a
 * bar that reaches full before the page does reads as broken, not as done.
 *
 * `motion-reduce:hidden` rather than a static bar: a fill that jumps to its
 * final position on every scroll event is still motion, and a reader who
 * asked for less of it should see nothing move at all.
 */
const progress = ref(0);

function update() {
    const doc = document.documentElement;
    const max = doc.scrollHeight - doc.clientHeight;

    progress.value = max > 0 ? Math.min(100, Math.max(0, (doc.scrollTop / max) * 100)) : 0;
}

onMounted(() => {
    update();
    window.addEventListener("scroll", update, { passive: true });
    window.addEventListener("resize", update);
});

onBeforeUnmount(() => {
    window.removeEventListener("scroll", update);
    window.removeEventListener("resize", update);
});
</script>

<template>
    <div class="fixed inset-x-0 top-0 z-50 h-1 bg-transparent motion-reduce:hidden" aria-hidden="true">
        <div
            class="h-full bg-accent-500 transition-[width] duration-150 ease-out"
            :style="{ width: progress + '%' }"
        />
    </div>
</template>
