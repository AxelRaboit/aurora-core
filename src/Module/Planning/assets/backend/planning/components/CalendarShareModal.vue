<script setup>
/**
 * Sharing one calendar by link, in a window of its own.
 *
 * It lived in the calendar's settings modal, and that was wrong twice over.
 * Handing an address to somebody outside is not a setting on a calendar, and
 * putting it at the bottom of an edit form meant the only way to find it was to
 * go looking for something else - which is how a feature ships and stays unused.
 *
 * The panel inside is `CalendarShareLinks`, unchanged. This is the frame and the
 * title.
 */
import { useI18n } from "vue-i18n";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import CalendarShareLinks from "./CalendarShareLinks.vue";

const props = defineProps({
    /** The calendar being shared, or null when nothing is open. */
    calendar: { type: Object, default: null },
    links: { type: Array, default: () => [] },
    errors: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "create", "revoke"]);

const { t } = useI18n();
</script>

<template>
    <AppModal
        :show="null !== calendar"
        max-width="lg"
        mobile-fullscreen
        :title="t('backend.plannings.links.title', { name: calendar?.name ?? '' })"
        v-on:close="emit('close')"
    >
        <CalendarShareLinks
            :links="links"
            :errors="errors"
            :saving="saving"
            v-on:create="emit('create', $event)"
            v-on:revoke="emit('revoke', $event)"
        />
    </AppModal>
</template>
