<script setup>
import DocumentFamilyChips from "@ged/suite/documents/components/DocumentFamilyChips.vue";
import { familyMembers } from "@ged/suite/documents/utils/familyLabels.js";
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { Check, FileText, Folder, Search, X } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppThumbnail from "@/shared/components/display/AppThumbnail.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { useFileSize } from "@/shared/composables/format/useFileSize.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * Reusable picker for an existing GED Document. Mirrors the role of
 * `MediaPickerModal` but for the document-management module - semantically
 * the right place when callers need a business document (welding PDF
 * templates, contract attachments, …) rather than a content asset.
 *
 * Out of scope (intentional vs MediaPickerModal):
 *   - No upload from inside the picker. Users go to /suite/ged/documents
 *     to add or version docs. Keeps the component lean and discourages
 *     uploading docs without their metadata (category, tags, folder).
 *   - No inline edit of title/description for the same reason.
 *
 * Optional filters:
 *   - `mimeFilter` - restrict the visible documents to a single MIME
 *     (e.g. "application/pdf"). Applied client-side from the list payload.
 *   - `status` query - defaults to "published".
 *
 * Emits `select` with the full serialized document so the consumer can
 * extract whichever field it needs (`fileId`, `fileName`, etc.).
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    listPath: { type: String, required: true },
    // Strict MIME equality, e.g. "application/pdf".
    mimeFilter: { type: String, default: null },
    // Prefix-match, e.g. "image/" → every raster image MIME passes. Set by
    // the document picker wrapper when the consumer only wants images.
    mimePrefix: { type: String, default: null },
    // Bulk-pick mode: confirm returns an array of docs, not a single one.
    // Mirrors MediaPickerModal's `multiple` so callers like Gallery's
    // addPhotos flow can keep their existing array-shaped contract.
    multiple: { type: Boolean, default: false },
    // Extra query parameters for the listing, over the defaults. An empty
    // string drops a default: `{ status: "" }` lifts the published-only
    // filter for a caller that wants drafts too.
    query: { type: Object, default: () => ({}) },
    pexelsSearchPath: { type: String, default: "/suite/ged/pexels/search" },
    pexelsImportPath: { type: String, default: "/suite/ged/pexels/import" },
});

const emit = defineEmits(["close", "select"]);

const { t } = useI18n();
const { formatSize } = useFileSize();
const { request } = useRequest();

const items = ref([]);
const loading = ref(false);
const search = ref("");
const page = ref(1);
const totalPages = ref(1);
const selected = ref(null);

/**
 * Stock photos are offered only where they make sense: a picker opened to
 * choose a contract PDF has no business showing landscapes, and Pexels
 * returns nothing else.
 */
const pexelsAvailable = computed(() => props.mimePrefix === "image/");

const tab = ref("library");
const pexelsItems = ref([]);
const pexelsQuery = ref("");
const pexelsPage = ref(1);
const pexelsTotalPages = ref(1);
const pexelsLoading = ref(false);
const pexelsConfigured = ref(true);
const pexelsSelected = ref(null);
const importing = ref(false);

const visibleItems = computed(() => {
    let list = items.value;
    if (props.mimeFilter) {
        list = list.filter((doc) => doc.fileMime === props.mimeFilter);
    }
    if (props.mimePrefix) {
        list = list.filter((doc) => (doc.fileMime ?? "").startsWith(props.mimePrefix));
    }
    return list;
});

async function load() {
    loading.value = true;
    try {
        // Families folded, as in the library: each visual once, its
        // alternates chosen from its card. A caller lifts it with
        // `{ originalsOnly: "" }`.
        const params = new URLSearchParams({
            page: String(page.value),
            status: "published",
            originalsOnly: "1",
        });
        for (const [key, value] of Object.entries(props.query)) {
            if ("" === value || null === value) params.delete(key);
            else params.set(key, String(value));
        }
        if (search.value) params.set("search", search.value);
        const res = await fetch(`${props.listPath}?${params}`, {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        });
        const data = await res.json();
        items.value = data.items ?? [];
        page.value = data.page ?? 1;
        totalPages.value = data.totalPages ?? 1;
    } finally {
        loading.value = false;
    }
}

