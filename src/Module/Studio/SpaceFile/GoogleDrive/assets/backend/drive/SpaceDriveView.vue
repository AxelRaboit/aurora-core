<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ChevronRight, Download, ExternalLink, FileText, Folder, FolderOpen, LayoutGrid, Library, Link2Off, List, Lock, LockOpen, Package, RefreshCw, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppFilePreview from "@/shared/components/display/AppFilePreview.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useDriveTree } from "./useDriveTree.js";

/**
 * Le dossier Drive d'un espace, sa propre vue dans la barre.
 *
 * **À côté de Fichiers, et non dedans.** La barre sépare déjà par origine -
 * ce qui est posé sur les fiches, ce qui appartient à l'espace - et un dossier
 * qui vit chez le client en est une troisième. Rangé en section sous les
 * fichiers, il fallait faire défiler tout le reste pour l'atteindre.
 *
 * **Chargée à l'ouverture, pas au montage.** Lire un dossier chez Google coûte
 * un aller-retour, et la plupart des espaces n'ont pas de Drive.
 */
const props = defineProps({
    folderId: { type: String, default: null },
    listPath: { type: String, required: true },
    folderPath: { type: String, required: true },
    filePath: { type: String, required: true },
    archivePath: { type: String, default: "" },
    importPath: { type: String, default: "" },
    /** L'adresse qui ouvre la serrure pour cette session. */
    unlockPath: { type: String, default: "" },
});

const { t } = useI18n();
const { request } = useRequest();
const { formatDateTimeNumeric } = useDateFormat();

/**
 * Les cartes demandent de la largeur. Sous le seuil, la liste dit la même
 * chose sans étirer une vignette sur toute la ligne - la même règle que les
 * autres vues de l'espace.
 */
const { container, isNarrow } = useNarrowContainer(560);

const { choice: stored } = usePersistedChoice("studio.space_drive.view", "grid", ["grid", "list"]);

const mode = computed(() => (isNarrow.value ? "list" : stored.value));

const loading = ref(false);
const saving = ref(false);
const folder = ref(props.folderId ?? "");
const current = ref(props.folderId ?? null);
const files = ref([]);
const previewed = ref(null);

const linked = computed(() => null !== current.value && "" !== current.value);

/**
 * Ce que pèse le dossier, et si le lot tient.
 *
 * **La même borne que le serveur, dite ici avant le clic.** L'archive se
 * construit en entier avant de partir, donc au-delà d'une certaine taille le
 * serveur web abandonne avant la fin. Plutôt que de laisser presser un bouton
 * qui finira en erreur au bout de cinq minutes, l'écran ne le propose pas et
 * explique ce qu'il faut faire à la place : les fichiers un par un.
 */
const ARCHIVE_MAX_BYTES = 150 * 1024 * 1024;

const weight = computed(() =>
    files.value.reduce((total, file) => total + (file.size ?? 0), 0),
);

const archivable = computed(() => files.value.length > 0 && weight.value <= ARCHIVE_MAX_BYTES);

/**
 * L'arborescence, reconstruite dans le navigateur à partir des chemins.
 *
 * Le composable est partagé avec le sélecteur qui accroche un fichier du Drive
 * à une fiche : deux écrans lisent le même arbre, et une seconde copie de
 * cette déduction aurait fini par diverger.
 */
const { breadcrumb, folders, visible, goTo, open, reset } = useDriveTree(files);

/**
 * Fermé par un mot de passe, et pas encore ouvert dans cette session.
 *
 * Le serveur le dit dans la liste plutôt que de répondre 404 : un refus sec
 * serait indiscernable d'un espace sans Drive, et l'écran afficherait « aucun
 * dossier » à quelqu'un qui n'a qu'un mot de passe à saisir.
 */
const locked = ref(false);
const password = ref("");
const unlocking = ref(false);

async function load() {
    if (!linked.value) {
        files.value = [];

        return;
    }

    loading.value = true;

    try {
        const data = await request(props.listPath, null, { method: HttpMethod.Get, noGuard: true });
        locked.value = true === data?.locked;
        files.value = Array.isArray(data?.files) ? data.files : [];
    } finally {
        loading.value = false;
    }
}

async function unlock() {
    if (!password.value || unlocking.value) return;

    unlocking.value = true;

    try {
        const data = await request(props.unlockPath, { password: password.value });

        if (data?.unlocked) {
            password.value = "";
            locked.value = false;
            await load();
        }
    } finally {
        unlocking.value = false;
    }
}

onMounted(load);

