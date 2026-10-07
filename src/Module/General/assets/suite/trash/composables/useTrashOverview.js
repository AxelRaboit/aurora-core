import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { resolveNavIcon } from "@/shared/nav/navMeta.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * The state of the trash screen, and the three things it can do.
 *
 * It never restores anything itself: it posts to the address the module gave
 * it, which is the endpoint that module's own screen used to call. The rules
 * of a restore - a folder released at the root, a category given a free slug,
 * a note whose parent is still deleted - stay written once, where they belong.
 *
 * Every action is followed by re-reading the whole list rather than patching
 * the row that moved. One trash can change another: releasing a folder moves
 * the documents that were in it, and a screen that only fixed up the row it
 * touched would quietly disagree with the database.
 */
export function useTrashOverview(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const trashes = ref(props.trashes ?? []);
    const activeKey = ref(firstKey(trashes.value));
    const busyId = ref(null);
    const pendingForceDelete = ref(null);
    const pendingEmpty = ref(null);
    const emptying = ref(false);

    const rows = computed(() =>
        trashes.value.map((trash) => ({
            ...trash,
            label: t(trash.labelKey),
            // The module the trash belongs to, as the side menu names it.
            // Grouping by it is what keeps "Dossiers" unambiguous without
            // repeating "GED ·" in front of every tab.
            section: trash.sectionLabel || t(trash.labelKey),
            iconComponent: resolveNavIcon(trash.icon),
            daysLeft: daysLeft(trash.oldestDeletedAt, props.retentionDays),
        })),
    );

    /**
     * The trashes grouped by module, in the order the server sent them.
     *
     * Nine tabs on three lines, each repeating its module, read as a wall: a
     * first row of modules, then the types of the open one, says the same
     * thing in two short rows.
     */
    const modules = computed(() => {
        const bySection = new Map();
        for (const row of rows.value) {
            if (!bySection.has(row.section)) {
                bySection.set(row.section, {
                    key: row.section,
                    label: row.section,
                    count: 0,
                    rows: [],
                });
            }
            const group = bySection.get(row.section);
            group.rows.push(row);
            group.count += row.count;
        }

        return [...bySection.values()];
    });

    const active = computed(
        () => rows.value.find((row) => row.key === activeKey.value) ?? null,
    );

    const activeModule = computed(
        () =>
            modules.value.find(
                (group) => group.key === active.value?.section,
            ) ?? null,
    );

    /**
     * The modules worth a tab: those holding something, and the open one
     * even once emptied, so the reader is not moved off what they just
     * cleared.
     */
    const visibleModules = computed(() =>
        modules.value.filter(
            (group) => group.count > 0 || group.key === activeModule.value?.key,
        ),
    );

    /** Opens a module on its first type holding something. */
    function selectModule(key) {
        const group = modules.value.find((candidate) => candidate.key === key);
        if (!group) return;

        activeKey.value = firstKey(group.rows);
    }

    const total = computed(() =>
        rows.value.reduce((sum, row) => sum + row.count, 0),
    );

    function select(key) {
        activeKey.value = key;
    }

    async function reload() {
        const data = await request(props.listPath, null, {
            method: HttpMethod.Get,
            noGuard: true,
        });
        if (!data?.success) return;

        trashes.value = data.trashes ?? [];

        // The tab that was open may have emptied while a fuller one took its
        // place; keeping the reader on a tab that no longer exists would show
        // them nothing and look broken.
        if (!trashes.value.some((trash) => trash.key === activeKey.value)) {
            activeKey.value = firstKey(trashes.value);
        }
    }

    function pathFor(template, id) {
        return (template ?? "").replace("__id__", String(id));
    }

    async function restore(trash, item) {
        if (!trash.restorePath) return;

        busyId.value = item.id;
        const data = await request(
            pathFor(trash.restorePath, item.id),
            {},
            { noGuard: true },
        );
        busyId.value = null;
        if (data === null) return;

        if (!data.success) {
            toast.error(reasonFrom(data) ?? t("shared.common.error"));

            return;
        }

        toast.success(t("suite.trash.restored"));
        await reload();
    }

    function askForceDelete(trash, item) {
        pendingForceDelete.value = { trash, item };
    }

    async function doForceDelete() {
        const pending = pendingForceDelete.value;
        if (!pending?.trash.forceDeletePath) return;

        busyId.value = pending.item.id;
        const data = await request(
            pathFor(pending.trash.forceDeletePath, pending.item.id),
            {},
            { noGuard: true },
        );
        busyId.value = null;
        pendingForceDelete.value = null;
        if (data === null) return;

        if (!data.success) {
            toast.error(reasonFrom(data) ?? t("shared.common.error"));

            return;
        }

        toast.success(t("suite.trash.deleted_forever"));
        await reload();
    }

    function askEmpty(trash) {
        pendingEmpty.value = trash;
    }

    async function doEmpty() {
        const trash = pendingEmpty.value;
        if (!trash?.emptyTrashPath) return;

        emptying.value = true;
        const data = await request(trash.emptyTrashPath, {}, { noGuard: true });
        emptying.value = false;
        pendingEmpty.value = null;
        if (data === null) return;

        if (!data.success) {
            toast.error(reasonFrom(data) ?? t("shared.common.error"));

            return;
        }

        toast.success(t("suite.trash.emptied", { count: data.deleted ?? 0 }));
        await reload();
    }

    return {
        rows,
        modules,
        visibleModules,
        total,
        active,
        activeModule,
        activeKey,
        busyId,
        pendingForceDelete,
        pendingEmpty,
        emptying,
        select,
        selectModule,
        reload,
        restore,
        askForceDelete,
        doForceDelete,
        askEmpty,
        doEmpty,
    };
}

/**
 * The trash to open first: the first one holding something, otherwise the
 * first one at all. Opening on an empty trash while another one is full made
 * the reader hunt for the content.
 */
function firstKey(trashes) {
    return (
        (trashes.find((trash) => trash.count > 0) ?? trashes[0])?.key ?? null
    );
}

/**
 * A refusal written for a human, when the server sent one.
 *
 * The modules answer a rejected deletion as `{errors: {field: sentence}}`, and
 * that sentence is the only thing that says why.
 */
function reasonFrom(data) {
    return Object.values(data?.errors ?? {})[0];
}

/**
 * Whole days between now and the moment this trash is purged, or null when
 * nothing is waiting or the automatic purge is switched off.
 *
 * A negative result is possible and shown as zero: the purge runs nightly, so
 * between the expiry and 3 a.m. there are rows past their window still sitting
 * in the trash. Saying "in -1 day" would be a bug report waiting to happen.
 */
export function daysLeft(oldestDeletedAt, retentionDays) {
    if (!oldestDeletedAt || !retentionDays || retentionDays <= 0) return null;

    const deletedAt = new Date(oldestDeletedAt);
    if (Number.isNaN(deletedAt.getTime())) return null;

    const purgeAt = deletedAt.getTime() + retentionDays * 86_400_000;
    const remaining = Math.ceil((purgeAt - Date.now()) / 86_400_000);

    return Math.max(remaining, 0);
}