/**
 * The Pexels side of the picker.
 *
 * `noGuard` because the shared loading guard drops a second call while one is
 * in flight, and paging through results is exactly that. Failures come back
 * as an empty list rather than an exception - `useRequest` has already told
 * the user, and an editor mid-page does not need a second alarm.
 */
async function loadPexels() {
    if ("" === pexelsQuery.value.trim()) {
        pexelsItems.value = [];
        pexelsTotalPages.value = 1;

        return;
    }

    pexelsLoading.value = true;
    try {
        const params = new URLSearchParams({
            q: pexelsQuery.value,
            page: String(pexelsPage.value),
        });
        const data = await request(`${props.pexelsSearchPath}?${params}`, null, {
            method: HttpMethod.Get,
            noGuard: true,
        });

        pexelsConfigured.value = data?.configured !== false;
        pexelsItems.value = data?.results ?? [];
        pexelsTotalPages.value = data?.totalPages || 1;
    } finally {
        pexelsLoading.value = false;
    }
}

function onPexelsSearch(value) {
    pexelsQuery.value = value;
    pexelsPage.value = 1;
    loadPexels();
}

function goToPexelsPage(p) {
    pexelsPage.value = p;
    loadPexels();
}

function pickPhoto(photo) {
    if (!props.multiple) {
        pexelsSelected.value = photo;

        return;
    }
    const list = Array.isArray(pexelsSelected.value) ? [...pexelsSelected.value] : [];
    const idx = list.findIndex((p) => p.id === photo.id);
    if (idx === -1) list.push(photo);
    else list.splice(idx, 1);
    pexelsSelected.value = list;
}

function isPhotoSelected(photo) {
    if (props.multiple) {
        return Array.isArray(pexelsSelected.value) && pexelsSelected.value.some((p) => p.id === photo.id);
    }

    return pexelsSelected.value?.id === photo.id;
}

const hasPexelsSelection = computed(() =>
    props.multiple
        ? Array.isArray(pexelsSelected.value) && pexelsSelected.value.length > 0
        : null !== pexelsSelected.value,
);

/**
 * Turns the chosen photos into documents.
 *
 * The import is the moment the picture enters the library, so it happens on
 * confirm rather than on click: browsing a search result should not litter
 * the media library with everything the editor considered.
 */
async function importSelection() {
    const photos = props.multiple ? pexelsSelected.value : [pexelsSelected.value];
    const documents = [];

    importing.value = true;
    try {
        for (const photo of photos) {
            // Sequential on purpose: each import is a write, and the Pexels
            // hourly quota is the whole installation's to spend carefully.
             
            const data = await request(props.pexelsImportPath, { photo }, { noGuard: true });
            if (!data?.document) return null;
            documents.push(data.document);
        }
    } finally {
        importing.value = false;
    }

    return props.multiple ? documents : documents[0];
}

watch(
    () => props.show,
    (next) => {
        if (next) {
            selected.value = props.multiple ? [] : null;
            search.value = "";
            page.value = 1;
            tab.value = "library";
            pexelsQuery.value = "";
            pexelsItems.value = [];
            pexelsPage.value = 1;
            pexelsSelected.value = props.multiple ? [] : null;
            load();
        }
    },
    { immediate: true },
);

function onSearch(value) {
    search.value = value;
    page.value = 1;
    load();
}

function goToPage(p) {
    page.value = p;
    load();
}

// The member each family card offers, chosen with its chips.
const previewed = ref({});

function memberOf(doc) {
    const id = previewed.value[doc.id];
    if (!id || id === doc.id) return doc;

    return doc.alternates?.find((member) => member.id === id) ?? doc;
}

function pickMember(doc, id) {
    previewed.value = { ...previewed.value, [doc.id]: id };
    pick(memberOf(doc));
}

