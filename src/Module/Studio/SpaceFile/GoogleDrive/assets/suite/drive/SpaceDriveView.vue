<script setup>
import { computed, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";
import { toast } from "vue-sonner";
import { ChevronRight, Download, ExternalLink, FileText, Folder, FolderOpen, LayoutGrid, Library, List, Lock, LockOpen, Package, RefreshCw, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppFilePreview from "@/shared/components/display/AppFilePreview.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useDriveTree } from "./useDriveTree.js";

/**
 * A space's Drive folder, its own view in the bar.
 *
 * **Next to Files, and not inside it.** The bar already separates by origin -
 * what is set on the records, what belongs to the space - and a folder that
 * lives with the client is a third one. Filed as a section under the files,
 * everything else had to be scrolled past to reach it.
 *
 * **Loaded on open, not on mount.** Reading a folder from Google costs a
 * round trip, and most spaces have no Drive.
 */
const props = defineProps({
    folderId: { type: String, default: null },
    listPath: { type: String, required: true },
    filePath: { type: String, required: true },
    archivePath: { type: String, default: "" },
    importPath: { type: String, default: "" },
    /** The address that opens the lock for this session. */
    unlockPath: { type: String, default: "" },
    /** True for the lead: only they can go and connect the folder. */
    canConfigure: { type: Boolean, default: false },
    /**
     * What the view says when no folder is designated. A client's is
     * connected in their space's settings; the agency's, in the Drive
     * configuration: the same sentence would send people to the wrong place.
     */
    emptyKey: { type: String, default: "" },
    /** The sentence under the title: the client's does not fit the agency. */
    introKey: { type: String, default: "suite.studio.drive.space.intro" },
});

const { t } = useI18n();
// The shared formatter: three local copies said the same thing,
// and only one knew the units of the other languages.
const { formatSize } = useFileSize();
const { request } = useRequest();
const { formatDateTime } = useDateFormat();

/**
 * The cards need width. Below the threshold, the list says the same thing
 * without stretching a thumbnail across the whole row - the same rule as the
 * space's other views.
 */
const { container, isNarrow } = useNarrowContainer(560);

const { choice: stored } = usePersistedChoice("studio.space_drive.view", "grid", ["grid", "list"]);

const mode = computed(() => (isNarrow.value ? "list" : stored.value));

const loading = ref(false);
const current = ref(props.folderId ?? null);
const files = ref([]);
const previewed = ref(null);

const linked = computed(() => null !== current.value && "" !== current.value);

/**
 * What the folder weighs, and whether the bundle fits.
 *
 * **The same limit as the server, stated here before the click.** The
 * archive is built in full before it leaves, so beyond a certain size the web
 * server gives up before the end. Rather than letting someone press a button
 * that will end in an error after five minutes, the screen does not offer it
 * and explains what to do instead: the files one by one.
 */
const ARCHIVE_MAX_BYTES = 150 * 1024 * 1024;

const weight = computed(() =>
    files.value.reduce((total, file) => total + (file.size ?? 0), 0),
);

const archivable = computed(() => files.value.length > 0 && weight.value <= ARCHIVE_MAX_BYTES);

/**
 * The tree, rebuilt in the browser from the paths.
 *
 * The composable is shared with the picker that attaches a Drive file to a
 * record: two screens read the same tree, and a second copy of this deduction
 * would have ended up diverging.
 */
const { breadcrumb, folders, visible, goTo, open, reset } = useDriveTree(files);

/**
 * Closed by a password, and not yet opened in this session.
 *
 * The server says so in the list rather than answering 404: a bare refusal
 * would be indistinguishable from a space without Drive, and the screen would
 * show "no folder" to someone who only has a password to type.
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

        // The refusal, announced: without it, a wrong password produces
        // nothing at all and the button looks dead.
        if (!data?.unlocked) {
            toast.error(t(data?.error ?? "shared.common.error"));

            return;
        }

        password.value = "";
        locked.value = false;
        await load();
    } finally {
        unlocking.value = false;
    }
}

onMounted(load);

function addressOf(file) {
    return props.filePath.replace("__id__", file.id);
}

/** The same address, but to take the file away rather than look at it. */
function downloadOf(file) {
    return addressOf(file) + "?download=1";
}

const importing = ref(false);

/**
 * Files the file in the space's media library.
 *
 * **It is the gateway to everything else.** A note displays images from the
 * media library, a record attaches documents from it, a gallery draws from
 * it: once filed, the Drive file is no longer a special case and each of
 * these screens sees it without knowing anything about Google. Connecting
 * Drive into the notes editor would have required the core to know about a
 * module's integration, which is what the registry exists to avoid.
 */
async function importToLibrary(file) {
    if (!file || !props.importPath || importing.value) return;

    importing.value = true;

    try {
        const data = await request(props.importPath.replace("__fileId__", file.id), {});

        if (data?.document) {
            toast.success(t("suite.studio.drive.space.imported"));
        }
    } finally {
        importing.value = false;
    }
}

</script>

<template>
    <section ref="container" class="relative space-y-3">
        <AppLoader :active="loading" />

        <header class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="flex items-center gap-2 text-sm font-medium text-primary">
                <FolderOpen class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t("suite.studio.drive.space.title") }}
            </h3>

            <div v-if="linked && !locked" class="flex items-center gap-2">
                <!-- Hidden where a narrow container already forces the list: a
                     switch that changes nothing reads as broken. -->
                <div
                    v-if="!isNarrow"
                    class="flex items-center gap-0.5 rounded-lg border border-line bg-surface-2/40 p-0.5"
                    role="group"
                    :aria-label="t('suite.studio.drive.space.view_label')"
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

                <!-- The whole folder in one go. Hidden as long as there is
                     nothing to take: a button that would produce an empty
                     archive reads as broken. -->
                <AppButton
                    v-if="archivable && archivePath"
                    class="shrink-0"
                    variant="ghost"
                    size="sm"
                    :href="archivePath"
                    :title="t('suite.studio.drive.space.archive_weight', { weight: formatSize(weight) })"
                >
                    <Package class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("suite.studio.drive.space.archive") }}
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

        <p v-if="!locked" class="text-xs text-muted">{{ t(introKey) }}</p>

        <p
            v-if="linked && !loading && files.length && !archivable"
            class="rounded-lg border border-line bg-surface-2 px-3 py-2 text-xs text-muted"
        >
            {{ t("suite.studio.drive.space.archive_too_large", { weight: formatSize(weight) }) }}
        </p>

        <!-- No folder field here any more: designating it is configuration,
             it lives in the Settings. What remains is a pointer for whoever
             lands on an empty screen, and nothing for the others: a team
             member who is not the lead would see an instruction they cannot
             follow. -->
        <p
            v-if="!linked && !loading"
            class="rounded-lg border border-line bg-surface-2 px-3 py-3 text-xs text-muted"
        >
            {{ t(emptyKey || (canConfigure ? "suite.studio.drive.space.no_folder_referent" : "suite.studio.drive.space.no_folder")) }}
        </p>

        <!-- The lock takes all the room: while it is closed, there is nothing
             else to show, and leaving the folder field visible would suggest
             it can be changed to get around the lock. -->
        <section
            v-if="locked"
            class="space-y-3 rounded-lg border border-line bg-surface-2 px-3 py-4 sm:px-4"
        >
            <h3 class="flex items-center gap-2 text-sm font-medium text-primary">
                <Lock class="h-4 w-4 shrink-0" :stroke-width="2" />
                {{ t("suite.studio.drive.space.locked_title") }}
            </h3>
            <p class="text-xs text-muted">{{ t("suite.studio.drive.space.locked_intro") }}</p>

            <form class="flex flex-col gap-2 sm:flex-row sm:items-center" v-on:submit.prevent="unlock">
                <!-- `AppInput` and not a raw field: the eye that reveals the
                     input matters all the more here, where a mistake says
                     nothing more precise than "incorrect password". -->
                <AppInput
                    class="min-w-0 flex-1"
                    :model-value="password"
                    type="password"
                    toggleable
                    :placeholder="t('suite.studio.drive.space.locked_placeholder')"
                    v-on:update:model-value="password = $event"
                />
                <AppButton
                    class="w-full shrink-0 sm:w-auto"
                    variant="primary"
                    size="sm"
                    type="submit"
                    :loading="unlocking"
                    :disabled="!password"
                >
                    <LockOpen class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t("suite.studio.drive.space.unlock") }}
                </AppButton>
            </form>
        </section>

        <template v-if="linked && !loading && !locked">
            <p v-if="!files.length" class="rounded-lg border border-line bg-surface-2 px-3 py-3 text-xs text-muted">
                {{ t("suite.studio.drive.space.empty") }}
            </p>

            <template v-else>
                <!-- The path, and the way back up it. Each step is a button:
                     going down without being able to go back up would trap
                     one in a subfolder. -->
                <nav
                    v-if="breadcrumb.length"
                    class="flex flex-wrap items-center gap-1 text-xs text-muted"
                    :aria-label="t('suite.studio.drive.space.path_label')"
                >
                    <button
                        type="button"
                        class="rounded px-1.5 py-0.5 text-accent transition-colors hover:bg-surface-2"
                        v-on:click="goTo(0)"
                    >
                        {{ t("suite.studio.drive.space.root") }}
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

                <!-- The cards. The thumbnail comes from Google's CDN, which
                     serves it without authentication: relaying it would cost a
                     call per image for under a kilobyte. `no-referrer` so
                     Google does not learn which page asks for it, and `lazy`
                     so a folder of a hundred files only loads what is on
                     screen.

                     Small: the thumbnail is for recognising, not reading. The
                     file opens full size with one click, right next to it. -->
                <div v-if="'grid' === mode" class="grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                    <button
                        v-for="entry in folders"
                        :key="`folder:${entry.name}`"
                        type="button"
                        class="aurora-card overflow-hidden text-left transition-colors hover:border-accent/50"
                        :title="entry.name"
                        v-on:click="open(entry.name)"
                    >
                        <span class="flex aspect-[4/3] items-center justify-center bg-surface-2">
                            <Folder class="h-7 w-7 text-muted" :stroke-width="1.5" />
                        </span>
                        <span class="block space-y-0.5 px-2 py-1.5">
                            <span class="block truncate text-xs text-primary">{{ entry.name }}</span>
                            <span class="block text-2xs tabular-nums text-muted">
                                {{ t("suite.studio.drive.space.items", { count: entry.count }, entry.count) }}
                            </span>
                        </span>
                    </button>

                    <article
                        v-for="file in visible"
                        :key="file.id"
                        class="aurora-card overflow-hidden"
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
                            <p v-if="file.size" class="text-2xs tabular-nums text-muted">{{ formatSize(file.size) }}</p>
                        </div>
                    </article>
                </div>

                <ul v-else class="divide-y divide-line/40 overflow-hidden rounded-lg border border-line">
                    <li v-for="entry in folders" :key="`folder:${entry.name}`">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-3 py-2.5 text-left transition-colors hover:bg-surface-2/60"
                            v-on:click="open(entry.name)"
                        >
                            <Folder class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                            <span class="min-w-0 flex-1 truncate text-sm text-primary">{{ entry.name }}</span>
                            <span class="shrink-0 text-xs tabular-nums text-muted">
                                {{ t("suite.studio.drive.space.items", { count: entry.count }, entry.count) }}
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
                            <span v-if="file.size" class="shrink-0 text-xs tabular-nums text-muted">{{ formatSize(file.size) }}</span>
                            <span v-if="file.modifiedAt" class="hidden shrink-0 text-xs text-muted sm:inline">
                                {{ formatDateTime(file.modifiedAt) }}
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
            <!-- The preview reads through the address here, never Google's:
                 it is the same reason as for the rest, and it is what makes it
                 identical for the studio and for a client without an account. -->
            <AppFilePreview
                :url="previewed ? addressOf(previewed) : ''"
                :mime="previewed?.mimeType ?? ''"
                :name="previewed?.name ?? ''"
                max-height="60vh"
            />

            <p v-if="previewed" class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted">
                <span v-if="previewed.path">{{ previewed.path }}</span>
                <span v-if="previewed.path" aria-hidden="true">·</span>
                <span v-if="previewed.size">{{ formatSize(previewed.size) }}</span>
                <template v-if="previewed.modifiedAt">
                    <span aria-hidden="true">·</span>
                    <span>{{ formatDateTime(previewed.modifiedAt) }}</span>
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
                        {{ t("suite.studio.drive.space.import") }}
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
                        {{ t("suite.studio.drive.space.open") }}
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
