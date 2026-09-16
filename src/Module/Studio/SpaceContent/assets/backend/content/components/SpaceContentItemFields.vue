<script setup>
/**
 * The form of one piece of content.
 *
 * Four fields and a notice. The notice is the one thing that is not obvious:
 * the hour typed here is read in the space's timezone, not the reader's, so a
 * person on holiday abroad does not move a client's Tuesday morning by opening
 * the page.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppDatePicker from "@/shared/components/form/picker/AppDatePicker.vue";
import SpaceContentThread from "../../../shared/SpaceContentThread.vue";
import SpaceContentAttachments from "../../../shared/SpaceContentAttachments.vue";

const props = defineProps({
    modelValue: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    columnOptions: { type: Array, default: () => [] },
    timezone: { type: String, default: "Europe/Paris" },
    approval: { type: String, default: "pending" },
    approvalBy: { type: String, default: "" },
    comments: { type: Array, default: () => [] },
    commentLoading: { type: Boolean, default: false },
    /** False while creating: a card with no id has nothing to hang a thread on. */
    canDiscuss: { type: Boolean, default: false },
    attachments: { type: Array, default: () => [] },
    attachmentLoading: { type: Boolean, default: false },
    /**
     * A reader who may see the space but not edit it.
     *
     * The fields go flat rather than disappearing: what a card says is what
     * such a reader came for, and a panel that hid the text to signal it was
     * uneditable would have answered the wrong question. What does disappear is
     * every way to write - the save button, the drop zone, the delete on a
     * message - because offering one the server would refuse is how a screen
     * teaches somebody to distrust it.
     */
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits([
    "update:modelValue",
    "post-comment",
    "delete-comment",
    "upload-attachment",
    "pick-attachment",
    "remove-attachment",
]);

const { t } = useI18n();

const form = computed(() => props.modelValue);

function set(field, value) {
    emit("update:modelValue", { ...props.modelValue, [field]: value });
}
</script>

<template>
    <div class="space-y-4">
        <AppInput
            :model-value="form.title"
            :label="t('backend.studio.space_content.title')"
            :placeholder="t('backend.studio.space_content.title_placeholder')"
            :error="errors.title"
            :disabled="readonly"
            required
            v-on:update:model-value="set('title', $event)"
        />

        <AppTextarea
            :model-value="form.body"
            :label="t('backend.studio.space_content.body')"
            :placeholder="t('backend.studio.space_content.body_placeholder')"
            :error="errors.body"
            :rows="6"
            :disabled="readonly"
            v-on:update:model-value="set('body', $event)"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <AppSelect
                :model-value="String(form.columnId ?? '')"
                :label="t('backend.studio.space_content.column')"
                :options="columnOptions"
                :error="errors.columnId"
                :disabled="readonly"
                required
                v-on:update:model-value="set('columnId', $event)"
            />

            <!-- The application's own picker, not a native `datetime-local`:
                 the browser's control takes its format from the machine's
                 locale rather than the reader's, so a French back-office showed
                 an American date on an English system. This one reads the app's
                 locale, accepts several typed formats, and emits exactly the
                 `Y-m-d\TH:i` wall clock the field carries. -->
            <AppDatePicker
                :model-value="form.scheduledAt"
                enable-time
                :label="t('backend.studio.space_content.scheduled_at')"
                :placeholder="t('backend.studio.space_content.scheduled_at_placeholder')"
                :hint="t('backend.studio.space_content.scheduled_at_hint')"
                :error="errors.scheduledAt"
                :disabled="readonly"
                v-on:update:model-value="set('scheduledAt', $event)"
            />
        </div>

        <p class="text-xs text-muted">
            {{ t("backend.studio.space_content.timezone_notice", { timezone }) }}
        </p>

        <!-- The client's own words, shown and not editable. The warning under
             it is the part nobody would guess: an approval is of a wording, so
             changing the text here drops it. -->
        <section
            v-if="approval && approval !== 'pending'"
            class="space-y-1 rounded-lg border border-line/60 bg-surface-2/40 px-3 py-2"
        >
            <p class="text-xs font-medium text-primary">
                {{ t(`backend.studio.space_content.approvals.${approval}`) }}
                <span v-if="approvalBy" class="font-normal text-muted">
                    · {{ approvalBy }}
                </span>
            </p>
            <p class="text-xs text-muted">
                {{ t("backend.studio.space_content.approval_reset_warning") }}
            </p>
        </section>

        <!-- The files, above the conversation on purpose: the visual is what a
             client is usually being asked to approve, and reading the thread
             without it in view is answering half a question. Like the thread,
             it needs a saved card to hang off. -->
        <SpaceContentAttachments
            v-if="canDiscuss"
            :attachments="attachments"
            :loading="attachmentLoading"
            :can-add="!readonly"
            :can-remove="!readonly"
            :can-pick="!readonly"
            v-on:upload="emit('upload-attachment', $event)"
            v-on:pick="emit('pick-attachment')"
            v-on:remove="emit('remove-attachment', $event)"
        />

        <!-- The conversation, which the verdict above does not carry: resetting
             an approval must not take the client's words with it, and it is
             those words the studio is acting on. -->
        <SpaceContentThread
            v-if="canDiscuss"
            :comments="comments"
            :loading="commentLoading"
            :can-post="!readonly"
            :can-delete="!readonly"
            :notice="t('backend.studio.space_content.thread_notice')"
            v-on:post="emit('post-comment', $event)"
            v-on:delete="emit('delete-comment', $event)"
        />
    </div>
</template>