async function save() {
    saving.value = true;

    try {
        const data = await request(props.folderPath, { folder: folder.value.trim() });

        if (data) {
            current.value = data.folderId ?? null;
            folder.value = current.value ?? "";
            reset();
            toast.success(t(linked.value ? "backend.studio.drive.space.linked" : "backend.studio.drive.space.unlinked"));
            await load();
        }
    } finally {
        saving.value = false;
    }
}

function addressOf(file) {
    return props.filePath.replace("__id__", file.id);
}

/** La même adresse, mais pour emporter le fichier plutôt que le regarder. */
function downloadOf(file) {
    return addressOf(file) + "?download=1";
}

const importing = ref(false);

/**
 * Range le fichier dans la médiathèque de l'espace.
 *
 * **C'est le point de passage vers tout le reste.** Une note affiche des
 * images de la médiathèque, une fiche y accroche des documents, une galerie y
 * puise : une fois rangé, le fichier du Drive n'est plus un cas particulier et
 * chacun de ces écrans le voit sans rien savoir de Google. Brancher le Drive
 * dans l'éditeur de notes aurait demandé au noyau de connaître une
 * intégration d'un module, ce que le registre existe pour éviter.
 */
async function importToLibrary(file) {
    if (!file || !props.importPath || importing.value) return;

    importing.value = true;

    try {
        const data = await request(props.importPath.replace("__fileId__", file.id), {});

        if (data?.document) {
            toast.success(t("backend.studio.drive.space.imported"));
        }
    } finally {
        importing.value = false;
    }
}

/** Google omet la taille de ses propres formats : un document n'a pas d'octets. */
function weightOf(file) {
    if (null === file.size || undefined === file.size) return "";

    const units = ["o", "ko", "Mo", "Go"];
    let value = file.size;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        ++unit;
    }

    return `${value.toFixed(0 === unit ? 0 : 1)} ${units[unit]}`;
}
</script>

