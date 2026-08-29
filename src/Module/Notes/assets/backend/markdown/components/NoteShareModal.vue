<script setup>
import { ref, computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Share2, Link2, Ban, Copy } from "lucide-vue-next";

import AppModal from "@shared/components/overlay/AppModal.vue";
import AppModalFooter from "@shared/components/overlay/AppModalFooter.vue";
import AppButton from "@shared/components/action/AppButton.vue";
import AppIconButton from "@shared/components/action/AppIconButton.vue";
import AppInput from "@shared/components/form/input/AppInput.vue";
import AppCheckbox from "@shared/components/form/toggle/AppCheckbox.vue";
import AppFieldLabel from "@shared/components/form/AppFieldLabel.vue";
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
const descendantCount = ref(0);
const submitting = ref(false);

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
        const payload = await api.list(props.noteId);
        if (!payload) {
            toast.error(t("notes.markdown.share.errors.list_failed"));
            return;
        }
        links.value = payload.links ?? [];
        descendantCount.value = payload.descendantCount ?? 0;
    },
);

function resetForm() {
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
            recipientEmail: recipientEmail.value.trim(),
            label: label.value.trim(),
            expiresAt: expiresAt.value,
        });
        if (!payload) {
            toast.error(t("notes.markdown.share.errors.create_failed"));
            return;
        }
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
    if (!payload) {
        toast.error(t("notes.markdown.share.errors.revoke_failed"));
        return;
    }
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
                <div>
                    <AppFieldLabel :label="t('notes.markdown.share.recipient_label')" />
                    <AppInput
                        v-model="recipientEmail"
                        type="email"
                        :placeholder="t('notes.markdown.share.recipient_placeholder')"
                        :disabled="submitting"
                    />
                    <p class="mt-1 text-xs text-muted">
                        {{ t("notes.markdown.share.recipient_help") }}
                    </p>
                </div>

                <div>
                    <AppFieldLabel :label="t('notes.markdown.share.label_field')" />
                    <AppInput
                        v-model="label"
                        :placeholder="t('notes.markdown.share.label_placeholder')"
                        :disabled="submitting"
                    />
                </div>

                <div>
                    <AppFieldLabel :label="t('notes.markdown.share.expires_label')" />
                    <AppInput
                        v-model="expiresAt"
                        type="date"
                        :placeholder="t('notes.markdown.share.expires_placeholder')"
                        :disabled="submitting"
                    />
                </div>

                <!-- The count is the point of this line: publishing one note and
                     publishing a branch of thirty are different acts, and the
                     number says which one this click is. -->
                <label class="flex items-start gap-2">
                    <AppCheckbox
                        v-model="includeDescendants"
                        :disabled="submitting || descendantCount === 0"
                    />
                    <span class="text-sm">
                        <span class="text-primary">{{ t("notes.markdown.share.include_descendants") }}</span>
                        <span class="block text-xs text-muted">
                            {{
                                descendantCount === 0
                                    ? t("notes.markdown.share.include_descendants_none")
                                    : t("notes.markdown.share.include_descendants_count", descendantCount)
                            }}
                        </span>
                    </span>
                </label>
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
