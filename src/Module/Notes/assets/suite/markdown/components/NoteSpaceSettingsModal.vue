<script setup>
/**
 * A space's settings: its name, who gets in, and who is a member.
 *
 * **Memberships apply right away**, the rest on save. Adding someone is a
 * gesture in itself, often done several times in a row; tying it to the
 * bottom button would make whoever closes the dialog without thinking lose a
 * whole list.
 *
 * The personal space only has a colour to set: it is not renamed, opened to
 * anyone or removed. The dialog says so rather than showing settings that
 * would do nothing.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, Globe, Layers, Trash2, UserPlus, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppColorPicker from "@/shared/components/form/picker/AppColorPicker.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppCheckbox from "@shared/components/form/toggle/AppCheckbox.vue";
import AppBetaBadge from "@shared/components/display/AppBetaBadge.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { useClipboard } from "@/shared/composables/useClipboard.js";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { spaceLabel } from "../composables/noteSpaces.js";

const props = defineProps({
    /** The open space; `null` closes the dialog. */
    spaceId: { type: Number, default: null },
    /** {@see useNoteSpacesApi} */
    api: { type: Object, required: true },
});

const emit = defineEmits(["close", "changed"]);

const { t } = useI18n();

const space = ref(null);
const members = ref([]);
const people = ref([]);
const loading = ref(false);
const saving = ref(false);
const confirmingDelete = ref(false);

const name = ref("");
const color = ref(null);
const access = ref("private");
const defaultRole = ref("reader");

/**
 * Whether the notes of this space may be written by several people at once.
 *
 * Not offered on a personal space: a private notebook has nobody to
 * co-edit with, and it does not change the promise it made about who can
 * read it.
 */
const coediting = ref(false);

const canPublish = ref(false);
const slug = ref("");
const indexable = ref(false);
const publishing = ref(false);
const { copy } = useClipboard();

const newMemberId = ref(null);
const newMemberRole = ref("reader");

const show = computed(() => null !== props.spaceId);
const isPersonal = computed(() => Boolean(space.value?.personal));
const takesMembers = computed(() => !isPersonal.value && "private" !== access.value);

watch(
    () => props.spaceId,
    async (id) => {
        space.value = null;
        confirmingDelete.value = false;
        newMemberId.value = null;
        newMemberRole.value = "reader";

        if (null === id) return;

        loading.value = true;
        const [shown, listed] = await Promise.all([props.api.show(id), props.api.people()]);
        loading.value = false;

        if (!shown.ok) {
            if (!shown.reported) toast.error(t("notes.markdown.spaces.errors.load_failed"));
            emit("close");

            return;
        }

        space.value = shown.payload.space;
        members.value = shown.payload.members ?? [];
        canPublish.value = Boolean(shown.payload.canPublish);
        slug.value = space.value.slug ?? "";
        indexable.value = Boolean(space.value.indexable);
        people.value = listed.ok ? listed.payload.people ?? [] : [];

        name.value = space.value.name ?? "";
        color.value = space.value.color ?? null;
        access.value = space.value.access;
        defaultRole.value = space.value.defaultRole;
        coediting.value = Boolean(space.value.coediting);
    },
    { immediate: true },
);

const accessOptions = computed(() => [
    { value: "private", label: t("notes.markdown.spaces.access.private") },
    { value: "members", label: t("notes.markdown.spaces.access.members") },
    { value: "backoffice", label: t("notes.markdown.spaces.access.backoffice") },
]);

const defaultRoleOptions = computed(() => [
    { value: "reader", label: t("notes.markdown.spaces.role.reader") },
    { value: "editor", label: t("notes.markdown.spaces.role.editor") },
]);

const memberRoleOptions = computed(() => [
    { value: "reader", label: t("notes.markdown.spaces.role.reader") },
    { value: "editor", label: t("notes.markdown.spaces.role.editor") },
    { value: "manager", label: t("notes.markdown.spaces.role.manager") },
]);

/** Those who can still be added: not the same person twice. */
const candidates = computed(() => {
    const already = new Set(members.value.map((member) => Number(member.userId)));

    return people.value
        .filter((person) => !already.has(Number(person.id)))
        .map((person) => ({ value: person.id, label: person.name }));
});

const accessHint = computed(() => t(`notes.markdown.spaces.access.${access.value}_hint`));

async function addMember() {
    if (null === newMemberId.value) return;

    const { ok, reported, payload } = await props.api.setMember(props.spaceId, newMemberId.value, newMemberRole.value);

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.spaces.errors.save_failed"));

        return;
    }

    members.value = [...members.value, payload.member];
    newMemberId.value = null;
    emit("changed");
}

async function changeRole(member, role) {
    const { ok, reported } = await props.api.setMember(props.spaceId, member.userId, role);

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.spaces.errors.save_failed"));

        return;
    }

    members.value = members.value.map((one) => (one.userId === member.userId ? { ...one, role } : one));
    emit("changed");
}

async function removeMember(member) {
    const { ok, reported } = await props.api.removeMember(props.spaceId, member.userId);

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.spaces.errors.save_failed"));

        return;
    }

    members.value = members.value.filter((one) => one.userId !== member.userId);
    emit("changed");
}

