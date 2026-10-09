<script setup>
import { ref, computed, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Share2, Link2, Ban, Copy, UserPlus, X } from "lucide-vue-next";

import AppModal from "@shared/components/overlay/AppModal.vue";
import AppModalFooter from "@shared/components/overlay/AppModalFooter.vue";
import AppButton from "@shared/components/action/AppButton.vue";
import AppIconButton from "@shared/components/action/AppIconButton.vue";
import AppInput from "@shared/components/form/input/AppInput.vue";
import AppDatePicker from "@shared/components/form/picker/AppDatePicker.vue";
import AppCheckbox from "@shared/components/form/toggle/AppCheckbox.vue";
import AppBetaBadge from "@shared/components/display/AppBetaBadge.vue";
import AppSelect from "@shared/components/form/select/AppSelect.vue";
import { toast } from "vue-sonner";
import { useClipboard } from "@shared/composables/useClipboard.js";
import { useNoteShareApi } from "@notes/suite/markdown/composables/useNoteShareApi.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";

const { formatDateShort } = useDateFormat();

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

/**
 * The two halves of one question - who else reaches this note.
 *
 * People first on screen, because it is the one that keeps the note inside
 * Aurora: naming somebody hands them the note under their own account, where
 * a link hands it to whoever ends up holding the address.
 */
const members = ref([]);
const people = ref([]);
const newPersonId = ref(null);
const newPersonRole = ref("reader");

const includeLinked = ref(false);
const canWrite = ref(false);
// Live co-editing through the link: off by default, and only ever offered on a
// link that writes - unticking writing takes it along.
const coediting = ref(false);
watch(canWrite, (writes) => {
    if (!writes) coediting.value = false;
});
// The sentence under "Par un lien" says what the link will let its holder do,
// boxes as they stand: it said "En lecture seule" while writing was ticked.
const byLinkHint = computed(() => {
    if (!canWrite.value) return t("notes.markdown.share.by_link_hint");

    return coediting.value
        ? t("notes.markdown.share.by_link_hint_live")
        : t("notes.markdown.share.by_link_hint_write");
});
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
const expiresAt = ref("");

const active = computed(() => links.value.filter((link) => !link.revokedAt));
const revoked = computed(() => links.value.filter((link) => link.revokedAt));

const roleOptions = computed(() => [
    { value: "reader", label: t("notes.markdown.people.role.reader") },
    { value: "editor", label: t("notes.markdown.people.role.editor") },
]);

/** Those who can still be added: never the same person twice. */
const candidates = computed(() => {
    const already = new Set(members.value.map((member) => Number(member.userId)));

    return people.value
        .filter((person) => !already.has(Number(person.id)))
        .map((person) => ({ value: person.id, label: person.name }));
});

async function addPerson() {
    if (null === newPersonId.value) return;

    const payload = await api.setPerson(props.noteId, newPersonId.value, newPersonRole.value);
    if (!payload) return;

    if (payload.errors) {
        errors.value = payload.errors;

        return;
    }

    const name = payload.member.name;
    members.value = [...members.value, payload.member];
    newPersonId.value = null;
    toast.success(t("notes.markdown.people.added", { name }));
}

async function changeRole(member, role) {
    const payload = await api.setPerson(props.noteId, member.userId, role);
    if (!payload || payload.errors) return;

    members.value = members.value.map((one) =>
        one.userId === member.userId ? payload.member : one,
    );
    toast.success(t("notes.markdown.people.role_changed"));
}

async function removePerson(member) {
    const payload = await api.removePerson(props.noteId, member.userId);
    if (!payload) return;

    members.value = members.value.filter((one) => one.userId !== member.userId);
    toast.success(t("notes.markdown.people.removed", { name: member.name }));
}

