<script setup>
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { Copy, Link2, Plus, Trash2, X } from "lucide-vue-next";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";

/**
 * Les adresses qui ouvrent ce livrable sans l'espace du client autour : pour
 * celui à qui on transmet le document sans lui ouvrir l'espace.
 *
 * La fenêtre des liens de lecture des publications, reprise pour le module :
 *
 * The deck's share panel, for a publication: one link per recipient, each
 * with a label, an optional expiry and an optional password, and how often it
 * was opened. A link that was opened is revoked, never deleted, and the revoked
 * ones stay in the list, faded - who could read it once is worth knowing
 * afterwards. One nobody ever opened has nothing to remember: it is deleted.
 *
 * Fetched when it opens rather than handed to the editor up front: the links
 * change from elsewhere (a reader opening one bumps its count), and the editor
 * already carries enough.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    /** La liste ; `/create` et `/{id}/revoke` se greffent dessus. */
    linksPath: { type: String, required: true },
});

defineEmits(["close"]);

const { t } = useI18n();
const { request } = useRequest();
const { formatDateShort } = useDateFormat();

const links = ref([]);
const readable = ref(true);
const withheld = ref([]);
const placeholders = ref(0);
const loading = ref(false);
const creating = ref(false);
const revoking = ref(null);
/** Ce que la confirmation ouverte sur une ligne fera : retirer (révoquer) ou supprimer. */
const revokingKind = ref("revoke");
const revokingBusy = ref(false);
const newLabel = ref("");
const expiresInDays = ref("");
const newPassword = ref("");
const copiedId = ref(null);

const base = () => props.linksPath;

const expiryOptions = [
    { value: "", label: t("backend.studio.deliverables.links.no_expiry") },
    { value: "7", label: t("backend.studio.deliverables.links.days", { count: 7 }) },
    { value: "30", label: t("backend.studio.deliverables.links.days", { count: 30 }) },
    { value: "90", label: t("backend.studio.deliverables.links.days", { count: 90 }) },
];

const isLive = (link) =>
    !link.revokedAt &&
    (!link.expiresAt || new Date(link.expiresAt) > new Date());

/** Le premier message d'erreur du serveur, traduit. */
function firstError(data) {
    const key = Object.values(data?.errors ?? {}).find((value) => "string" === typeof value && "" !== value);

    return key ? t(key) : null;
}

function apply(data) {
    if (!data?.success) return;
    links.value = data.links ?? [];
    readable.value = data.readable ?? true;
    withheld.value = data.withheldPictures ?? [];
    placeholders.value = data.placeholders ?? 0;
}

/**
 * Remise à zéro : une seule fenêtre sert toutes les lignes d'une liste. Sans
 * cela, ouvrir « Liens » sur B montrait d'abord ceux de A, qui y restaient si
 * la requête de B échouait, et un mot de passe tapé pour A sans être validé
 * partait avec le lien créé pour B.
 */
function reset() {
    links.value = [];
    readable.value = true;
    withheld.value = [];
    placeholders.value = 0;
    revoking.value = null;
    newLabel.value = "";
    expiresInDays.value = "";
    newPassword.value = "";
    copiedId.value = null;
}

/** Quelle ouverture attend sa réponse : une réponse d'une ouverture précédente est ignorée. */
let opening = 0;

async function load() {
    const mine = ++opening;
    loading.value = true;
    try {
        const data = await request(base(), null, { method: HttpMethod.Get });
        if (mine === opening) apply(data);
    } finally {
        if (mine === opening) loading.value = false;
    }
}

watch(
    [() => props.show, () => props.linksPath],
    ([open]) => {
        reset();
        if (open) load();
    },
    { immediate: true },
);

async function createLink() {
    if (creating.value) return;

    creating.value = true;
    try {
        const data = await request(`${base()}/create`, {
            label: newLabel.value,
            expiresInDays: expiresInDays.value ? Number(expiresInDays.value) : null,
            password: newPassword.value,
        });

        if (!data?.success) {
            toast.error(firstError(data) ?? t("backend.studio.deliverables.links.create_failed"));

            return;
        }

        apply(data);
        newLabel.value = "";
        // Cleared rather than kept: the field holds a secret, and a second
        // link made from this panel would otherwise inherit the first one's.
        newPassword.value = "";
        toast.success(t("backend.studio.deliverables.links.created"));
    } finally {
        creating.value = false;
    }
}

