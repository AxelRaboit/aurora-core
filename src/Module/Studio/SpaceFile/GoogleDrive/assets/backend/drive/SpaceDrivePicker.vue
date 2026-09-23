<script setup>
/**
 * Choisir un fichier du dossier Drive pour l'accrocher à une fiche.
 *
 * **Le même arbre que la vue Drive**, par le même composable : on cherche un
 * fichier là où on a l'habitude de le voir, et un sélecteur qui aplatirait ce
 * que l'autre écran range en dossiers obligerait à réapprendre le dossier du
 * client à chaque fois.
 *
 * La liste est chargée à l'ouverture et non au montage : la fenêtre vit dans
 * le formulaire d'une fiche, qui s'ouvre bien plus souvent qu'on n'attache un
 * fichier venu du Drive.
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";
import { ChevronRight, FileText, Folder, FolderOpen } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useDriveTree } from "./useDriveTree.js";

const props = defineProps({
    show: { type: Boolean, default: false },
    listPath: { type: String, default: "" },
    /** Vrai pendant que le serveur descend le fichier chez Google. */
    importing: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "choose"]);

const { t } = useI18n();
// Le formateur partagé : trois copies locales disaient la même chose,
// et une seule connaissait les unités des autres langues.
const { formatSize } = useFileSize();
const { request } = useRequest();

const loading = ref(false);
const files = ref([]);
const chosen = ref(null);

const { breadcrumb, folders, visible, goTo, open, reset } = useDriveTree(files);

watch(
    () => props.show,
    async (isOpen) => {
        if (!isOpen || !props.listPath) return;

        // Toujours à la racine : rouvrir la fenêtre dans le sous-dossier de la
        // fois d'avant fait chercher un fichier qui n'y est pas.
        reset();
        chosen.value = null;
        loading.value = true;

        try {
            const data = await request(props.listPath, null, { method: HttpMethod.Get, noGuard: true });
            files.value = Array.isArray(data?.files) ? data.files : [];
        } finally {
            loading.value = false;
        }
    },
);

</script>

<template>
    <AppModal
        :show="show"
        max-width="2xl"
        :title="t('backend.studio.drive.picker.title')"
        :icon="FolderOpen"
        v-on:close="emit('close')"
    >
        <div class="relative min-h-40 space-y-3">
            <AppLoader :active="loading" />

            <p class="text-xs text-muted">{{ t("backend.studio.drive.picker.intro") }}</p>

            <nav
                v-if="breadcrumb.length"
                class="flex flex-wrap items-center gap-1 text-xs text-muted"
                :aria-label="t('backend.studio.drive.space.path_label')"
            >
                <button
                    type="button"
                    class="rounded px-1.5 py-1 text-accent transition-colors hover:bg-surface-2"
                    v-on:click="goTo(0)"
                >
                    {{ t("backend.studio.drive.space.root") }}
                </button>
                <template v-for="(step, index) in breadcrumb" :key="index">
                    <ChevronRight class="h-3 w-3 shrink-0" :stroke-width="2" aria-hidden="true" />
                    <button
                        v-if="index < breadcrumb.length - 1"
                        type="button"
                        class="rounded px-1.5 py-1 text-accent transition-colors hover:bg-surface-2"
                        v-on:click="goTo(index + 1)"
                    >
                        {{ step }}
                    </button>
                    <span v-else class="px-1.5 py-1 text-primary" aria-current="page">{{ step }}</span>
                </template>
            </nav>

            <p v-if="!loading && !files.length" class="rounded-lg border border-line bg-surface-2 px-3 py-3 text-xs text-muted">
                {{ t("backend.studio.drive.space.empty") }}
            </p>

            <ul v-else class="max-h-80 divide-y divide-line/40 overflow-y-auto overflow-x-hidden rounded-lg border border-line">
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
                        class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors"
                        :class="chosen?.id === file.id ? 'bg-accent/10' : 'hover:bg-surface-2/60'"
                        v-on:click="chosen = file"
                    >
                        <FileText class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                        <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ file.name }}</span>
                        <span v-if="file.size" class="shrink-0 text-xs tabular-nums text-muted">{{ formatSize(file.size) }}</span>
                    </button>
                </li>
            </ul>

            <!-- Ce que le bouton va faire, dit avant de le presser : le fichier
                 est recopié dans la médiathèque, et il n'y suivra plus le
                 Drive du client. -->
            <p v-if="chosen" class="text-xs text-muted">{{ t("backend.studio.drive.picker.copy_notice") }}</p>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton class="w-full sm:w-auto" variant="ghost" size="md" v-on:click="emit('close')">
                    {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    class="w-full sm:w-auto"
                    variant="primary"
                    size="md"
                    :disabled="!chosen"
                    :loading="importing"
                    v-on:click="emit('choose', chosen)"
                >
                    <FolderOpen class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("backend.studio.drive.picker.submit") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
