<script setup>
/**
 * The sections a grid can take in one click: those the library ships with,
 * and those the author saved.
 *
 * Each is drawn as the rows it makes - the widths of its zones, to scale -
 * because an arrangement is recognised by its shape before its name. The
 * author's own come from the server when the window opens, so a section
 * saved in a deliverable is there in the next publication.
 */
import { computed, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { LayoutTemplate, Plus, Trash2 } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { SECTION_PATTERNS } from "../composables/gridSectionPatterns.js";

const props = defineProps({
    show: { type: Boolean, default: false },
});

const emit = defineEmits(["close", "insert"]);

const { t } = useI18n();
const { request } = useRequest();

const tab = ref("builtin");
const mine = ref([]);
const loading = ref(false);

const COLUMNS = 48;

/** What the open tab is for. */
const intro = computed(() =>
    t(tab.value === "builtin" ? "suite.posts.grid.sections.intro" : "suite.posts.grid.sections.intro_mine"),
);

/** A saved section's rows, worked out from its zones the way the grid flows them. */
function rowsOf(zones) {
    const rows = [];
    let used = COLUMNS;

    for (const zone of zones ?? []) {
        const width = zone.span?.lg ?? zone.span?.md ?? COLUMNS;
        if (zone.newRow || used + width > COLUMNS) {
            rows.push([]);
            used = 0;
        }
        rows.at(-1).push(width);
        used += width;
    }

    return rows;
}

const builtin = computed(() =>
    SECTION_PATTERNS.map((pattern) => ({
        key: pattern.key,
        name: t(`suite.posts.grid.sections.patterns.${pattern.key}.name`),
        description: t(`suite.posts.grid.sections.patterns.${pattern.key}.description`),
        rows: pattern.rows,
        build: () => pattern.build(t),
    })),
);

async function loadMine() {
    loading.value = true;
    try {
        const data = await request("/suite/grid-sections", null, { method: "GET", silent: true });
        mine.value = data?.sections ?? [];
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.show,
    (open) => {
        if (open) loadMine();
    },
);

function insertBuiltin(pattern) {
    emit("insert", pattern.build());
}

function insertMine(section) {
    emit("insert", { zones: section.zones, content: section.content });
}

async function remove(section) {
    const data = await request(`/suite/grid-sections/${section.id}/delete`);
    if (data?.success) mine.value = data.sections ?? [];
}
</script>

<template>
    <AppModal
        :show="show"
        max-width="4xl"
        :title="t('suite.posts.grid.sections.title')"
        :icon="LayoutTemplate"
        v-on:close="emit('close')"
    >
        <div class="space-y-4">
            <div class="flex gap-1 border-b border-line" role="tablist">
                <AppTab
                    variant="underline"
                    role="tab"
                    :aria-selected="'builtin' === tab ? 'true' : 'false'"
                    :active="'builtin' === tab"
                    v-on:click="tab = 'builtin'"
                >
                    {{ t("suite.posts.grid.sections.builtin") }}
                </AppTab>
                <AppTab
                    variant="underline"
                    role="tab"
                    :aria-selected="'mine' === tab ? 'true' : 'false'"
                    :active="'mine' === tab"
                    v-on:click="tab = 'mine'"
                >
                    {{ t("suite.posts.grid.sections.mine") }}
                </AppTab>
            </div>

            <p class="text-sm text-secondary">
                {{ intro }}
            </p>

            <div v-if="'builtin' === tab" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="pattern in builtin"
                    :key="pattern.key"
                    type="button"
                    class="flex flex-col gap-2 rounded-lg border border-line bg-surface p-3 text-left transition-colors hover:border-accent"
                    v-on:click="insertBuiltin(pattern)"
                >
                    <span class="flex flex-col gap-1 rounded-md bg-surface-2 p-2" aria-hidden="true">
                        <span v-for="(row, rowIndex) in pattern.rows" :key="rowIndex" class="flex gap-1">
                            <span
                                v-for="(width, cell) in row"
                                :key="cell"
                                class="h-5 rounded-sm border border-line bg-surface"
                                :style="{ flexGrow: width, flexBasis: 0 }"
                            />
                        </span>
                    </span>
                    <span class="text-sm font-medium text-primary">{{ pattern.name }}</span>
                    <span class="text-xs text-muted">{{ pattern.description }}</span>
                </button>
            </div>

            <template v-else>
                <p v-if="!loading && !mine.length" class="rounded-md bg-surface-2 p-4 text-sm text-muted">
                    {{ t("suite.posts.grid.sections.empty_mine") }}
                </p>
                <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="section in mine"
                        :key="section.id"
                        class="flex flex-col gap-2 rounded-lg border border-line bg-surface p-3"
                    >
                        <span class="flex flex-col gap-1 rounded-md bg-surface-2 p-2" aria-hidden="true">
                            <span v-for="(row, rowIndex) in rowsOf(section.zones)" :key="rowIndex" class="flex gap-1">
                                <span
                                    v-for="(width, cell) in row"
                                    :key="cell"
                                    class="h-5 rounded-sm border border-line bg-surface"
                                    :style="{ flexGrow: width, flexBasis: 0 }"
                                />
                            </span>
                        </span>
                        <span class="flex items-center gap-2">
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-primary">{{ section.name }}</span>
                            <AppIconButton color="rose" :title="t('suite.posts.grid.sections.delete')" v-on:click="remove(section)">
                                <Trash2 class="h-4 w-4" :stroke-width="2" />
                            </AppIconButton>
                        </span>
                        <AppButton variant="ghost" size="sm" v-on:click="insertMine(section)">
                            <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.posts.grid.sections.insert") }}
                        </AppButton>
                    </div>
                </div>
            </template>
        </div>
    </AppModal>
</template>
