<script setup>
import { ref, computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Share2, Link2, Ban, Copy } from "lucide-vue-next";

import AppModal from "@shared/components/overlay/AppModal.vue";
import AppModalFooter from "@shared/components/overlay/AppModalFooter.vue";
import AppButton from "@shared/components/action/AppButton.vue";
import AppIconButton from "@shared/components/action/AppIconButton.vue";
import AppInput from "@shared/components/form/input/AppInput.vue";
import AppDatePicker from "@shared/components/form/picker/AppDatePicker.vue";
import AppCheckbox from "@shared/components/form/toggle/AppCheckbox.vue";
import { toast } from "vue-sonner";
import { useClipboard } from "@shared/composables/useClipboard.js";
import { useNoteShareApi } from "@notes/backend/markdown/composables/useNoteShareApi.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    noteId: { type: Number, default: null },
    paths: { type: Object, required: true },
});

const emit = defineEmits(["close"]);

const { t } = useI18n();
const { copy: copyToClipboard } = useClipboard();
const api = useNoteShareApi(props.paths);

const links = ref([]);
const includeLinked = ref(false);
// What the two switches would publish, titles and all. Refreshed whenever they
// move: a count could be computed once, but a list has to match the boxes as
// they stand or it is worse than nothing.
const previewNotes = ref([]);
const submitting = ref(false);
// Field errors from a 422. `useRequest` returns the body for those rather than
// null, so they have to be read - treating a 422 as success was silently
// dropping "this address is not an address" on the floor.
const errors = ref({});

const recipientEmail = ref("");
const label = ref("");
const includeDescendants = ref(false);
const expiresAt = ref("");

const active = computed(() => links.value.filter((l) => !l.revokedAt));
const revoked = computed(() => links.value.filter((l) => l.revokedAt));

// Reloaded on every open rather than cached: a link may have been revoked from
// another tab, and a revoked link still shown as live is the one mistake this
// screen must not make.
watch(
    () => props.show,
    async (open) => {
        if (!open || !props.noteId) return;
        resetForm();
        // `useRequest` has already toasted on transport failure; a second one
        // here stacked two messages over each other.
        const payload = await api.list(props.noteId);
        if (!payload) return;
        links.value = payload.links ?? [];
        await refreshPreview();
    },
);

async function refreshPreview() {
    if (!props.noteId) return;
    const payload = await api.preview(props.noteId, {
        descendants: includeDescendants.value,
        linked: includeLinked.value,
    });
    if (payload) previewNotes.value = payload.notes ?? [];
}

watch([includeDescendants, includeLinked], refreshPreview);

function resetForm() {
    errors.value = {};
    includeLinked.value = false;
    previewNotes.value = [];
    recipientEmail.value = "";
    label.value = "";
    includeDescendants.value = false;
    expiresAt.value = "";
}

async function create() {
    submitting.value = true;
    try {
        const payload = await api.create({
            noteId: props.noteId,
            includeDescendants: includeDescendants.value,
            includeLinked: includeLinked.value,
            recipientEmail: recipientEmail.value.trim(),
            label: label.value.trim(),
            expiresAt: expiresAt.value,
        });
        if (!payload) return;

        // A 422 comes back as a body, not as null: the fields say what is wrong,
        // and reading them is the difference between "the address is malformed"
        // and a link that silently never appears.
        if (payload.errors) {
            errors.value = payload.errors;
            return;
        }

        errors.value = {};
        links.value = [payload.link, ...links.value];
        const email = payload.link.recipientEmail;
        toast.success(
            email
                ? t("notes.markdown.share.sent", { email })
                : t("notes.markdown.share.created"),
        );
        resetForm();
    } finally {
        submitting.value = false;
    }
}

async function revoke(id) {
    const payload = await api.revoke(id);
    if (!payload) return;
    const index = links.value.findIndex((l) => l.id === id);
    if (index !== -1) links.value[index] = payload.link;
    toast.success(t("notes.markdown.share.revoked"));
}

function copy(url) {
    return copyToClipboard(url, "notes.markdown.share.copied");
}