// Reloaded on every open rather than cached: a link may have been revoked from
// another tab, and a revoked link still shown as live is the one mistake this
// screen must not make.
watch(
    () => props.show,
    async (open) => {
        if (!open || !props.noteId) return;
        resetForm();
        // Both halves in one go: they answer the same question, and two
        // waterfalls would draw the dialog twice. `useRequest` has already
        // toasted on transport failure; a second one here stacked two
        // messages over each other.
        const [linkPayload, peoplePayload] = await Promise.all([
            api.list(props.noteId),
            api.listPeople(props.noteId),
        ]);

        if (peoplePayload) {
            members.value = peoplePayload.members ?? [];
            people.value = peoplePayload.people ?? [];
        }

        if (!linkPayload) return;
        links.value = linkPayload.links ?? [];
        await refreshPreview();
    },
);

async function refreshPreview() {
    if (!props.noteId) return;
    const payload = await api.preview(props.noteId, {
        linked: includeLinked.value,
    });
    if (payload) previewNotes.value = payload.notes ?? [];
}

watch(includeLinked, refreshPreview);

function resetForm() {
    errors.value = {};
    newPersonId.value = null;
    newPersonRole.value = "reader";
    includeLinked.value = false;
    canWrite.value = false;
    coediting.value = false;
    previewNotes.value = [];
    recipientEmail.value = "";
    label.value = "";
    expiresAt.value = "";
}

