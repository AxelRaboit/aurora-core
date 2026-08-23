<script setup>
/**
 * One event, read or edited.
 *
 * Both in one component because the read view and the form answer the same
 * question about the same record, and the rule that separates them - an event a
 * module owns is not ours to change - has to be applied once. Two components
 * would each have to know it.
 *
 * A modal rather than the popover anchored to the clicked cell that the mockup
 * drew. The month stays visible behind it, which was the point of not opening a
 * page; anchoring to a cell inside a scrolling grid is its own piece of work and
 * not what makes the calendar usable.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { MapPin, Pencil, Trash2 } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";

const props = defineProps({
    /** The event being looked at, or null when the modal is closed. */
    event: { type: Object, default: null },
    /** True when the form is open rather than the read view. */
    editing: { type: Boolean, default: false },
    calendars: { type: Array, required: true },
    errors: { type: Object, default: () => ({}) },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "edit", "save", "delete"]);

const { t, d } = useI18n();

const form = ref(blank());

function blank() {
    return {
        planningId: props.calendars[0]?.id ?? null,
        title: "",
        description: "",
        location: "",
        startAt: "",
        endAt: "",
        allDay: false,
        status: "confirmed",
    };
}

/** `datetime-local` wants `YYYY-MM-DDTHH:mm` and nothing else. */
function forInput(iso) {
    if (!iso) return "";
    const date = new Date(iso);
    const pad = (n) => String(n).padStart(2, "0");

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

// Refilled whenever a different event opens, so the form never shows the
// previous one's values for an instant.
watch(
    () => [props.event, props.editing],
    () => {
        if (!props.editing) return;

        form.value = props.event?.id
            ? {
                planningId: props.event.planningId,
                title: props.event.title,
                description: props.event.description ?? "",
                location: props.event.location ?? "",
                startAt: forInput(props.event.startAt),
                endAt: forInput(props.event.endAt),
                allDay: props.event.allDay,
                status: props.event.status,
            }
            : { ...blank(), ...(props.event ?? {}) };
    },
    { immediate: true },
);

const calendarOptions = computed(() =>
    props.calendars.map((calendar) => ({ value: calendar.id, label: calendar.name })),
);

const statusOptions = computed(() =>
    ["tentative", "confirmed", "cancelled"].map((value) => ({
        value,
        label: t(`backend.plannings.events.status.${value}`),
    })),
);

const when = computed(() => {
    if (!props.event) return "";

    const start = new Date(props.event.startAt);
    if (props.event.allDay) {
        return `${d(start, "long")} · ${t("backend.plannings.events.all_day")}`;
    }

    return `${d(start, "long")} · ${d(start, { hour: "2-digit", minute: "2-digit" })}`;
});
</script>

<template>
    <AppModal
        :show="null !== event"
        max-width="md"
        :close-on-overlay="!editing"
        :title="editing ? (event?.id ? t('shared.common.edit') : t('backend.plannings.events.new')) : ''"
        v-on:close="emit('close')"
    >
        <!-- Read view -->
        <div v-if="event && !editing" class="space-y-3">
            <div class="flex items-start gap-2.5">
                <span
                    class="w-[3px] self-stretch rounded-sm shrink-0"
                    :style="{ backgroundColor: `var(--chart-cat-${event.colourSlot})` }"
                />
                <div class="min-w-0">
                    <p class="text-base font-semibold text-primary leading-tight">{{ event.title }}</p>
                    <p class="mt-0.5 text-sm text-secondary">{{ when }}</p>
                </div>
                <AppBadge :color="event.statusColor" class="ml-auto shrink-0">{{ event.statusLabel }}</AppBadge>
            </div>

            <p v-if="event.location" class="flex items-center gap-1.5 text-sm text-secondary">
                <MapPin class="w-3.5 h-3.5 shrink-0 text-muted" :stroke-width="2" />
                {{ event.location }}
            </p>

            <p v-if="event.description" class="text-sm text-secondary whitespace-pre-line">{{ event.description }}</p>

            <!-- An event a module pushed says where it came from and offers
                 nothing else: it reflects a date that lives elsewhere, and the
                 only useful gesture is to go to the source. -->
            <p v-if="event.readOnly" class="text-xs text-muted">
                {{ t("backend.plannings.events.from_module") }}{{ event.sourceLabel ? ` · ${event.sourceLabel}` : "" }}
            </p>
        </div>

        <!-- Form -->
        <div v-else-if="event" class="space-y-3">
            <AppInput
                v-model="form.title"
                :label="t('backend.plannings.events.title')"
                :placeholder="t('shared.placeholders.title')"
                :error="errors.title"
            />
            <AppSelect
                v-model="form.planningId"
                :label="t('backend.plannings.calendars')"
                :options="calendarOptions"
                :error="errors.planningId"
            />
            <AppToggle v-model="form.allDay" :label="t('backend.plannings.events.all_day')" />
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <AppInput
                    v-model="form.startAt"
                    type="datetime-local"
                    :label="t('backend.plannings.events.starts')"
                    :placeholder="t('backend.posts.scheduled_at_placeholder')"
                    :error="errors.startAt"
                />
                <AppInput
                    v-model="form.endAt"
                    type="datetime-local"
                    :label="t('backend.plannings.events.ends')"
                    :placeholder="t('backend.posts.scheduled_at_placeholder')"
                    :error="errors.endAt"
                />
            </div>
            <AppInput
                v-model="form.location"
                :label="t('backend.plannings.events.location')"
                :placeholder="t('backend.plannings.events.location_placeholder')"
            />
            <AppSelect v-model="form.status" :label="t('shared.common.status')" :options="statusOptions" />
            <AppTextarea
                v-model="form.description"
                :label="t('backend.plannings.description')"
                :placeholder="t('shared.placeholders.description')"
                :rows="3"
            />
        </div>

        <template #footer>
            <AppModalFooter>
                <template v-if="event && !editing">
                    <AppButton v-if="!event.readOnly" variant="ghost" size="md" v-on:click="emit('delete', event)">
                        <Trash2 class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.delete") }}
                    </AppButton>
                    <AppButton v-if="!event.readOnly" variant="primary" size="md" v-on:click="emit('edit', event)">
                        <Pencil class="w-4 h-4" :stroke-width="2" /> {{ t("shared.common.edit") }}
                    </AppButton>
                    <AppButton v-else variant="primary" size="md" v-on:click="emit('close')">
                        {{ t("shared.common.close") }}
                    </AppButton>
                </template>
                <template v-else>
                    <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="primary" size="md" :loading="saving" v-on:click="emit('save', form)">
                        {{ t("shared.common.save") }}
                    </AppButton>
                </template>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