function openedLabel(link) {
    return link.lastUsedAt
        ? t("notes.markdown.share.last_opened", {
            date: new Date(link.lastUsedAt).toLocaleDateString(),
        })
        : t("notes.markdown.share.never_opened");
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="lg"
        :closeable="!submitting"
        :title="t('notes.markdown.share.title')"
        :icon="Share2"
        v-on:close="emit('close')"
    >
        <div class="space-y-4">
            <div class="space-y-3">
                <AppInput
                    v-model="recipientEmail"
                    type="email"
                    :label="t('notes.markdown.share.recipient_label')"
                    :placeholder="t('notes.markdown.share.recipient_placeholder')"
                    :hint="t('notes.markdown.share.recipient_help')"
                    :error="errors.recipientEmail"
                    :disabled="submitting"
                />

                <AppInput
                    v-model="label"
                    :label="t('notes.markdown.share.label_field')"
                    :placeholder="t('notes.markdown.share.label_placeholder')"
                    :error="errors.label"
                    :disabled="submitting"
                />

                <AppDatePicker
                    v-model="expiresAt"
                    :label="t('notes.markdown.share.expires_label')"
                    :placeholder="t('notes.markdown.share.expires_placeholder')"
                    :hint="t('notes.markdown.share.expires_hint')"
                    :error="errors.expiresAt"
                />

                <!-- `AppCheckbox` brings its own <label>; wrapping it in
                     another one nested two labels over the same input, so every
                     click toggled it twice and the box could not be unticked. -->
                <AppCheckbox
                    v-model="includeDescendants"
                    :label="t('notes.markdown.share.include_descendants')"
                    :hint="t('notes.markdown.share.include_descendants_hint')"
                    :disabled="submitting"
                />

                <AppCheckbox
                    v-model="includeLinked"
                    :label="t('notes.markdown.share.include_linked')"
                    :hint="t('notes.markdown.share.include_linked_hint')"
                    :disabled="submitting"
                />

                <!-- The list, not a count. "4 notes" cannot be checked against
                     what somebody meant to share; seeing a title they did not
                     expect is what stops the click. -->
                <div
                    v-if="previewNotes.length > 0"
                    class="rounded-md border border-line bg-surface-2 p-2"
                >
                    <p class="mb-1 text-xs font-medium text-secondary">
                        {{ t("notes.markdown.share.also_shared", previewNotes.length) }}
                    </p>
                    <ul class="max-h-32 space-y-0.5 overflow-auto">
                        <li
                            v-for="n in previewNotes"
                            :key="n.id"
                            class="truncate text-xs text-muted"
                        >
                            {{ n.title?.trim() || t("notes.markdown.untitled") }}
                        </li>
                    </ul>
                </div>
                <p
                    v-else-if="includeDescendants || includeLinked"
                    class="text-xs text-muted"
                >
                    {{ t("notes.markdown.share.nothing_else") }}
                </p>
            </div>

            <div v-if="links.length > 0" class="space-y-2">
                <p class="text-xs font-medium uppercase tracking-wide text-muted">
                    {{ t("notes.markdown.share.existing") }}
                </p>
                <div class="divide-y divide-line rounded-md border border-line">
                    <div
                        v-for="link in [...active, ...revoked]"
                        :key="link.id"
                        class="flex items-center gap-2 px-3 py-2"
                        :class="link.revokedAt ? 'opacity-60' : ''"
                    >
                        <Link2 class="w-3.5 h-3.5 shrink-0 text-muted" :stroke-width="2" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-primary">
                                {{ link.recipientEmail || link.label || link.url }}
                            </p>
                            <p class="truncate text-xs text-muted">
                                {{
                                    link.revokedAt
                                        ? t("notes.markdown.share.revoked_on", {
                                            date: new Date(link.revokedAt).toLocaleDateString(),
                                        })
                                        : openedLabel(link)
                                }}
                            </p>
                        </div>
                        <template v-if="!link.revokedAt">
                            <AppIconButton
                                :title="t('notes.markdown.share.copy')"
                                v-on:click="copy(link.url)"
                            >
                                <Copy class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                color="danger"
                                :title="t('notes.markdown.share.revoke')"
                                v-on:click="revoke(link.id)"
                            >
                                <Ban class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                        </template>
                    </div>
                </div>
            </div>
            <p v-else class="text-sm text-muted">{{ t("notes.markdown.share.none_yet") }}</p>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" :disabled="submitting" v-on:click="emit('close')">
                    {{ t("notes.markdown.cancel") }}
                </AppButton>
                <AppButton :disabled="submitting" v-on:click="create">
                    {{ t("notes.markdown.share.create") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
