import { computed, ref } from "vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { monthGrid } from "@/shared/composables/calendar/monthGrid.js";
import { useSpaceContentItemForm } from "./useSpaceContentItemForm.js";
import { useSpaceThread } from "./useSpaceThread.js";

/**
 * The same content, read by its date.
 *
 * It owns no list. `items` is the space's content, exactly what the board
 * holds, and this turns the scheduled ones into the shape the shared month grid
 * draws. Nothing here knows about steps except to name the one a card is on.
 *
 * The ones with no date are not missing from the calendar, they are its
 * waiting room: they sit in a rail beside the month, which is where the work
 * that has not been scheduled yet belongs.
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

export function useSpaceCalendar(initialItems, initialColumns, paths) {
    const { request } = useRequest();

    const items = ref(initialItems ?? []);
    const columns = ref(initialColumns ?? []);

    const today = new Date();
    const year = ref(today.getFullYear());
    const month = ref(today.getMonth());

    const cells = computed(() => monthGrid(year.value, month.value));

    const columnOptions = computed(() =>
        columns.value.map((column) => ({
            value: String(column.id),
            label: column.name,
        })),
    );

    const columnNames = computed(
        () => new Map(columns.value.map((column) => [column.id, column.name])),
    );

    const columnColours = computed(
        () =>
            new Map(
                columns.value.map((column) => [column.id, column.colourSlot]),
            ),
    );

    /**
     * The scheduled cards, as the grid's events.
     *
     * `endAt` equals `startAt`: a publication is a moment, not a span, and the
     * grid refuses an end before a start while accepting one equal to it.
     *
     * **The colour is the step's, and falls back to the space's.** Inside one
     * space every card belongs to the same client, so the space's colour says
     * nothing here - what a reader scans a month for is what still needs
     * approving. On the team's shared agenda the question is the opposite one,
     * which client a date belongs to, and that calendar keeps the space's
     * colour: two surfaces, two questions, and the same rows.
     */
    const events = computed(() =>
        items.value
            .filter((item) => item.scheduledAt)
            .map((item) => ({
                id: item.id,
                title: item.title,
                startAt: item.scheduledAt,
                endAt: item.scheduledAt,
                allDay: false,
                colourSlot:
                    columnColours.value.get(item.columnId) ?? paths.colourSlot,
                readOnly: false,
                columnName: columnNames.value.get(item.columnId) ?? "",
            })),
    );

    const unscheduled = computed(() =>
        items.value.filter((item) => !item.scheduledAt),
    );

    const itemsById = computed(
        () => new Map(items.value.map((item) => [item.id, item])),
    );

    const comments = ref(paths.comments ?? {});

    function applyBoard(data) {
        if (Array.isArray(data?.columns)) columns.value = data.columns;
        if (Array.isArray(data?.items)) items.value = data.items;
        if (data?.comments) comments.value = data.comments;
    }

    const form = useSpaceContentItemForm(paths, applyBoard, (id) => {
        items.value = items.value.filter((item) => item.id !== id);
    });

    /** Writes one card's date, or clears it. */
    async function schedule(id, scheduledAt) {
        const data = await request(buildPath(paths.schedulePath, { id }), {
            scheduledAt,
        });
        applyBoard(data);
    }

    /**
     * A card dragged across the month.
     *
     * The grid hands back a shifted instant; what is sent is the card's own
     * wall clock moved by the same number of days. Going through the instant
     * would mean converting to the space's zone and back on every drag, and the
     * hour a client was promised is not a thing to round-trip twice.
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

    function openEvent(event) {
        const item = itemsById.value.get(event.id);
        if (item) form.openItemEdit(item);
    }

    /** The first step, which is where a card created from a day belongs. */
    const defaultColumnId = computed(() => columns.value[0]?.id ?? "");

    /** Clicking an empty day starts a card already dated to it. */
    function addOn(draft) {
        form.openItemCreate({
            columnId: defaultColumnId.value,
            scheduledAt: String(draft?.startAt ?? "").slice(0, 16),
        });
    }

    const monthLabel = computed(() => new Date(year.value, month.value, 1));

    function goToMonth(delta) {
        const moved = new Date(year.value, month.value + delta, 1);
        year.value = moved.getFullYear();
        month.value = moved.getMonth();
    }

    function goToToday() {
        const now = new Date();
        year.value = now.getFullYear();
        month.value = now.getMonth();
    }

    const { commentLoading, threadOf, postComment, deleteComment } =
        useSpaceThread(comments, paths, applyBoard);

    return {
        comments,
        commentLoading,
        threadOf,
        postComment,
        deleteComment,
        items,
        cells,
        events,
        unscheduled,
        columnOptions,
        columnNames,
        monthLabel,
        goToMonth,
        goToToday,
        moveEvent,
        openEvent,
        addOn,
        schedule,
        ...form,
    };
}