/**
 * Publishing applies right away, like a membership: opening a space to the
 * web is a gesture in itself, and the address it gets must show before the
 * dialog is closed.
 */
async function applyPublication(published) {
    publishing.value = true;
    const { ok, reported, payload } = await props.api.publish(props.spaceId, {
        published,
        slug: slug.value.trim(),
        indexable: indexable.value,
    });
    publishing.value = false;

    if (!ok) {
        const error = payload?.errors?.slug;
        if (!reported) toast.error(t(error ?? "notes.markdown.spaces.errors.save_failed"));

        return;
    }

    space.value = payload.space;
    slug.value = payload.space.slug ?? slug.value;
    toast.success(t(published ? "notes.markdown.spaces.publication.published" : "notes.markdown.spaces.publication.unpublished"));
    emit("changed");
}

const canSave = computed(() => !saving.value && (isPersonal.value || "" !== name.value.trim()));

async function save() {
    if (!canSave.value) return;

    saving.value = true;
    const { ok, reported } = await props.api.update(props.spaceId, {
        name: name.value.trim(),
        color: color.value,
        access: access.value,
        defaultRole: defaultRole.value,
        coediting: coediting.value,
    });
    saving.value = false;

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.spaces.errors.save_failed"));

        return;
    }

    toast.success(t("notes.markdown.spaces.updated"));
    emit("changed");
    emit("close");
}

