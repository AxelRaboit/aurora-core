import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { required } from "@/shared/utils/validation/validators.js";
import { monthGrid } from "@/shared/composables/calendar/monthGrid.js";
import { useSpaceContentItemForm } from "./useSpaceContentItemForm.js";
import { useSpaceThread } from "./useSpaceThread.js";
import { useSpaceAttachments } from "./useSpaceAttachments.js";

/**
 * A space's content, and every write on it - once, for all three views.
 *
 * **One composable and not one per view.** The board, the list and the month
 * read the same rows and share the same form, the same thread and the same
 * drag; two copies of that state would be two copies to keep in agreement, and
 * the first thing to diverge would be what a card says after somebody edited it
 * in the view they were not looking at.
 *
 * The server always answers a write with the whole content, and this replaces
 * its copy with what comes back rather than patching the row it changed. A drag
 * renumbers a column, deleting a step renumbers the rest, and a page that
 * reconciled those itself would drift within three gestures.
 *
 * What stays in a view is what only that view has: which month the calendar is
 * showing is nobody else's business.
 */
const DAY_MS = 86400000;

/** A `Y-m-d\TH:i` wall clock, moved by whole days, staying at its hour. */
function shiftLocalDays(local, days) {
    const [date, time] = local.split("T");
    const [year, month, day] = date.split("-").map(Number);

    // Built and read in UTC so a daylight-saving boundary cannot turn "+1 day"
    // into "+23 hours" and land the card on the day it started.
    const moved = new Date(Date.UTC(year, month - 1, day));
    moved.setUTCDate(moved.getUTCDate() + days);

    const shifted = [
        moved.getUTCFullYear(),
        String(moved.getUTCMonth() + 1).padStart(2, "0"),
        String(moved.getUTCDate()).padStart(2, "0"),
    ].join("-");

    return `${shifted}T${time}`;
}

