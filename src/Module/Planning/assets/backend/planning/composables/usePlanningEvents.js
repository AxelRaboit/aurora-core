import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { fromDisplay } from "./displayZone.js";
import { useSaveFlow } from "./useSaveFlow.js";

/**
 * The open event, and every write the screen makes to one.
 *
 * `convention_sfc_thin_presentation` puts requests and state machines outside the
 * `.vue` file, and the scope question is a state machine by any reading: it holds
 * a pending write, waits for an answer, then runs it. Saving, deleting and
 * dragging all pause on the same question, which is why they are one composable
 * and not three - splitting them would mean three copies of the pause.
 *
 * @param {object} props            the screen's route templates
 * @param {object} deps
 * @param {() => Promise<void>} deps.load      refetch the visible range
 * @param {import("vue").Ref<string>} deps.zone  the zone the grids are drawn in
 * @param {import("vue").Ref<boolean>} deps.canCreate
 * @param {() => void} deps.onOpen  called before a modal opens, to clear the way
 */
export function usePlanningEvents(props, { load, zone, canCreate, onOpen }) {
    const { request } = useRequest();
    const { saving, errors, submit } = useSaveFlow(request);

    const openEvent = ref(null);
    const editing = ref(false);

    /**
     * A write to a series, held while the reader is asked what it applies to.
     *
     * One ref rather than three, so a pending question cannot be half-formed:
     * either something is waiting on an answer or nothing is.
     */
    const pendingScope = ref(null);

    /**
     * Whether an appearance belongs to a series and therefore needs the question.
     *
     * `occurrenceAt` is what makes it a generated appearance rather than a row. A
     * detached occurrence has a master but no rule of its own, so it is a single
     * event and asking would offer three answers to a question with one.
     */
    function needsScope(event) {
        return Boolean(event?.recurring && event?.occurrenceAt);
    }

    function askScope(kind, intent, payload) {
        pendingScope.value = { kind, intent, payload };
    }

    function cancelScope() {
        pendingScope.value = null;
    }

    /**
     * Runs the held write, now that the scope is known.
     *
     * The scope and the occurrence travel in the same body as the fields, which is
     * why all three writes take them the same way: the server resolves which row
     * to touch, and nothing here has to know whether a row was detached or a
     * series split.
     */
    async function confirmScope(scope) {
        const pending = pendingScope.value;
        pendingScope.value = null;

        if (null === pending) {
            return;
        }

        const scoped = { ...pending.payload, scope };

        if ("save" === pending.kind) {
            await sendSave(scoped);
        } else if ("delete" === pending.kind) {
            await sendDelete(pending.payload.id, scoped);
        } else {
            await sendMove(pending.payload.id, scoped);
        }
    }

    /**
     * Turns a draft the grid built into real instants.
     *
     * The grid's columns are display days, so a click on one names a wall clock in
     * the display zone rather than a moment. Converted here and in the drag below,
     * which are the only two places a shifted value would otherwise be sent.
     */
    function realSpan(draft) {
        if (!draft.startAt) {
            return draft;
        }

        return {
            ...draft,
            startAt: fromDisplay(new Date(draft.startAt), zone.value),
            endAt: draft.endAt
                ? fromDisplay(new Date(draft.endAt), zone.value)
                : draft.endAt,
        };
    }

    function viewEvent(event) {
        openEvent.value = event;
        editing.value = false;
        errors.value = {};
    }

    function create(draft = {}) {
        // The same gate the button carries. A click on the grid is a second way
        // in, and without this it would open a form whose save is refused - or one
        // with no calendar to put the event in.
        if (!canCreate.value) {
            return;
        }

        // An empty object rather than null: the modal opens on `event !== null`,
        // and a new event has no id yet. A click on the grid hands in a draft with
        // its two instants, so the form opens already on the day pointed at.
        openEvent.value = realSpan(draft);
        editing.value = true;
        errors.value = {};
        onOpen();
    }

    function close() {
        openEvent.value = null;
        editing.value = false;
    }

    async function save(form) {
        if (needsScope(openEvent.value)) {
            askScope("save", "edit", {
                ...form,
                occurrenceAt:
                    openEvent.value.realOccurrenceAt ??
                    openEvent.value.occurrenceAt,
            });

            return;
        }

        await sendSave(form);
    }

    async function sendSave(form) {
        const id = openEvent.value?.id;
        const path = id
            ? props.updateEventPathTemplate.replace("__id__", String(id))
            : props.createEventPath;

        await submit(path, form, async () => {
            close();
            await load();
        });
    }

    /**
     * Saves a drag or a resize.
     *
     * Its own route rather than the update endpoint, because the grid holds a
     * serialised event and not an input: `colourSlot` comes down resolved, so
     * echoing it back would turn an event that follows its calendar into one with
     * a colour of its own.
     *
     * Reloads afterwards rather than trusting the local move: the span the server
     * accepted is the one that counts, and an alert that followed the event has
     * moved too.
     */
    async function moveEvent(moved) {
        // The grid worked in display wall clocks, so the span comes back in them;
        // and `occurrenceAt` has to be the real instant, because the server
        // matches it against a date a rule produced.
        const span = realSpan({ startAt: moved.startAt, endAt: moved.endAt });

        if (needsScope(moved.event)) {
            askScope("move", "edit", {
                id: moved.id,
                ...span,
                occurrenceAt:
                    moved.event.realOccurrenceAt ?? moved.event.occurrenceAt,
            });

            return;
        }

        await sendMove(moved.id, span);
    }

    async function sendMove(id, body) {
        const data = await request(
            props.moveEventPathTemplate.replace("__id__", String(id)),
            body,
        );
        if (!data) return;

        await load();
    }

    /**
     * Answering an invitation.
     *
     * Reloads and reopens rather than patching the badge in place: the answer
     * changes what every grid draws for that event, and the response carries the
     * whole event back anyway.
     */
    async function respond({ event, status }) {
        const data = await request(
            props.respondEventPathTemplate.replace("__id__", String(event.id)),
            { status },
        );
        if (!data) return;

        openEvent.value = data.event;
        await load();
    }

    async function remove(event) {
        if (needsScope(event)) {
            askScope("delete", "delete", {
                id: event.id,
                occurrenceAt: event.realOccurrenceAt ?? event.occurrenceAt,
            });

            return;
        }

        await sendDelete(event.id, {});
    }

    async function sendDelete(id, body) {
        const data = await request(
            props.deleteEventPathTemplate.replace("__id__", String(id)),
            body,
        );
        if (!data) return;

        close();
        await load();
    }

    return {
        openEvent,
        editing,
        saving,
        errors,
        pendingScope,
        viewEvent,
        create,
        close,
        save,
        remove,
        moveEvent,
        respond,
        cancelScope,
        confirmScope,
    };
}