async function create() {
    submitting.value = true;
    try {
        const payload = await api.create({
            noteId: props.noteId,
            includeLinked: includeLinked.value,
            canWrite: canWrite.value,
            coediting: canWrite.value && coediting.value,
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
    const index = links.value.findIndex((link) => link.id === id);
    if (index !== -1) links.value[index] = payload.link;
    toast.success(t("notes.markdown.share.revoked"));
}

function copy(url) {
    return copyToClipboard(url, "notes.markdown.share.copied");
}

function openedLabel(link) {
    return link.lastUsedAt
        ? t("notes.markdown.share.last_opened", {
            date: formatDateShort(link.lastUsedAt),
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
            <!-- The people, first: naming somebody keeps the note inside
                 Aurora, under their own account. -->
            <section data-note-people>
                <h3 class="text-sm font-semibold text-primary">{{ t("notes.markdown.people.title") }}</h3>
                <p class="mt-1 text-xs text-muted">{{ t("notes.markdown.people.hint") }}</p>

                <p v-if="!members.length" class="mt-3 text-sm text-muted">
                    {{ t("notes.markdown.people.none_yet") }}
                </p>

                <ul v-else class="mt-3 space-y-2">
                    <li
                        v-for="member in members"
                        :key="member.userId"
                        :data-note-member="member.userId"
                        class="flex items-center gap-2"
                    >
                        <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ member.name }}</span>
                        <AppSelect
                            class="w-32"
                            :model-value="member.role"
                            :options="roleOptions"
                            v-on:update:model-value="changeRole(member, $event)"
                        />
                        <AppIconButton
                            :title="t('notes.markdown.people.remove', { name: member.name })"
                            :aria-label="t('notes.markdown.people.remove', { name: member.name })"
                            v-on:click="removePerson(member)"
                        >
                            <X class="h-4 w-4" :stroke-width="2" />
                        </AppIconButton>
                    </li>
                </ul>

                <div v-if="candidates.length" class="mt-3 flex flex-wrap items-end gap-2">
                    <AppSelect
                        class="min-w-0 flex-1"
                        data-note-new-member
                        :placeholder="t('notes.markdown.people.pick_person')"
                        :model-value="newPersonId"
                        :options="candidates"
                        :error="errors.userId"
                        v-on:update:model-value="newPersonId = Number($event)"
                    />
                    <AppSelect
                        class="w-32"
                        :model-value="newPersonRole"
                        :options="roleOptions"
                        v-on:update:model-value="newPersonRole = $event"
                    />
                    <AppButton
                        variant="secondary"
                        size="md"
                        data-note-add-member
                        :disabled="null === newPersonId"
                        v-on:click="addPerson"
                    >
                        <UserPlus class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("notes.markdown.people.add") }}
                    </AppButton>
                </div>
            </section>

            <div class="space-y-3 border-t border-line pt-4">
                <div>
                    <h3 class="text-sm font-semibold text-primary">{{ t("notes.markdown.share.by_link") }}</h3>
                    <p data-share-by-link-hint class="mt-1 text-xs text-muted">{{ byLinkHint }}</p>
                </div>

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

                <!-- A single switch since folders exist: sub-notes no longer
                     exist, and a folder is not shared.

                     `AppCheckbox` brings its own <label>; wrapping it in a
                     second one stacked two labels on the same field, so that
                     a click ticked it twice. -->
                <AppCheckbox
                    v-model="includeLinked"
                    :label="t('notes.markdown.share.include_linked')"
                    :hint="t('notes.markdown.share.include_linked_hint')"
                    :disabled="submitting"
                />

                <!-- The switch that turns an address into a write endpoint.
                     Its own note only, whatever the one above says - the hint
                     spells that out, because a reader who ticks both would
                     otherwise expect the whole share to be writable. -->
                <AppCheckbox
                    v-model="canWrite"
                    data-share-can-write
                    :label="t('notes.markdown.share.can_write')"
                    :hint="t('notes.markdown.share.can_write_hint')"
                    :disabled="submitting"
                />

                <!-- Writing together, letter by letter, the way a shared
                     document does. Under the writing switch and only with it:
                     a link that does not write has no room to enter. -->
                <AppCheckbox
                    v-if="canWrite"
                    v-model="coediting"
                    data-share-coediting
                    class="ml-6"
                    :hint="t('notes.markdown.share.coediting_hint')"
                    :disabled="submitting"
                >
                    {{ t('notes.markdown.share.coediting') }}<AppBetaBadge />
                </AppCheckbox>

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
                            v-for="previewNote in previewNotes"
                            :key="previewNote.id"
                            class="truncate text-xs text-muted"
                        >
                            {{ previewNote.title?.trim() || t("notes.markdown.untitled") }}
                        </li>
                    </ul>
                </div>
                <p
                    v-else-if="includeLinked"
                    class="text-xs text-muted"
                >
                    {{ t("notes.markdown.share.nothing_else") }}
                </p>
            </div>

            <div v-if="links.length > 0" class="space-y-2">
                <p class="text-xs font-medium uppercase tracking-wide text-muted">
                    {{ t("notes.markdown.share.existing") }}
                </p>
                <div class="divide-y divide-line/40 rounded-md border border-line">
                    <div
                        v-for="link in [...active, ...revoked]"
                        :key="link.id"
                        class="flex items-center gap-2 px-3 py-2"
                        :class="link.revokedAt ? 'opacity-60' : ''"
                    >
                        <Link2 class="w-3.5 h-3.5 shrink-0 text-muted" :stroke-width="2" />
                        <div class="min-w-0 flex-1">
                            <p class="flex items-center gap-1.5 truncate text-sm text-primary">
                                <span class="truncate">{{ link.recipientEmail || link.label || link.url }}</span>
                                <!-- A writing link has to be recognisable in
                                     the list, or nobody notices it is still
                                     out there. -->
                                <span
                                    v-if="link.canWrite"
                                    data-share-writable
                                    class="shrink-0 rounded-full bg-surface-2 px-1.5 py-0.5 text-[10px] font-medium text-muted"
                                >{{ t("notes.markdown.share.writable_badge") }}</span>
                                <span
                                    v-if="link.coediting"
                                    data-share-coediting-badge
                                    class="shrink-0 rounded-full bg-surface-2 px-1.5 py-0.5 text-[10px] font-medium text-muted"
                                >{{ t("notes.markdown.share.coediting_badge") }}</span>
                            </p>
                            <p class="truncate text-xs text-muted">
                                {{
                                    link.revokedAt
                                        ? t("notes.markdown.share.revoked_on", {
                                            date: formatDateShort(link.revokedAt),
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