export function useSpaceContent(initial, paths) {
    const { t } = useI18n();
    const { request } = useRequest();

    const columns = ref(initial.columns ?? []);
    const items = ref(initial.items ?? []);
    const comments = ref(initial.comments ?? {});
    const attachments = ref(initial.attachments ?? {});

    function applyContent(data) {
        if (Array.isArray(data?.columns)) columns.value = data.columns;
        if (Array.isArray(data?.items)) items.value = data.items;
        if (data?.comments) comments.value = data.comments;
        if (data?.attachments) attachments.value = data.attachments;
    }

    // --- what every view reads ------------------------------------------

    const isEmpty = computed(() => items.value.length === 0);

    const columnOptions = computed(() =>
        columns.value.map((column) => ({
            value: String(column.id),
            label: column.name,
        })),
    );

    const columnsById = computed(
        () => new Map(columns.value.map((column) => [column.id, column])),
    );

    const itemsById = computed(
        () => new Map(items.value.map((item) => [item.id, item])),
    );

    /** The cards of each step, in their stored order. Read by the board and the list. */
    const grouped = computed(() =>
        columns.value.map((column) => ({
            column,
            cards: items.value
                .filter((item) => item.columnId === column.id)
                .sort((a, b) => a.position - b.position),
        })),
    );

    const unscheduled = computed(() =>
        items.value.filter((item) => !item.scheduledAt),
    );

    /**
     * The scheduled cards, as the shared month grid's events.
     *
     * `endAt` equals `startAt`: a publication is a moment, not a span, and the
     * grid refuses an end before a start while accepting one equal to it.
     *
     * The colour is the step's and falls back to the space's: inside one space
     * every card belongs to the same client, so what a reader scans a month for
     * is what still needs approving.
     *
     * **Une carte décochée n'est ni ici ni dans le rail.** Elle a une date,
     * donc elle n'attend pas d'être placée ; elle a juste une échéance qui ne
     * regarde pas le calendrier. La faire retomber parmi le travail à placer
     * aurait redemandé chaque jour de la dater.
     */
    const events = computed(() =>
        items.value
            .filter((item) => item.scheduledAt && false !== item.showOnCalendar)
            .map((item) => ({
                id: item.id,
                title: item.title,
                startAt: item.scheduledAt,
                endAt: item.scheduledAt,
                allDay: false,
                colourSlot:
                    columnsById.value.get(item.columnId)?.colourSlot ??
                    paths.colourSlot,
                readOnly: false,
            })),
    );

    function cellsFor(year, month) {
        return monthGrid(year, month);
    }

    // --- the card form and the thread, shared by all three ---------------

    const form = useSpaceContentItemForm(paths, applyContent, (id) => {
        items.value = items.value.filter((item) => item.id !== id);
    });

    const thread = useSpaceThread(comments, paths, applyContent);

    const files = useSpaceAttachments(attachments, paths, applyContent);

    const defaultColumnId = computed(() => columns.value[0]?.id ?? "");

    /** Clicking an empty day starts a card already dated to it. */
    function addOn(draft) {
        form.openItemCreate({
            columnId: defaultColumnId.value,
            scheduledAt: String(draft?.startAt ?? "").slice(0, 16),
        });
    }

    function openEvent(event) {
        const item = itemsById.value.get(event.id);
        if (item) form.openItemEdit(item);
    }

    // --- moving a card ---------------------------------------------------

    /**
     * Writes the order of one step after a drag.
     *
     * The step the cards landed in and the ids in their new order is everything
     * the server needs: it sets each card's step and its place in one pass.
     */
    async function reorderItems(columnId, cards) {
        // Moved optimistically so the card does not snap back while the request
        // is in flight; the server's answer replaces this a moment later.
        cards.forEach((card, index) => {
            const local = items.value.find((item) => item.id === card.id);
            if (local) {
                local.columnId = columnId;
                local.position = index;
            }
        });

        applyContent(
            await request(paths.itemReorderPath, {
                columnId,
                itemIds: cards.map((card) => card.id),
            }),
        );
    }

    /** Writes one card's date, or clears it. */
    async function schedule(id, scheduledAt) {
        applyContent(
            await request(buildPath(paths.schedulePath, { id }), {
                scheduledAt,
            }),
        );
    }

    /**
     * A card dragged across the month.
     *
     * What is sent is the card's own wall clock moved by the same number of
     * days, not the instant the grid computed: going through the instant would
     * mean converting into the space's zone and back on every drag, and the
     * hour a client was promised is not a value to convert twice.
     */
    async function moveEvent(moved) {
        const item = itemsById.value.get(moved.id);
        if (!item?.scheduledAtLocal) return;

        const days = Math.round(
            (new Date(moved.startAt) - new Date(item.scheduledAt)) / DAY_MS,
        );
        if (0 === days) return;

        await schedule(item.id, shiftLocalDays(item.scheduledAtLocal, days));
    }

    // --- the steps -------------------------------------------------------

    const showColumnForm = ref(false);
    const editingColumn = ref(null);
    const columnForm = ref({
        name: "",
        colourSlot: null,
        visibleToClient: true,
    });

    const {
        errors: columnErrors,
        loading: columnLoading,
        submit: submitColumn,
        clearErrors: clearColumnErrors,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(
                    t(
                        "backend.studio.space_content.errors.column_name_required",
                    ),
                )(columnForm.value.name),
        }),
        url: () =>
            editingColumn.value
                ? buildPath(paths.columnUpdatePath, {
                      id: editingColumn.value.id,
                  })
                : paths.columnCreatePath,
        body: () => columnForm.value,
        onSuccess: (data) => {
            showColumnForm.value = false;
            toast.success(
                t(
                    editingColumn.value
                        ? "backend.studio.space_content.column_updated"
                        : "backend.studio.space_content.column_created",
                ),
            );
            applyContent(data);
        },
    });

    function openColumnCreate() {
        editingColumn.value = null;
        columnForm.value = {
            name: "",
            colourSlot: null,
            visibleToClient: true,
        };
        clearColumnErrors();
        showColumnForm.value = true;
    }

    function openColumnEdit(column) {
        editingColumn.value = column;
        columnForm.value = {
            name: column.name,
            colourSlot: column.colourSlot ?? null,
            visibleToClient: false !== column.visibleToClient,
        };
        clearColumnErrors();
        showColumnForm.value = true;
    }

    // A step holding cards, or the last one, is refused by the server with a
    // sentence; `useDelete` shows it as it came. Nothing here has to know the
    // two rules, which is why they are stated once, in the Manager.
    const {
        pendingDelete: pendingColumnDelete,
        loading: columnDeleteLoading,
        confirm: confirmColumnDelete,
        submit: deleteColumn,
    } = useDelete(
        paths.columnDeletePath,
        (id) => {
            columns.value = columns.value.filter((column) => column.id !== id);
        },
        "backend.studio.space_content.column_deleted",
    );

    return {
        columns,
        items,
        comments,
        attachments,
        isEmpty,
        grouped,
        unscheduled,
        events,
        cellsFor,
        columnOptions,
        columnsById,
        reorderItems,
        schedule,
        moveEvent,
        openEvent,
        addOn,
        showColumnForm,
        editingColumn,
        columnForm,
        columnErrors,
        columnLoading,
        openColumnCreate,
        openColumnEdit,
        submitColumn,
        pendingColumnDelete,
        columnDeleteLoading,
        confirmColumnDelete,
        deleteColumn,
        ...form,
        ...thread,
        ...files,
    };
}