<template>
    <section ref="container" class="relative space-y-3">
        <AppLoader :active="loading" />

        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="flex items-center gap-2 text-sm font-medium text-primary">
                <FolderOpen class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t("backend.studio.drive.space.title") }}
            </h3>

            <div v-if="linked && !locked" class="flex items-center gap-2">
                <!-- Caché là où un conteneur étroit impose déjà la liste : un
                     interrupteur qui ne change rien se lit comme cassé. -->
                <div
                    v-if="!isNarrow"
                    class="flex items-center gap-0.5 rounded-lg border border-line/60 bg-surface-2/40 p-0.5"
                    role="group"
                    :aria-label="t('backend.studio.drive.space.view_label')"
                >
                    <AppIconButton
                        :title="t('shared.common.grid_view')"
                        :color="'grid' === mode ? 'accent' : 'default'"
                        v-on:click="stored = 'grid'"
                    >
                        <LayoutGrid class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                    <AppIconButton
                        :title="t('shared.common.list_view')"
                        :color="'list' === mode ? 'accent' : 'default'"
                        v-on:click="stored = 'list'"
                    >
                        <List class="h-3.5 w-3.5" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <!-- Tout le dossier en une fois. Caché tant qu'il n'y a rien
                     à emporter : un bouton qui produirait une archive vide se
                     lit comme cassé. -->
                <AppButton
                    v-if="archivable && archivePath"
                    class="shrink-0"
                    variant="ghost"
                    size="sm"
                    :href="archivePath"
                    :title="t('backend.studio.drive.space.archive_weight', { weight: weightOf({ size: weight }) })"
                >
                    <Package class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.drive.space.archive") }}
                </AppButton>

                <AppButton
                    class="shrink-0"
                    variant="ghost"
                    size="sm"
                    :loading="loading"
                    v-on:click="load"
                >
                    <RefreshCw class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("shared.common.refresh") }}
                </AppButton>
            </div>
        </header>

        <p v-if="!locked" class="text-xs text-muted">{{ t("backend.studio.drive.space.intro") }}</p>

        <p
            v-if="linked && !loading && files.length && !archivable"
            class="rounded-lg border border-line bg-surface-2 px-3 py-2 text-xs text-muted"
        >
            {{ t("backend.studio.drive.space.archive_too_large", { weight: weightOf({ size: weight }) }) }}
        </p>

        <!-- L'explication sous la rangée entière, et non sous le seul champ :
             collée au champ, elle poussait le bouton d'une ligne vers le bas,
             qui s'alignait alors sur elle au lieu de s'aligner sur la saisie. -->
        <div v-if="!locked" class="space-y-1">
            <span class="block text-xs text-secondary">{{ t("backend.studio.drive.space.folder_label") }}</span>

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                <input
                    v-model="folder"
                    type="text"
                    spellcheck="false"
                    placeholder="https://drive.google.com/drive/folders/…"
                    class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary"
                >
                <AppButton
                    class="w-full shrink-0 sm:w-auto"
                    variant="primary"
                    size="sm"
                    :loading="saving"
                    v-on:click="save"
                >
                    <component :is="linked && '' === folder.trim() ? Link2Off : FolderOpen" class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t(linked && "" === folder.trim() ? "backend.studio.drive.space.unlink" : "backend.studio.drive.space.link") }}
                </AppButton>
            </div>

            <span class="block text-xs text-muted">{{ t("backend.studio.drive.space.folder_hint") }}</span>
        </div>

        <!-- La serrure prend toute la place : tant qu'elle est fermée, il n'y
             a rien d'autre à montrer, et laisser le champ de dossier visible
             donnerait à croire qu'on peut le changer pour contourner. -->
        <section
            v-if="locked"
            class="space-y-3 rounded-lg border border-line bg-surface-2 px-3 py-4 sm:px-4"
        >
            <h3 class="flex items-center gap-2 text-sm font-medium text-primary">
                <Lock class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t("backend.studio.drive.space.locked_title") }}
            </h3>
            <p class="text-xs text-muted">{{ t("backend.studio.drive.space.locked_intro") }}</p>

            <form class="flex flex-col gap-2 sm:flex-row sm:items-center" v-on:submit.prevent="unlock">
                <input
                    v-model="password"
                    type="password"
                    autocomplete="off"
                    :placeholder="t('backend.studio.drive.space.locked_placeholder')"
                    class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary"
                >
                <AppButton
                    class="w-full shrink-0 sm:w-auto"
                    variant="primary"
                    size="sm"
                    type="submit"
                    :loading="unlocking"
                    :disabled="!password"
                >
                    <LockOpen class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.drive.space.unlock") }}
                </AppButton>
            </form>
        </section>

        <template v-if="linked && !loading && !locked">
            <p v-if="!files.length" class="rounded-lg border border-line bg-surface-2 px-3 py-3 text-xs text-muted">
                {{ t("backend.studio.drive.space.empty") }}
            </p>

            <template v-else>
                <!-- Le chemin, et le moyen d'en remonter. Chaque marche est un
                     bouton : descendre sans pouvoir remonter enfermerait dans
                     un sous-dossier. -->
                <nav
                    v-if="breadcrumb.length"
                    class="flex flex-wrap items-center gap-1 text-xs text-muted"
                    :aria-label="t('backend.studio.drive.space.path_label')"
                >
                    <button
                        type="button"
                        class="rounded px-1.5 py-0.5 text-accent transition-colors hover:bg-surface-2"
                        v-on:click="goTo(0)"
                    >
                        {{ t("backend.studio.drive.space.root") }}
                    </button>
                    <template v-for="(step, index) in breadcrumb" :key="index">
                        <ChevronRight class="h-3 w-3 shrink-0" :stroke-width="2" aria-hidden="true" />
                        <button
                            v-if="index < breadcrumb.length - 1"
                            type="button"
                            class="rounded px-1.5 py-0.5 text-accent transition-colors hover:bg-surface-2"
                            v-on:click="goTo(index + 1)"
                        >
                            {{ step }}
                        </button>
                        <span v-else class="px-1.5 py-0.5 text-primary" aria-current="page">{{ step }}</span>
                    </template>
                </nav>

                <!-- Les cartes. La vignette vient du CDN de Google, qui la sert
                     sans authentification : la relayer coûterait un appel par
                     image pour moins d'un kilo-octet. `no-referrer` pour que
                     Google n'apprenne pas de quelle page elle est demandée, et
                     `lazy` pour qu'un dossier de cent fichiers n'en charge que
                     ce qui est à l'écran.

                     Petites : la vignette sert à reconnaître, pas à lire. Le
                     fichier s'ouvre en grand d'un clic, juste à côté. -->
                <div v-if="'grid' === mode" class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                    <button
                        v-for="entry in folders"
                        :key="`folder:${entry.name}`"
                        type="button"
                        class="overflow-hidden rounded-lg border border-line/60 bg-surface text-left transition-colors hover:border-accent/50"
                        :title="entry.name"
                        v-on:click="open(entry.name)"
                    >
                        <span class="flex aspect-[4/3] items-center justify-center bg-surface-2">
                            <Folder class="h-7 w-7 text-muted" :stroke-width="1.5" />
                        </span>
                        <span class="block space-y-0.5 px-2 py-1.5">
                            <span class="block truncate text-xs text-primary">{{ entry.name }}</span>
                            <span class="block text-2xs tabular-nums text-muted">
                                {{ t("backend.studio.drive.space.items", { count: entry.count }, entry.count) }}
                            </span>
                        </span>
                    </button>

                    <article
                        v-for="file in visible"
                        :key="file.id"
                        class="overflow-hidden rounded-lg border border-line/60 bg-surface"
                    >
                        <button
                            type="button"
                            class="block w-full cursor-zoom-in appearance-none border-0 bg-transparent p-0 text-left"
                            :title="file.name"
                            v-on:click="previewed = file"
                        >
                            <span class="flex aspect-[4/3] items-center justify-center bg-surface-2">
                                <img
                                    v-if="file.thumbnail"
                                    :src="file.thumbnail"
                                    :alt="file.name"
                                    loading="lazy"
                                    referrerpolicy="no-referrer"
                                    class="h-full w-full object-cover"
                                >
                                <FileText v-else class="h-7 w-7 text-muted" :stroke-width="1.5" />
                            </span>
                        </button>

                        <div class="space-y-0.5 px-2 py-1.5">
                            <p class="truncate text-xs text-primary" :title="file.name">{{ file.name }}</p>
                            <p class="text-2xs tabular-nums text-muted">{{ weightOf(file) }}</p>
                        </div>
                    </article>
                </div>

                <ul v-else class="divide-y divide-line/60 overflow-hidden rounded-lg border border-line">
                    <li v-for="entry in folders" :key="`folder:${entry.name}`">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-surface-2/60"
                            v-on:click="open(entry.name)"
                        >
                            <Folder class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                            <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ entry.name }}</span>
                            <span class="shrink-0 text-xs tabular-nums text-muted">
                                {{ t("backend.studio.drive.space.items", { count: entry.count }, entry.count) }}
                            </span>
                        </button>
                    </li>

                    <li v-for="file in visible" :key="file.id">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-surface-2/60"
                            v-on:click="previewed = file"
                        >
                            <FileText class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                            <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ file.name }}</span>
                            <span class="shrink-0 text-xs tabular-nums text-muted">{{ weightOf(file) }}</span>
                            <span v-if="file.modifiedAt" class="hidden shrink-0 text-xs text-muted sm:inline">
                                {{ formatDateTimeNumeric(file.modifiedAt) }}
                            </span>
                        </button>
                    </li>
                </ul>
            </template>
        </template>

        <AppModal
            :show="!!previewed"
            max-width="3xl"
            :title="previewed?.name ?? ''"
            :icon="FileText"
            v-on:close="previewed = null"
        >
            <!-- L'aperçu lit par l'adresse d'ici, jamais par celle de Google :
                 c'est la même raison que le reste, et c'est ce qui le rend
                 identique pour le studio et pour un client sans compte. -->
            <AppFilePreview
                :url="previewed ? addressOf(previewed) : ''"
                :mime="previewed?.mimeType ?? ''"
                :name="previewed?.name ?? ''"
                max-height="60vh"
            />

            <p v-if="previewed" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
                <span v-if="previewed.path">{{ previewed.path }}</span>
                <span v-if="previewed.path" aria-hidden="true">·</span>
                <span v-if="weightOf(previewed)">{{ weightOf(previewed) }}</span>
                <template v-if="previewed.modifiedAt">
                    <span aria-hidden="true">·</span>
                    <span>{{ formatDateTimeNumeric(previewed.modifiedAt) }}</span>
                </template>
            </p>

            <template #footer>
                <AppModalFooter>
                    <AppButton
                        v-if="importPath"
                        class="w-full sm:w-auto"
                        variant="ghost"
                        size="md"
                        :loading="importing"
                        v-on:click="importToLibrary(previewed)"
                    >
                        <Library class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.drive.space.import") }}
                    </AppButton>
                    <AppButton
                        class="w-full sm:w-auto"
                        variant="ghost"
                        size="md"
                        :href="previewed ? downloadOf(previewed) : ''"
                    >
                        <Download class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.download") }}
                    </AppButton>
                    <AppButton
                        class="w-full sm:w-auto"
                        variant="ghost"
                        size="md"
                        :href="previewed ? addressOf(previewed) : ''"
                    >
                        <ExternalLink class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.drive.space.open") }}
                    </AppButton>
                    <AppButton class="w-full sm:w-auto" variant="primary" size="md" v-on:click="previewed = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.close") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </section>
</template>
