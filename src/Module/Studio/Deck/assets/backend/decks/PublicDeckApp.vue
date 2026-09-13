<script setup>
/**
 * A deck, for somebody holding its address and nothing else.
 *
 * Slides stacked, largest first, and a button that opens the same full-screen
 * player the author uses. Stacked rather than paginated because a recipient
 * scrolls: they were sent a document, and reading one is not the same gesture
 * as presenting it - but the presenting gesture is one click away for the
 * reader who would rather have it.
 *
 * The speaker notes never reach this page. The controller strips them from the
 * payload rather than trusting this template to leave them out, which is the
 * right place for that decision: a template is edited far more often than a
 * serializer.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Play } from "lucide-vue-next";
import SlideFrame from "./components/SlideFrame.vue";
import DeckPlayer from "./components/DeckPlayer.vue";
import AppButton from "@/shared/components/action/AppButton.vue";

const props = defineProps({
    deck: { type: Object, required: true },
    /** ISO 8601, or null when the link lives until it is revoked. */
    expiresAt: { type: String, default: null },
});

const { t, d } = useI18n();

const playing = ref(false);
const slides = props.deck.slides ?? [];
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-4xl flex-col gap-6 p-4 sm:p-8">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="m-0 text-xl font-semibold text-primary">{{ deck.title }}</h1>
                <p v-if="deck.description" class="m-0 mt-1 text-sm text-secondary">
                    {{ deck.description }}
                </p>
            </div>

            <AppButton v-if="slides.length" variant="primary" v-on:click="playing = true">
                <Play class="h-4 w-4" :stroke-width="2" />
                {{ t("backend.studio.decks.present") }}
            </AppButton>
        </header>

        <div class="flex flex-col gap-4">
            <SlideFrame
                v-for="(slide, at) in slides"
                :key="slide.id"
                :slide="slide"
                :appearance="deck.appearance"
                :index="at + 1"
            />
        </div>

        <footer class="mt-auto border-t border-line/50 pt-3 text-xs text-muted">
            <p v-if="expiresAt" class="m-0">
                {{ t("backend.studio.decks.share_expires_on", { date: d(new Date(expiresAt), "short") }) }}
            </p>
            <p class="m-0">{{ t("backend.studio.decks.share_footer") }}</p>
        </footer>

        <DeckPlayer
            v-if="playing"
            :slides="slides"
            :appearance="deck.appearance"
            v-on:close="playing = false"
        />
    </div>
</template>
