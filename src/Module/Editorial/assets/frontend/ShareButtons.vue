<script setup>
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { Link, Copy, Check, Mail } from "lucide-vue-next";
import { resolveShareLinks } from "./shareLinks.js";

/**
 * The share block at the bottom of a publication.
 *
 * Its links are the page's own when an editor chose them - which ones, in
 * which order, with which words and colours - and otherwise the default row
 * of copy, LinkedIn and Facebook.
 */
const props = defineProps({
    url: { type: String, required: true },
    title: { type: String, required: true },
    links: { type: Array, default: null },
});

const { t } = useI18n();

const copied = ref(false);

const items = computed(() => resolveShareLinks(props.links, props.url, props.title));

async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.url);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // A clipboard denied by the browser is not this component's to
        // recover from - the address is in the reader's own address bar.
    }
}

function label(link) {
    if ("copy" === link.type && copied.value) {
        return t("frontend.editorial.share.copied");
    }

    return link.label || t(`frontend.editorial.share.${"copy" === link.type ? "copy_link" : link.type}`);
}

// A chosen colour tints the text and the outline; without one the link keeps
// the neutral look of the rest of the block.
function tint(link) {
    return link.color ? { color: link.color, borderColor: link.color } : null;
}
</script>

<template>
    <div v-if="items.length" class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-secondary">{{ t("frontend.editorial.share.label") }}</span>

        <template v-for="(link, index) in items" :key="index">
            <button
                v-if="'copy' === link.type"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-sm text-secondary hover:border-secondary hover:text-primary transition-colors"
                :style="tint(link)"
                v-on:click="copyLink"
            >
                <Check v-if="copied" class="w-4 h-4" :stroke-width="2" />
                <Copy v-else class="w-4 h-4" :stroke-width="2" />
                {{ label(link) }}
            </button>

            <a
                v-else
                :href="link.href"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-sm text-secondary hover:border-secondary hover:text-primary transition-colors"
                :style="tint(link)"
            >
                <Mail v-if="'email' === link.type" class="w-4 h-4" :stroke-width="2" />
                <Link v-else class="w-4 h-4" :stroke-width="2" />
                {{ label(link) }}
            </a>
        </template>
    </div>
</template>
