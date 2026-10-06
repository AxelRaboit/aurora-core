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
 * Opened from the client's space, it leads back there (`backUrl`); opened
 * by a reading link, there is nowhere to go back to and the link is absent.
 *
 * The speaker notes never reach this page. The controller strips them from the
 * payload rather than trusting this template to leave them out, which is the
 * right place for that decision: a template is edited far more often than a
 * serializer.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { ArrowLeft, Play } from "lucide-vue-next";
import SlideFrame from "./components/SlideFrame.vue";
import DeckPlayer from "./components/DeckPlayer.vue";
import AppButton from "@/shared/components/action/AppButton.vue";

const props = defineProps({
    deck: { type: Object, required: true },
    /** ISO 8601, or null when the link lives until it is revoked. */
    expiresAt: { type: String, default: null },
    /** The client's space, when the deck was opened from it. */
    backUrl: { type: String, default: null },
});

const { t, d } = useI18n();

const playing = ref(false);
const slides = props.deck.slides ?? [];
</script>

<template>
    <div class="mx-auto flex min-h-screen w-full max-w-4xl flex-col gap-5 px-2 py-2 sm:p-8">
        <a
            v-if="backUrl"
            :href="backUrl"
            class="inline-flex min-h-[38px] items-center gap-1.5 self-start text-sm text-secondary no-underline hover:text-primary"
        >
            <ArrowLeft class="h-4 w-4" :stroke-width="2" />
            {{ t("frontend.reading.back") }}
        </a>

        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="m-0 text-xl font-semibold text-primary">{{ deck.title }}</h1>
                <p v-if="deck.description" class="m-0 mt-1 text-sm text-secondary">
                    {{ deck.description }}
                </p>
            </div>

            <!-- The page's only action takes the full line on a phone: a client
                 opens it, often from their home screen. -->
            <AppButton
                v-if="slides.length"
                class="w-full sm:w-auto"
                variant="primary"
                v-on:click="playing = true"
            >
                <Play class="h-4 w-4" :stroke-width="2" />
                {{ t("suite.studio.deliverables.slides.present") }}
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

        <footer class="mt-auto border-t border-line pt-3 text-xs text-muted">
            <p v-if="expiresAt" class="m-0">
                {{ t("suite.studio.deliverables.slides.share_expires_on", { date: d(new Date(expiresAt), "short") }) }}
            </p>
            <p class="m-0">{{ t("suite.studio.deliverables.slides.share_footer") }}</p>
        </footer>

        <DeckPlayer
            v-if="playing"
            :slides="slides"
            :appearance="deck.appearance"
            v-on:close="playing = false"
        />
    </div>
</template>