/** Two steps: the button asks, the second click removes. */
async function removeSpace() {
    if (!confirmingDelete.value) {
        confirmingDelete.value = true;

        return;
    }

    saving.value = true;
    const { ok, reported } = await props.api.remove(props.spaceId);
    saving.value = false;

    if (!ok) {
        if (!reported) toast.error(t("notes.markdown.spaces.errors.save_failed"));

        return;
    }

    toast.success(t("notes.markdown.spaces.deleted"));
    emit("changed");
    emit("close");
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="md"
        :closeable="!saving"
        :title="t('notes.markdown.spaces.settings')"
        :icon="Layers"
        v-on:close="emit('close')"
    >
        <p v-if="loading || !space" class="text-sm text-muted">{{ t('notes.markdown.spaces.loading') }}</p>

        <template v-else>
            <p v-if="isPersonal" class="text-sm text-secondary">
                {{ t('notes.markdown.spaces.personal_hint') }}
            </p>

            <template v-else>
                <AppInput
                    v-model="name"
                    data-space-name
                    class="w-full"
                    :label="t('notes.markdown.spaces.name')"
                    :placeholder="t('notes.markdown.spaces.name_placeholder')"
                />

                <p v-if="!space.isOwner && space.ownerName" class="mt-1 text-xs text-muted">
                    {{ t('notes.markdown.spaces.owner', { name: space.ownerName }) }}
                </p>

                <AppChoiceRow
                    v-model="access"
                    data-space-access
                    class="mt-4"
                    :label="t('notes.markdown.spaces.access.label')"
                    :options="accessOptions"
                />
                <p class="mt-1 text-xs text-muted">{{ accessHint }}</p>

                <AppChoiceRow
                    v-if="'backoffice' === access"
                    v-model="defaultRole"
                    class="mt-4"
                    :label="t('notes.markdown.spaces.role.label')"
                    :options="defaultRoleOptions"
                />
            </template>

            <AppColorPicker v-model="color" class="mt-4" :label="t('notes.markdown.folders.color')" />

            <!-- What the space allows beyond who gets in. Not on a personal
                 one: there is nobody to co-edit with, and the notebook keeps
                 the promise it made. -->
            <section v-if="!isPersonal" class="mt-6 border-t border-line pt-4" data-space-coediting>
                <h3 class="text-sm font-semibold text-primary">{{ t('notes.markdown.spaces.coediting.title') }}</h3>
                <AppCheckbox
                    v-model="coediting"
                    class="mt-3"
                    data-space-coediting-toggle
                    :hint="t('notes.markdown.spaces.coediting.hint')"
                    :disabled="saving"
                >
                    {{ t('notes.markdown.spaces.coediting.enable') }}<AppBetaBadge />
                </AppCheckbox>
            </section>

            <!-- The members: each with their role, which is changed in place. -->
            <section v-if="takesMembers" class="mt-6 border-t border-line pt-4" data-space-members>
                <h3 class="text-sm font-semibold text-primary">{{ t('notes.markdown.spaces.members') }}</h3>
                <p class="mt-1 text-xs text-muted">
                    {{ 'backoffice' === access ? t('notes.markdown.spaces.members_hint_backoffice') : t('notes.markdown.spaces.members_hint') }}
                </p>

                <p v-if="!members.length" class="mt-3 text-sm text-muted">{{ t('notes.markdown.spaces.no_members') }}</p>

                <ul v-else class="mt-3 space-y-2">
                    <li
                        v-for="member in members"
                        :key="member.userId"
                        :data-space-member="member.userId"
                        class="flex items-center gap-2"
                    >
                        <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ member.name }}</span>
                        <AppSelect
                            class="w-40"
                            :model-value="member.role"
                            :options="memberRoleOptions"
                            v-on:update:model-value="changeRole(member, $event)"
                        />
                        <AppIconButton
                            :title="t('notes.markdown.spaces.remove_member', { name: member.name })"
                            :aria-label="t('notes.markdown.spaces.remove_member', { name: member.name })"
                            v-on:click="removeMember(member)"
                        >
                            <X class="h-4 w-4" :stroke-width="2" />
                        </AppIconButton>
                    </li>
                </ul>

                <div v-if="candidates.length" class="mt-3 flex flex-wrap items-end gap-2">
                    <AppSelect
                        class="min-w-0 flex-1"
                        data-space-new-member
                        :placeholder="t('notes.markdown.spaces.pick_person')"
                        :model-value="newMemberId"
                        :options="candidates"
                        v-on:update:model-value="newMemberId = Number($event)"
                    />
                    <AppSelect
                        class="w-40"
                        :model-value="newMemberRole"
                        :options="memberRoleOptions"
                        v-on:update:model-value="newMemberRole = $event"
                    />
                    <AppButton
                        variant="secondary"
                        size="md"
                        data-space-add-member
                        :disabled="null === newMemberId"
                        v-on:click="addMember"
                    >
                        <UserPlus class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t('notes.markdown.spaces.add_member') }}
                    </AppButton>
                </div>
            </section>

            <!-- Public reading: for whoever is allowed to, never for one's
                 personal space. -->
            <section v-if="canPublish" class="mt-6 border-t border-line pt-4" data-space-publication>
                <h3 class="flex items-center gap-1.5 text-sm font-semibold text-primary">
                    <Globe class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.spaces.publication.title') }}
                </h3>
                <p class="mt-1 text-xs text-muted">{{ t('notes.markdown.spaces.publication.hint') }}</p>

                <AppInput
                    v-model="slug"
                    data-space-slug
                    class="mt-3 w-full"
                    :label="t('notes.markdown.spaces.publication.slug')"
                    :placeholder="t('notes.markdown.spaces.publication.slug_placeholder')"
                />

                <AppToggle
                    v-model="indexable"
                    class="mt-3"
                    data-space-indexable
                    :label="t('notes.markdown.spaces.publication.indexable')"
                    :hint="t('notes.markdown.spaces.publication.indexable_hint')"
                />

                <div v-if="space.published && space.publicUrl" class="mt-3 flex min-w-0 items-center gap-2" data-space-public-url>
                    <a
                        :href="space.publicUrl"
                        target="_blank"
                        rel="noopener"
                        class="min-w-0 flex-1 truncate text-sm text-accent-400"
                    >{{ space.publicUrl }}</a>
                    <AppIconButton
                        :title="t('notes.markdown.spaces.publication.copy')"
                        :aria-label="t('notes.markdown.spaces.publication.copy')"
                        v-on:click="copy(space.publicUrl, 'notes.markdown.spaces.publication.copied')"
                    >
                        <Copy class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    <!-- `space?.` and not `space.`, inside this slot only.
                         The dialog's own guard keeps the whole block out
                         while the space is still loading, but a slot body is
                         run by the button, not here: saving clears the open
                         space and the button renders its label once more on
                         the way out, with nothing left to read. It threw a
                         TypeError the error boundary turned into a failed
                         page, and the save went through all the same, which
                         is why nobody had reported it. -->
                    <AppButton
                        variant="secondary"
                        size="md"
                        data-space-publish
                        :loading="publishing"
                        v-on:click="applyPublication(true)"
                    >
                        <Globe class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ space?.published ? t('notes.markdown.spaces.publication.update') : t('notes.markdown.spaces.publication.publish') }}
                    </AppButton>
                    <AppButton
                        v-if="space.published"
                        variant="ghost"
                        size="md"
                        data-space-unpublish
                        :disabled="publishing"
                        v-on:click="applyPublication(false)"
                    >
                        {{ t('notes.markdown.spaces.publication.unpublish') }}
                    </AppButton>
                </div>
            </section>

            <p v-if="confirmingDelete" class="mt-6 text-sm text-rose-400" data-space-confirm-delete>
                {{ t('notes.markdown.spaces.confirm_delete', { name: spaceLabel(space, t) }) }}
            </p>
        </template>

        <template #footer>
            <AppModalFooter>
                <AppButton
                    v-if="space && !isPersonal"
                    variant="danger"
                    size="md"
                    class="mr-auto"
                    data-space-delete
                    :disabled="saving"
                    v-on:click="removeSpace"
                >
                    <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ confirmingDelete ? t('notes.markdown.spaces.delete_confirm') : t('notes.markdown.spaces.delete') }}
                </AppButton>
                <AppButton variant="ghost" size="md" :disabled="saving" v-on:click="emit('close')">
                    <X class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t('notes.markdown.cancel') }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    data-space-save
                    :loading="saving"
                    :disabled="!canSave || !space"
                    v-on:click="save"
                >
                    {{ t('notes.markdown.spaces.save') }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
