<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { ExternalLink, FolderOpen, Link2Off, RefreshCw } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
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

const loading = ref(false);
const saving = ref(false);
const folder = ref(props.folderId ?? "");
const current = ref(props.folderId ?? null);
const files = ref([]);

const linked = computed(() => null !== current.value && "" !== current.value);

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
    if (null === file.size || undefined === file.size) return t("backend.studio.drive.space.no_size");

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
    <section class="relative space-y-3">
        <AppLoader :active="loading" />

        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="flex items-center gap-2 text-sm font-medium text-primary">
                <FolderOpen class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t("backend.studio.drive.space.title") }}
            </h3>

            <AppButton
                v-if="linked"
                class="w-full sm:w-auto"
                variant="ghost"
                size="sm"
                :loading="loading"
                v-on:click="load"
            >
                <RefreshCw class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("shared.common.refresh") }}
            </AppButton>
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

            <!-- Chaque fichier passe par une adresse d'Aurora : le client de
                 l'espace n'a pas de compte Google, et un lien vers Drive lui
                 donnerait un mur d'authentification. -->
            <ul v-else class="divide-y divide-line/60 overflow-hidden rounded-lg border border-line">
                <li v-for="file in files" :key="file.id">
                    <a
                        :href="addressOf(file)"
                        target="_blank"
                        rel="noopener"
                        class="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-surface-2/60"
                    >
                        <span class="min-w-0 flex-1 truncate text-sm text-primary">
                            <!-- Le sous-dossier devant le nom, en gris : une
                                 liste à plat qui ne dit pas d'où vient chaque
                                 fichier serait moins lisible que l'arbre
                                 qu'elle remplace. -->
                            <span v-if="file.path" class="text-muted">{{ file.path }}/</span>{{ file.name }}
                        </span>
                        <span class="shrink-0 text-xs tabular-nums text-muted">{{ weightOf(file) }}</span>
                        <span v-if="file.modifiedAt" class="hidden shrink-0 text-xs text-muted sm:inline">
                            {{ formatDateTimeNumeric(file.modifiedAt) }}
                        </span>
                        <ExternalLink class="h-3.5 w-3.5 shrink-0 text-muted" :stroke-width="2" />
                    </a>
                </li>
            </ul>
        </template>
    </section>
</template>
