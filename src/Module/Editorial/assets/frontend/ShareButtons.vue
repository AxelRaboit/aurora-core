<script setup>
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Link, Copy, Check } from "lucide-vue-next";

/**
 * Copy the link, or hand it to LinkedIn or WhatsApp.
 *
 * Built from the two providers a client's own reference pages actually get
 * shared on, rather than a generic row of a dozen networks nobody here posts
 * to. Twitter/X and Facebook are conspicuously absent for that reason.
 */
const props = defineProps({
    url: { type: String, required: true },
    title: { type: String, required: true },
});

const { t } = useI18n();

const copied = ref(false);

async function copyLink() {
    try {
        await navigator.clipboard.writeText(props.url);
        copied.value = true;
        window.setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // A clipboard denied by the browser is not this component's to
        // recover from - the link is still on screen in every share link
        // below, and the reader can select it themselves.
    }
}

const linkedinUrl = () =>
    `https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(props.url)}`;

const whatsappUrl = () =>
    `https://wa.me/?text=${encodeURIComponent(`${props.title} ${props.url}`)}`;
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 border-t border-line pt-4">
        <span class="text-sm text-secondary">{{ t("frontend.editorial.share.label") }}</span>

        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-sm text-secondary hover:border-secondary hover:text-primary transition-colors"
            v-on:click="copyLink"
        >
            <Check v-if="copied" class="w-4 h-4" :stroke-width="2" />
            <Copy v-else class="w-4 h-4" :stroke-width="2" />
            {{ copied ? t("frontend.editorial.share.copied") : t("frontend.editorial.share.copy_link") }}
        </button>

        <a
            :href="linkedinUrl()"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-sm text-secondary hover:border-secondary hover:text-primary transition-colors"
        >
            <Link class="w-4 h-4" :stroke-width="2" />
            {{ t("frontend.editorial.share.linkedin") }}
        </a>

        <a
            :href="whatsappUrl()"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1.5 text-sm text-secondary hover:border-secondary hover:text-primary transition-colors"
        >
            <Link class="w-4 h-4" :stroke-width="2" />
            {{ t("frontend.editorial.share.whatsapp") }}
        </a>
    </div>
</template>