function isFamilySelected(doc) {
    return isSelected(doc) || (doc.alternates ?? []).some((member) => isSelected(member));
}

function pick(doc) {
    if (!props.multiple) {
        selected.value = doc;
        return;
    }
    const list = Array.isArray(selected.value) ? [...selected.value] : [];
    const idx = list.findIndex((d) => d.id === doc.id);
    if (idx === -1) list.push(doc);
    else list.splice(idx, 1);
    selected.value = list;
}

async function confirm() {
    if ("pexels" === tab.value) {
        if (!hasPexelsSelection.value || importing.value) return;
        const result = await importSelection();
        // A failed import has already surfaced its own message; keeping the
        // modal open lets the editor retry instead of losing their search.
        if (result) emit("select", result);

        return;
    }

    if (!selected.value) return;
    if (props.multiple) {
        if (Array.isArray(selected.value) && selected.value.length > 0) emit("select", selected.value);
        return;
    }
    emit("select", selected.value);
}

const confirmDisabled = computed(() => {
    if ("pexels" === tab.value) {
        return !hasPexelsSelection.value || importing.value;
    }

    return props.multiple ? !(Array.isArray(selected.value) && selected.value.length > 0) : !selected.value;
});

function isSelected(doc) {
    if (props.multiple) {
        return Array.isArray(selected.value) && selected.value.some((d) => d.id === doc.id);
    }
    return selected.value?.id === doc.id;
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="4xl"
        :title="t('suite.ged.documents.picker_title')"
        :icon="FileText"
        :closeable="true"
        v-on:close="emit('close')"
    >
        <div class="space-y-4">
            <div v-if="pexelsAvailable" class="flex gap-1 border-b border-line">
                <AppTab variant="underline" :active="tab === 'library'" v-on:click="tab = 'library'">
                    {{ t("suite.ged.documents.picker_tab_library") }}
                </AppTab>
                <AppTab variant="underline" :active="tab === 'pexels'" v-on:click="tab = 'pexels'">
                    {{ t("suite.ged.pexels.tab") }}
                </AppTab>
            </div>

            <template v-if="tab === 'pexels'">
                <AppSearchInput
                    v-model="pexelsQuery"
                    :placeholder="t('suite.ged.pexels.search_placeholder')"
                    v-on:search="onPexelsSearch"
                />

                <p v-if="!pexelsConfigured" class="text-xs text-muted">
                    {{ t("suite.ged.pexels.not_configured") }}
                </p>

                <div class="relative min-h-64">
                    <ul v-if="pexelsItems.length > 0" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                        <li
                            v-for="photo in pexelsItems"
                            :key="photo.id"
                            :class="[
                                'group relative overflow-hidden rounded-lg border cursor-pointer transition-colors',
                                isPhotoSelected(photo)
                                    ? 'border-accent ring-2 ring-accent-500/40'
                                    : 'border-line hover:border-accent-500/40',
                            ]"
                            v-on:click="pickPhoto(photo)"
                        >
                            <!-- The provider's own placeholder colour, so a
                                 slow row is a tinted rectangle rather than a
                                 flash of empty layout. -->
                            <img
                                :src="photo.thumbUrl"
                                :alt="photo.description ?? ''"
                                loading="lazy"
                                class="w-full h-28 object-cover"
                                :style="{ backgroundColor: photo.color ?? 'transparent' }"
                            >
                            <!-- Credit is visible while choosing, not only
                                 once placed: it is whose work this is. -->
                            <p class="truncate px-2 py-1 text-2xs text-muted">{{ photo.authorName }}</p>
                            <Check
                                v-if="isPhotoSelected(photo)"
                                class="absolute top-1.5 right-1.5 w-5 h-5 text-accent"
                                :stroke-width="2.5"
                            />
                        </li>
                    </ul>
                    <AppNoData
                        v-else-if="!pexelsLoading && pexelsConfigured"
                        :message="t('suite.ged.pexels.empty')"
                    />
                    <AppLoader :active="pexelsLoading || importing" />
                </div>

                <AppPagination
                    v-if="pexelsTotalPages > 1"
                    :page="pexelsPage"
                    :total-pages="pexelsTotalPages"
                    v-on:change="goToPexelsPage"
                />
            </template>

            <template v-else>
                <AppSearchInput
                    v-model="search"
                    :placeholder="t('suite.ged.documents.search_placeholder')"
                    v-on:search="onSearch"
                />

                <div class="relative min-h-64">
                    <ul v-if="visibleItems.length > 0" class="space-y-1.5">
                        <li
                            v-for="doc in visibleItems"
                            :key="doc.id"
                            :class="[
                                'flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-colors',
                                isFamilySelected(doc)
                                    ? 'border-accent bg-accent-500/10'
                                    : 'border-line bg-surface-2 hover:border-accent-500/40 hover:bg-surface-2/60',
                            ]"
                            v-on:click="pick(memberOf(doc))"
                        >
                            <!-- Un aperçu plutôt qu'une icône : choisir une image
                                 sur son seul nom de fichier obligeait à la
                                 retrouver ailleurs. La petite taille générée
                                 d'abord, plus légère que le fichier entier. -->
                            <AppThumbnail
                                :src="memberOf(doc).renditions?.thumbnail ?? memberOf(doc).thumbnailUrl ?? null"
                                :alt="memberOf(doc).alt ?? memberOf(doc).title ?? ''"
                                size="md"
                            >
                                <span class="flex h-full w-full items-center justify-center">
                                    <FileText
                                        class="w-5 h-5"
                                        :class="'application/pdf' === doc.fileMime ? 'text-rose-400' : 'text-secondary'"
                                        :stroke-width="1.5"
                                    />
                                </span>
                            </AppThumbnail>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium text-primary truncate">{{ doc.title }}</span>
                                    <AppBadge v-if="doc.categoryName" color="sky">{{ doc.categoryName }}</AppBadge>
                                    <AppBadge v-if="doc.folderName" color="slate">
                                        <Folder class="w-3 h-3" :stroke-width="2" /> {{ doc.folderName }}
                                    </AppBadge>
                                </div>
                                <p v-if="doc.description" class="text-xs text-secondary line-clamp-1 mt-0.5">{{ doc.description }}</p>
                                <DocumentFamilyChips
                                    v-if="familyMembers(doc).length"
                                    class="mt-1"
                                    :model-value="previewed[doc.id] ?? doc.id"
                                    :members="familyMembers(doc)"
                                    v-on:choose="(id) => pickMember(doc, id)"
                                />
                                <!-- The stored name is 32 random characters with no
                                     space to break at: on a phone it ran out of
                                     the card and pushed the list sideways. It
                                     is cut short; the size and type stay whole. -->
                                <p class="flex min-w-0 text-xs text-muted mt-0.5">
                                    <span class="truncate">{{ doc.fileName }}</span>
                                    <span v-if="doc.fileSize" class="shrink-0 whitespace-nowrap">&nbsp;· {{ formatSize(doc.fileSize) }}</span>
                                    <span v-if="doc.fileMime" class="shrink-0 whitespace-nowrap max-sm:hidden">&nbsp;· {{ doc.fileMime }}</span>
                                </p>
                            </div>
                            <Check v-if="isFamilySelected(doc)" class="w-5 h-5 text-accent shrink-0" :stroke-width="2.5" />
                        </li>
                    </ul>
                    <AppNoData v-else-if="!loading" :message="t('suite.ged.documents.picker_empty')" />
                    <AppLoader :active="loading" />
                </div>

                <AppPagination v-if="totalPages > 1" :page="page" :total-pages="totalPages" v-on:change="goToPage" />
            </template>
        </div>

        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="emit('close')">
                    <X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    :disabled="confirmDisabled"
                    :loading="importing"
                    v-on:click="confirm"
                >
                    <Check class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("suite.ged.documents.picker_confirm") }}
                </AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