/** Une adresse jamais ouverte se supprime ; une adresse ouverte se révoque et garde sa ligne. */
const isDeletable = (link) => 0 === link.openCount;

/** Les textes de la confirmation ouverte : supprimer ou retirer. */
const removal = computed(() => {
    const scope = "backend.studio.deliverables.links";

    return "delete" === revokingKind.value
        ? { confirm: `${scope}.delete_confirm`, action: `${scope}.delete`, failed: `${scope}.delete_failed`, done: `${scope}.deleted_toast` }
        : { confirm: `${scope}.revoke_confirm`, action: `${scope}.revoke`, failed: `${scope}.revoke_failed`, done: `${scope}.revoked_toast` };
});

function askToRemove(link) {
    revokingKind.value = isDeletable(link) ? "delete" : "revoke";
    revoking.value = link.id;
}

/** Retirer une adresse ne se rattrape pas : on demande confirmation sur sa ligne. */
async function revoke(link) {
    if (revokingBusy.value) return;

    const kind = revokingKind.value;
    revokingBusy.value = true;
    try {
        const data = await request(`${base()}/${link.id}/${kind}`);

        if (!data?.success) {
            // Quelqu'un l'a ouverte entre-temps : le serveur le dit, la liste se met à jour, et ce sera une révocation.
            const reason = firstError(data);
            toast.error(reason ?? t(removal.value.failed));
            if (reason) {
                revoking.value = null;
                await load();
            }

            return;
        }

        apply(data);
        revoking.value = null;
        toast.success(t(removal.value.done));
    } finally {
        revokingBusy.value = false;
    }
}

/**
 * Copy, with a fallback that is not a failure: the clipboard needs a secure
 * context, routinely absent on a local instance over plain http, and the
 * address shown in the row can then be selected by hand.
 */
