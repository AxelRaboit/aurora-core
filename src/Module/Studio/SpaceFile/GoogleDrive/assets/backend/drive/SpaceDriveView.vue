<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ChevronRight, ExternalLink, FileText, Folder, FolderOpen, LayoutGrid, Link2Off, List, RefreshCw, X } from "lucide-vue-next";
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
 * Le dossier ouvert, « » à la racine.
 *
 * **L'arborescence se reconstruit ici, pas chez Google.** La descente a déjà
 * ramené chaque fichier avec son chemin ; en refaire un appel par dossier
 * ouvert serait payer deux fois ce qu'on a. Entrer dans un dossier et en
 * ressortir ne coûte donc rien du tout.
 */
const cwd = ref("");

/** « Contrats/2026 » devient les deux marches qui y mènent. */
const breadcrumb = computed(() => ("" === cwd.value ? [] : cwd.value.split("/")));

function goTo(depth) {
    cwd.value = breadcrumb.value.slice(0, depth).join("/");
}

/** Les sous-dossiers directs du dossier ouvert, déduits des chemins. */
const folders = computed(() => {
    const prefix = "" === cwd.value ? "" : cwd.value + "/";
    const names = new Map();

    for (const file of files.value) {
        if (file.path === cwd.value || !file.path.startsWith(prefix)) continue;

        const name = file.path.slice(prefix.length).split("/")[0];
        names.set(name, (names.get(name) ?? 0) + 1);
    }

    return [...names].map(([name, count]) => ({ name, count })).sort((a, b) => a.name.localeCompare(b.name));
});

/** Les fichiers posés directement dans le dossier ouvert. */
const visible = computed(() => files.value.filter((file) => file.path === cwd.value));

async function load() {
    if (!linked.value) {
        files.value = [];

        return;
    }

    loading.value = true;

    try {
        const data = await request(props.listPath, null, { method: HttpMethod.Get, noGuard: true });
        files.value = Array.isArray(data?.files) ? data.files : [];
    } finally {
        loading.value = false;
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
            cwd.value = "";
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

            <div v-if="linked" class="flex items-center gap-2">
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

        <p class="text-xs text-muted">{{ t("backend.studio.drive.space.intro") }}</p>

        <!-- L'explication sous la rangée entière, et non sous le seul champ :
             collée au champ, elle poussait le bouton d'une ligne vers le bas,
             qui s'alignait alors sur elle au lieu de s'aligner sur la saisie. -->
        <div class="space-y-1">
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

        <template v-if="linked && !loading">
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
                        v-on:click="cwd = cwd ? `${cwd}/${entry.name}` : entry.name"
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
                            v-on:click="cwd = cwd ? `${cwd}/${entry.name}` : entry.name"
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
