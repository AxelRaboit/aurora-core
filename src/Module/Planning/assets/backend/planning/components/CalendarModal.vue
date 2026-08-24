<script setup>
/**
 * One calendar, created or edited.
 *
 * The screen had none of this: the three routes existed and were tested, and
 * nothing ever called them - so a fresh installation showed an empty sidebar,
 * with the "new event" button hidden because an event needs a calendar. The page
 * was unusable until a fixture made the first one.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Trash2, X } from "lucide-vue-next";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import CalendarShareLinks from "./CalendarShareLinks.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import { COLOUR_SLOTS, nextFreeColourSlot } from "../composables/calendarColours.js";

const props = defineProps({
    /** The calendar being edited, `{}` for a new one, null when closed. */
    calendar: { type: Object, default: null },
    /** Every calendar on screen, so a new one can take a colour nobody uses. */
    calendars: { type: Array, default: () => [] },
    timezones: { type: Array, default: () => [] },
    /** Accounts that can be shared with, as `{ value, label }`. */
    people: { type: Array, default: () => [] },
    currentUserId: { type: [Number, null], default: null },
    errors: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
    /** The address, returned only by the request that created it. */
    /** Links pointing at this calendar, filtered by the parent. */
    links: { type: Array, default: () => [] },
    linkErrors: { type: Object, default: () => ({}) },
    savingLink: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "save", "delete", "create-link", "revoke-link", "set-shares"]);

const { t } = useI18n();

const form = ref(blank());

function blank() {
    return {
        name: "",
        description: "",
        colourSlot: nextFreeColourSlot(props.calendars),
        timezone: defaultTimezone(),
        visibility: "private",
    };
}

/**
 * The reader's own zone, which is the only sensible default.
 *
 * It used to be `timezones[0]`, and that list arrives alphabetically from
 * `DateTimeZone::listIdentifiers()` - so every new calendar was born in
 * Africa/Abidjan, and every all-day event on it was cut on Abidjan's midnight.
 *
 * Falls back to the entity's own default rather than to nothing, and checks the
 * zone is one the picker offers: a browser reporting a name PHP does not know
 * would otherwise preselect an option that is not in the list, which shows as an
 * empty select.
 */
function defaultTimezone() {
    const mine = Intl.DateTimeFormat().resolvedOptions().timeZone;

    if (mine && props.timezones.includes(mine)) {
        return mine;
    }

    return props.timezones.includes("Europe/Paris") ? "Europe/Paris" : (props.timezones[0] ?? "Europe/Paris");
}

const isNew = computed(() => !props.calendar?.id);

/**
 * Only the owner decides who else gets in.
 *
 * Somebody granted write access can put things on a calendar; handing out keys is
 * not the same authority, and the server refuses it either way - this is the half
 * that stops the reader trying.
 */
const isOwner = computed(
    () => null !== props.currentUserId && props.calendar?.ownerId === props.currentUserId,
);

/** The share list being edited, as ids for the picker plus a level per person. */
const shares = ref([]);

watch(
    () => props.calendar,
    () => {
        shares.value = (props.calendar?.shares ?? []).map((share) => ({ ...share }));
    },
    { immediate: true },
);

const sharedIds = computed({
    get: () => shares.value.map((share) => share.userId),
    set: (ids) => {
        // Kept by id, so somebody already in the list keeps their level when
        // another person is added - rebuilding would reset everybody to read-only.
        const existing = new Map(shares.value.map((share) => [share.userId, share]));

        shares.value = ids.map(
            (id) => existing.get(id) ?? {
                userId: id,
                name: props.people.find((person) => person.value === id)?.label ?? String(id),
                canWrite: false,
            },
        );
    },
});

/** Everybody but the reader: sharing a calendar with yourself is not a thing. */
const shareCandidates = computed(() =>
    props.people.filter((person) => person.value !== props.currentUserId),
);

watch(
    () => props.calendar,
    () => {
        if (null === props.calendar) return;

        form.value = props.calendar.id
            ? {
                name: props.calendar.name,
                description: props.calendar.description ?? "",
                colourSlot: props.calendar.colourSlot,
                timezone: props.calendar.timezone,
                visibility: props.calendar.visibility,
            }
            : blank();
    },
    { immediate: true },
);

const timezoneOptions = computed(() =>
    props.timezones.map((zone) => ({ value: zone, label: zone.replace(/_/g, " ") })),
);