async function copy(link) {
    try {
        await navigator.clipboard.writeText(link.url);
        toast.success(t("backend.studio.deliverables.links.copied"));
    } catch {
        toast.message(t("backend.studio.deliverables.links.copy_manually"));
    }

    copiedId.value = link.id;
    setTimeout(() => {
        if (copiedId.value === link.id) copiedId.value = null;
    }, 2000);
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="lg"
        :title="t('backend.studio.deliverables.links.title')"
        :icon="Link2"
        v-on:close="$emit('close')"
    >
        <div class="space-y-4">
            <p class="m-0 text-sm text-secondary">{{ t("backend.studio.deliverables.links.intro") }}</p>

            <!-- Before the form, like the deck's warning: they change what is
                 about to be sent, not what was sent. -->
            <div
                v-if="!readable"
                class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300"
                role="status"
            >
                {{ t("backend.studio.deliverables.links.not_readable") }}
            </div>
            <div
                v-if="placeholders"
                class="flex flex-col gap-1 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3"
                role="status"
            >
                <p class="m-0 text-sm font-medium text-amber-700 dark:text-amber-300">
                    {{ t("backend.studio.deliverables.links.placeholders_title", { count: placeholders }) }}
                </p>
                <p class="m-0 text-xs text-secondary">{{ t("backend.studio.deliverables.links.placeholders_hint") }}</p>
            </div>
            <div
                v-if="withheld.length"
                class="flex flex-col gap-1 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3"
                role="status"
            >
                <p class="m-0 text-sm font-medium text-amber-700 dark:text-amber-300">
                    {{ t("backend.studio.deliverables.links.withheld_title", withheld.length) }}
                </p>
                <p class="m-0 text-xs text-secondary">{{ t("backend.studio.deliverables.links.withheld_hint") }}</p>
                <p class="m-0 truncate text-xs text-muted">{{ withheld.map((picture) => picture.name).join(", ") }}</p>
            </div>

            <!-- Un vrai formulaire : Entrée crée le lien, comme le bouton. -->
            <form class="space-y-4" v-on:submit.prevent="createLink">
                <div class="flex flex-wrap items-end gap-2">
                    <AppInput
                        v-model="newLabel"
                        class="min-w-48 flex-1"
                        :label="t('backend.studio.deliverables.links.label')"
                        :placeholder="t('backend.studio.deliverables.links.label_placeholder')"
                    />
                    <AppSelect
                        v-model="expiresInDays"
                        class="w-44"
                        :label="t('backend.studio.deliverables.links.expiry')"
                        :options="expiryOptions"
                    />
                    <AppButton type="submit" variant="primary" :loading="creating">
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.deliverables.links.create") }}
                    </AppButton>
                </div>

                <AppInput
                    v-model="newPassword"
                    type="password"
                    autocomplete="new-password"
                    :label="t('backend.studio.deliverables.links.password')"
                    :placeholder="t('backend.studio.deliverables.links.password_placeholder')"
                    :hint="t('backend.studio.deliverables.links.password_hint')"
                />
            </form>

            <p v-if="!loading && !links.length" class="m-0 text-sm text-muted">{{ t("backend.studio.deliverables.links.none") }}</p>

            <ul v-else class="m-0 flex list-none flex-col gap-2 p-0" role="list">
                <li
                    v-for="link in links"
                    :key="link.id"
                    class="rounded-lg border border-line p-3"
                    :class="isLive(link) ? '' : 'opacity-60'"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="min-w-0 text-sm font-medium text-primary">
                            {{ link.label || t("backend.studio.deliverables.links.untitled") }}
                        </span>
                        <span class="flex shrink-0 gap-1">
                            <AppIconButton :title="t('backend.studio.deliverables.links.copy')" v-on:click="copy(link)">
                                <Copy class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                v-if="(isDeletable(link) || isLive(link)) && revoking !== link.id"
                                :title="t(isDeletable(link) ? 'backend.studio.deliverables.links.delete' : 'backend.studio.deliverables.links.revoke')"
                                v-on:click="askToRemove(link)"
                            >
                                <Trash2 v-if="isDeletable(link)" class="h-3.5 w-3.5" :stroke-width="2" />
                                <X v-else class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>
                        </span>
                    </div>

                    <!-- Un lien retiré ne se rétablit pas : une seconde étape,
                         sur la ligne même, plutôt qu'une fenêtre de plus. -->
                    <div
                        v-if="revoking === link.id"
                        class="mt-2 flex flex-wrap items-center gap-2 rounded-md bg-rose-500/10 p-2"
                        role="alert"
                    >
                        <p class="m-0 min-w-0 flex-1 text-xs text-primary">{{ t(removal.confirm) }}</p>
                        <AppButton variant="ghost" size="sm" v-on:click="revoking = null">
                            {{ t("shared.common.cancel") }}
                        </AppButton>
                        <AppButton variant="danger" size="sm" :loading="revokingBusy" v-on:click="revoke(link)">
                            {{ t(removal.action) }}
                        </AppButton>
                    </div>

                    <p class="m-0 mt-1 truncate font-mono text-xs text-muted">
                        {{ copiedId === link.id ? t("backend.studio.deliverables.links.copied") : link.url }}
                    </p>

                    <p class="m-0 mt-1 text-xs text-muted">
                        <span v-if="link.revokedAt">{{ t("backend.studio.deliverables.links.revoked") }}</span>
                        <span v-else-if="link.expiresAt">{{ t("backend.studio.deliverables.links.expires_on", { date: formatDateShort(link.expiresAt) }) }}</span>
                        <span v-else>{{ t("backend.studio.deliverables.links.no_expiry") }}</span>
                        <span v-if="link.locked"> · {{ t("backend.studio.deliverables.links.locked") }}</span>
                        <span v-if="link.lastUsedAt">
                            ·
                            {{ link.openCount > 1
                                ? t("backend.studio.deliverables.links.opened", { count: link.openCount })
                                : t("backend.studio.deliverables.links.opened_once") }}
                            · {{ t("backend.studio.deliverables.links.last_used", { date: formatDateShort(link.lastUsedAt) }) }}
                        </span>
                        <span v-else> · {{ t("backend.studio.deliverables.links.never_opened") }}</span>
                    </p>
                </li>
            </ul>
        </div>
    </AppModal>
</template>
