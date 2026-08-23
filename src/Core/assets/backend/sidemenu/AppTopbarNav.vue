<script setup>
/**
 * Back, forward and reload, in the page header.
 *
 * Browser chrome brought into the page, the way Arc puts it beside the tab
 * strip: the backend is used as an application, and an application that hides
 * its own history behind the browser's toolbar is one gesture further from
 * everything.
 *
 * Presentation only - what each one actually does, and what it honestly can and
 * cannot do, lives in `useTopbarNavigation`.
 */
import { useI18n } from "vue-i18n";
import { ArrowLeft, ArrowRight, RotateCw } from "lucide-vue-next";
import { useTopbarNavigation } from "./composables/useTopbarNavigation.js";

const { t } = useI18n();
const { canGoBack, back, forward, reloading, hardReload } = useTopbarNavigation();

const BUTTON =
    "shrink-0 rounded-lg p-1.5 text-muted transition-colors hover:bg-surface-2 hover:text-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-muted";
</script>

<template>
    <div class="flex items-center gap-0.5">
        <button
            type="button"
            :class="BUTTON"
            :disabled="!canGoBack"
            :title="t('backend.nav.go_back')"
            :aria-label="t('backend.nav.go_back')"
            v-on:click="back"
        >
            <ArrowLeft class="w-5 h-5" :stroke-width="2" />
        </button>

        <!-- Never disabled: nothing in the platform says whether a forward
             entry exists, so greying this out would be a guess wearing the
             costume of knowledge. It is occasionally a no-op instead. -->
        <button
            type="button"
            :class="BUTTON"
            :title="t('backend.nav.go_forward')"
            :aria-label="t('backend.nav.go_forward')"
            v-on:click="forward"
        >
            <ArrowRight class="w-5 h-5" :stroke-width="2" />
        </button>

        <button
            type="button"
            :class="BUTTON"
            :disabled="reloading"
            :title="t('backend.nav.hard_reload')"
            :aria-label="t('backend.nav.hard_reload')"
            v-on:click="hardReload"
        >
            <RotateCw class="w-5 h-5" :class="{ 'animate-spin': reloading }" :stroke-width="2" />
        </button>
    </div>
</template>
