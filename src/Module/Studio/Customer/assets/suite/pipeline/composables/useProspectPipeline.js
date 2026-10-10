import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * The prospect board: where each customer is drawn, and the gestures that
 * move them.
 *
 * **The board holds ids, the list holds customers.** The server sends the
 * stages and, per stage, the ids in order; the cards are the list's own rows,
 * looked up by id. A card is therefore never out of date with the row the
 * list shows for the same customer, and a search filters both alike.
 *
 * **Two drops are not moves.** A prospect dropped on the won stage is a
 * conversion, which asks for the address the contract goes to; one dropped
 * on the lost stage asks why. Both are held while the question is open: the
 * card stays where it was dropped, and goes back if the question is
 * cancelled. Everything else is sent at once.
 *
 * @param {object} options
 * @param {object} options.initialBoard   `{stages, columns, lastInteractions, outcomeWindowDays}`
 * @param {object} options.paths          the board's addresses (move, stage CRUD)
 * @param {import("vue").Ref<Array>} options.customers  the list's rows
 * @param {(data: object) => void} options.applyResult  takes a server answer's `customers`
 *        (and must not hand the answer back to `apply`)
 * @param {(customer: object) => void} options.askConversion opens the conversion of a prospect
 */
export function useProspectPipeline({
    initialBoard,
    paths,
    customers,
    applyResult,
    askConversion,
}) {
    const { t } = useI18n();
    const { request } = useRequest();

    /** What the server last said: the board falls back to it when a drop is cancelled. */
    const serverBoard = ref(initialBoard ?? emptyBoard());
    const board = ref(clone(serverBoard.value));
    const sending = ref(false);

    /** A drop on the lost stage, waiting for its reason: `{stageId, customerIds, customer}`. */
    const pendingLoss = ref(null);
    const lostReason = ref("");

    const stages = computed(() => board.value.stages ?? []);

    const customersById = computed(() => {
        const byId = new Map();
        for (const customer of customers.value) byId.set(customer.id, customer);

        return byId;
    });

    function stageById(stageId) {
        return stages.value.find((stage) => stage.id === stageId) ?? null;
    }

    function idsOf(stageId) {
        return (
            board.value.columns?.find((column) => column.stageId === stageId)
                ?.customerIds ?? []
        );
    }

    /**
     * The board as drawn: each stage with its cards, filtered by `visible`
     * (the list's search) when given.
     *
     * @param {(customer: object) => boolean} [visible]
     */
    function grouped(visible = null) {
        return stages.value.map((stage) => ({
            stage,
            cards: idsOf(stage.id)
                .map((id) => customersById.value.get(id))
                .filter(
                    (customer) => customer && (!visible || visible(customer)),
                ),
        }));
    }

    function lastInteractionOf(customer) {
        return board.value.lastInteractions?.[customer.id] ?? null;
    }

    /** Takes the board of a server answer, when it carries one. */
    function applyBoard(data) {
        if (!data?.pipeline) return;
        serverBoard.value = data.pipeline;
        board.value = clone(data.pipeline);
    }

    /** A whole answer: the list's rows, then the board. */
    function apply(data) {
        if (!data) return;
        applyResult(data);
        applyBoard(data);
    }

    function revert() {
        board.value = clone(serverBoard.value);
    }

    /** Draws a stage's new order at once, before the server confirms it. */
    function place(stageId, customerIds) {
        const moving = new Set(customerIds);
        board.value = {
            ...board.value,
            columns: (board.value.columns ?? []).map((column) =>
                column.stageId === stageId
                    ? { ...column, customerIds: [...customerIds] }
                    : {
                          ...column,
                          customerIds: column.customerIds.filter(
                              (id) => !moving.has(id),
                          ),
                      },
            ),
        };
    }

    async function send(stageId, customerIds, reason = null) {
        sending.value = true;
        try {
            const data = await request(
                paths.movePath,
                { stageId, customerIds, lostReason: reason },
                { noGuard: true },
            );
            if (!data) {
                revert();

                return false;
            }
            if (!data.success) {
                revert();
                toast.error(firstError(data) ?? t("shared.common.error"));

                return false;
            }
            apply(data);

            return true;
        } finally {
            sending.value = false;
        }
    }

    /**
     * A stage's list changed under the drag: `cards` is its new order.
     *
     * The stage a card left also reports a change, with the card missing;
     * that one is not sent - the stage it arrived in carries the move.
     */
    function onStageChange(stageId, cards) {
        const before = idsOf(stageId);
        const after = cards.map((card) => card.id);
        const arrived = after.filter((id) => !before.includes(id));

        if (!arrived.length && after.length < before.length) return;

        place(stageId, after);

        const stage = stageById(stageId);
        const customer = arrived.length
            ? customersById.value.get(arrived[0])
            : null;

        if (
            customer &&
            "won" === stage?.role &&
            "prospect" === customer.status
        ) {
            askConversion(customer);

            return;
        }

        if (customer && "lost" === stage?.role) {
            lostReason.value = customer.lostReason ?? "";
            pendingLoss.value = { stageId, customerIds: after, customer };

            return;
        }

        send(stageId, after);
    }

    /**
     * A card moved to another stage without dragging - the "…" menu on a
     * phone, or the stage select of a customer's page. Lands on top.
     */
    function moveTo(customer, stageId) {
        const ids = [
            customer.id,
            ...idsOf(stageId).filter((id) => id !== customer.id),
        ];
        onStageChange(
            stageId,
            ids.map((id) => ({ id })),
        );
    }

    async function confirmLoss() {
        if (!pendingLoss.value) return;
        const { stageId, customerIds } = pendingLoss.value;
        const ok = await send(stageId, customerIds, lostReason.value);
        if (ok) {
            pendingLoss.value = null;
            toast.success(t("suite.studio.pipeline.marked_lost"));
        }
    }

    function cancelLoss() {
        pendingLoss.value = null;
        revert();
    }

    /** The conversion was cancelled: the card goes back where it came from. */
    function cancelConversion() {
        revert();
    }

    async function reorderStages(stageIds) {
        board.value = {
            ...board.value,
            stages: stageIds.map((id) => stageById(id)).filter(Boolean),
        };
        const data = await request(
            paths.stageReorderPath,
            { stageIds },
            { noGuard: true },
        );
        if (data?.success) apply(data);
        else revert();
    }

    // ── Stages ───────────────────────────────────────────────────────────

    const stageForm = ref(null);
    const stageErrors = ref({});
    const stageSaving = ref(false);

    function openStageCreate() {
        stageErrors.value = {};
        stageForm.value = { id: null, name: "", colourSlot: null, role: "" };
    }

    function openStageEdit(stage) {
        stageErrors.value = {};
        stageForm.value = {
            id: stage.id,
            name: stage.name,
            colourSlot: stage.colourSlot,
            role: stage.role ?? "",
        };
    }

    function closeStageForm() {
        stageForm.value = null;
    }

    async function saveStage() {
        if (!stageForm.value || stageSaving.value) return;
        if (!stageForm.value.name.trim()) {
            stageErrors.value = {
                name: t("suite.studio.pipeline.errors.stage_name_required"),
            };

            return;
        }

        stageSaving.value = true;
        try {
            const url = stageForm.value.id
                ? buildPath(paths.stageUpdatePath, { id: stageForm.value.id })
                : paths.stageCreatePath;
            const { name, colourSlot, role } = stageForm.value;
            const data = await request(
                url,
                { name, colourSlot, role },
                { noGuard: true },
            );
            if (!data) return;
            if (!data.success) {
                stageErrors.value = data.errors ?? {};

                return;
            }
            apply(data);
            stageForm.value = null;
            toast.success(t("suite.studio.pipeline.stage_saved"));
        } finally {
            stageSaving.value = false;
        }
    }

    const pendingStageDelete = ref(null);
    const stageDeleting = ref(false);

    async function deleteStage() {
        if (!pendingStageDelete.value || stageDeleting.value) return;
        stageDeleting.value = true;
        try {
            const data = await request(
                buildPath(paths.stageDeletePath, {
                    id: pendingStageDelete.value.id,
                }),
                {},
                { noGuard: true },
            );
            if (!data) return;
            if (!data.success) {
                toast.error(firstError(data) ?? t("shared.common.error"));

                return;
            }
            apply(data);
            pendingStageDelete.value = null;
            toast.success(t("suite.studio.pipeline.stage_deleted"));
        } finally {
            stageDeleting.value = false;
        }
    }

    return {
        board,
        stages,
        grouped,
        lastInteractionOf,
        apply,
        applyBoard,
        sending,
        onStageChange,
        moveTo,
        reorderStages,
        pendingLoss,
        lostReason,
        confirmLoss,
        cancelLoss,
        cancelConversion,
        stageForm,
        stageErrors,
        stageSaving,
        openStageCreate,
        openStageEdit,
        closeStageForm,
        saveStage,
        pendingStageDelete,
        stageDeleting,
        deleteStage,
    };
}

function emptyBoard() {
    return {
        stages: [],
        columns: [],
        lastInteractions: {},
        outcomeWindowDays: 30,
    };
}

function clone(value) {
    return JSON.parse(JSON.stringify(value));
}

function firstError(data) {
    const errors = data?.errors ?? {};
    const first = Object.values(errors)[0];

    return "string" === typeof first ? first : null;
}

/**
 * What a stage is worth: its estimates summed per currency, since a dollar
 * and a euro do not add up.
 *
 * @param {Array<object>} cards
 * @returns {Array<{currency: string, cents: number}>}
 */
export function stageTotals(cards) {
    const totals = new Map();
    for (const card of cards) {
        if (
            null === card.estimatedValueCents ||
            undefined === card.estimatedValueCents
        )
            continue;
        const currency = card.estimatedValueCurrency ?? "EUR";
        totals.set(
            currency,
            (totals.get(currency) ?? 0) + card.estimatedValueCents,
        );
    }

    return [...totals].map(([currency, cents]) => ({ currency, cents }));
}