const visibilityOptions = computed(() =>
    ["private", "shared"].map((value) => ({
        value,
        label: t(`backend.plannings.visibility.${value}`),
    })),
);
</script>

<template>
    <AppModal
        :show="null !== calendar"
        max-width="md"
        mobile-fullscreen
        :title="isNew ? t('backend.plannings.new_calendar') : t('backend.plannings.edit_calendar')"
        v-on:close="emit('close')"
    >
        <div v-if="calendar" class="space-y-3">
            <AppInput
                v-model="form.name"
                :label="t('backend.plannings.name')"
                :placeholder="t('backend.plannings.name_placeholder')"
                :error="errors.name"
            />

            <!-- Swatches and not a select: the thing being chosen is the colour
                 itself, so showing it beats naming it - and these are the same
                 eight tokens the grid draws with, so what the reader picks here
                 is literally what they will see. -->
            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-medium text-primary">{{ t("backend.plannings.colour") }}</span>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="slot in COLOUR_SLOTS"
                        :key="slot"
                        type="button"
                        class="w-7 h-7 rounded-lg border-2 transition-transform cursor-pointer hover:scale-110"
                        :class="form.colourSlot === slot ? 'border-primary scale-110' : 'border-transparent'"
                        :style="{ backgroundColor: `var(--chart-cat-${slot})` }"
                        :aria-label="t('backend.plannings.colour_slot', { slot })"
                        :aria-pressed="form.colourSlot === slot"
                        v-on:click="form.colourSlot = slot"
                    />
                </div>
                <p v-if="errors.colourSlot" class="text-xs text-red-500">{{ errors.colourSlot }}</p>
            </div>

            <AppSelect
                v-model="form.visibility"
                :label="t('backend.plannings.visibility.label')"
                :options="visibilityOptions"
                :hint="t('backend.plannings.visibility_hint')"
            />

            <AppSelect
                v-model="form.timezone"
                :label="t('backend.plannings.timezone')"
                :options="timezoneOptions"
                :hint="t('backend.plannings.timezone_hint')"
                :error="errors.timezone"
            />

            <!-- Sharing by name, which is the middle ground the visibility select
                 cannot express: private is nobody, shared is everybody who can
                 reach the module. -->
            <div v-if="!isNew && isOwner" class="flex flex-col gap-1.5 border-t border-line pt-3">
                <AppMultiselect
                    v-model="sharedIds"
                    multiple
                    :label="t('backend.plannings.shares.label')"
                    :placeholder="t('backend.plannings.shares.placeholder')"
                    :options="shareCandidates"
                />

                <div v-for="share in shares" :key="share.userId" class="flex items-center gap-2">
                    <span class="min-w-0 flex-1 truncate text-sm text-secondary">{{ share.name }}</span>
                    <AppToggle
                        v-model="share.canWrite"
                        :label="t('backend.plannings.shares.can_write')"
                    />
                    <button
                        type="button"
                        class="shrink-0 cursor-pointer p-1 text-muted transition-colors hover:text-primary"
                        :title="t('shared.common.delete')"
                        v-on:click="sharedIds = sharedIds.filter((id) => id !== share.userId)"
                    >
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                    </button>
                </div>

                <AppButton
                    variant="secondary"
                    size="sm"
                    class="self-start"
                    v-on:click="emit('set-shares', { calendar, shares })"
                >
                    {{ t("backend.plannings.shares.save") }}
                </AppButton>
            </div>

            <!-- The share links. Only for a calendar that exists: a link needs an
                 id, and offering one on a form that has not saved yet would be a
                 control that cannot work. -->
            <CalendarShareLinks
                v-if="!isNew"
                ref="shareLinks"
                :links="links"
                :errors="linkErrors"
                :saving="savingLink"
                v-on:create="emit('create-link', $event)"
                v-on:revoke="emit('revoke-link', $event)"
            />

            <AppTextarea
                v-model="form.description"
                :label="t('backend.plannings.description')"
                :placeholder="t('shared.placeholders.description')"
                :rows="2"
            />
        </div>

        <template #footer>
            <AppModalFooter>
                <!-- Deleting a calendar takes its events with it, so the control
                     sits here rather than on a hover icon in the sidebar: the
                     reader is already looking at what they are about to lose. -->
                <AppButton
                    v-if="!isNew"
                    variant="ghost"
                    size="md"
                    v-on:click="emit('delete', calendar)"
                >
                    <Trash2 class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.delete") }}
                </AppButton>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('save', form)">
                    {{ t("shared.common.save") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
