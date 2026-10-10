import { describe, it, expect, vi, beforeEach } from "vitest";
import { ref } from "vue";
import { mount, flushPromises } from "@vue/test-utils";

const toast = { success: vi.fn(), error: vi.fn() };
let answer = { success: true };
const sent = [];

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast }));
vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({
        loading: ref(false),
        request: (url, body) => {
            sent.push({ url, body });

            return Promise.resolve(answer);
        },
    }),
}));

const { useProspectPipeline, stageTotals } =
    await import("./useProspectPipeline.js");

const STAGES = [
    { id: 1, name: "Nouveau", role: null },
    { id: 2, name: "Contacté", role: null },
    { id: 3, name: "Gagné", role: "won" },
    { id: 4, name: "Perdu", role: "lost" },
];

function board() {
    return {
        stages: STAGES,
        columns: [
            { stageId: 1, customerIds: [10, 11] },
            { stageId: 2, customerIds: [12] },
            { stageId: 3, customerIds: [] },
            { stageId: 4, customerIds: [] },
        ],
        lastInteractions: { 12: "2026-10-08T17:15:00+00:00" },
        outcomeWindowDays: 30,
    };
}

const CUSTOMERS = [
    {
        id: 10,
        legalName: "Garage Moreau",
        status: "prospect",
        estimatedValueCents: 90000,
        estimatedValueCurrency: "EUR",
    },
    {
        id: 11,
        legalName: "Boulangerie Lemoine",
        status: "prospect",
        estimatedValueCents: null,
    },
    {
        id: 12,
        legalName: "Menuiserie Fabre",
        status: "prospect",
        estimatedValueCents: 250000,
        estimatedValueCurrency: "EUR",
    },
];

const PATHS = {
    movePath: "/pipeline/move",
    stageCreatePath: "/pipeline/stages/create",
    stageUpdatePath: "/pipeline/stages/__id__/update",
    stageDeletePath: "/pipeline/stages/__id__/delete",
    stageReorderPath: "/pipeline/stages/reorder",
};

function pipeline() {
    const asked = [];
    const applied = [];
    let api;
    mount({
        setup() {
            api = useProspectPipeline({
                initialBoard: board(),
                paths: PATHS,
                customers: ref(CUSTOMERS),
                applyResult: (data) => applied.push(data),
                askConversion: (customer) => asked.push(customer.id),
            });

            return {};
        },
        template: "<i />",
    });

    return { api, asked, applied };
}

const cards = (...ids) => ids.map((id) => ({ id }));
const column = (api, stageId) =>
    api.board.value.columns.find((entry) => entry.stageId === stageId)
        .customerIds;

beforeEach(() => {
    sent.length = 0;
    answer = { success: true };
    toast.success.mockClear();
    toast.error.mockClear();
});

describe("useProspectPipeline", () => {
    it("draws each stage with the list's own rows, filtered like the list", () => {
        const { api } = pipeline();

        const groups = api.grouped((customer) => customer.id !== 11);

        expect(groups.map((group) => group.stage.id)).toEqual([1, 2, 3, 4]);
        expect(groups[0].cards.map((card) => card.legalName)).toEqual([
            "Garage Moreau",
        ]);
        expect(api.lastInteractionOf({ id: 12 })).toBe(
            "2026-10-08T17:15:00+00:00",
        );
    });

    it("sends a card that moves to a stage in progress, with the stage's whole order", async () => {
        const { api } = pipeline();

        api.onStageChange(2, cards(10, 12));
        await flushPromises();

        expect(sent).toEqual([
            {
                url: "/pipeline/move",
                body: { stageId: 2, customerIds: [10, 12], lostReason: null },
            },
        ]);
        expect(column(api, 2)).toEqual([10, 12]);
        expect(column(api, 1)).toEqual([11]);
    });

    it("does not send the stage a card left: the stage it entered carries the move", () => {
        const { api } = pipeline();

        api.onStageChange(1, cards(11));

        expect(sent).toEqual([]);
    });

    it("hands a prospect dropped on won to the conversion instead of moving it", () => {
        const { api, asked } = pipeline();

        api.onStageChange(3, cards(10));

        expect(asked).toEqual([10]);
        expect(sent).toEqual([]);
        // Held where it was dropped while the question is open...
        expect(column(api, 3)).toEqual([10]);

        api.cancelConversion();

        // ...and back where it came from when the question is cancelled.
        expect(column(api, 3)).toEqual([]);
        expect(column(api, 1)).toEqual([10, 11]);
    });

    it("asks why a deal was lost, and sends the reason with the move", async () => {
        const { api } = pipeline();

        api.onStageChange(4, cards(12));

        expect(sent).toEqual([]);
        expect(api.pendingLoss.value.customer.id).toBe(12);

        api.lostReason.value = "Budget";
        await api.confirmLoss();

        expect(sent[0].body).toEqual({
            stageId: 4,
            customerIds: [12],
            lostReason: "Budget",
        });
        expect(api.pendingLoss.value).toBeNull();
    });

    it("puts the card back when the loss is cancelled", () => {
        const { api } = pipeline();

        api.onStageChange(4, cards(12));
        api.cancelLoss();

        expect(column(api, 4)).toEqual([]);
        expect(column(api, 2)).toEqual([12]);
    });

    it("puts the board back and says why when the server refuses a move", async () => {
        answer = { success: false, errors: { stage: "Refusé." } };
        const { api } = pipeline();

        api.onStageChange(2, cards(10, 12));
        await flushPromises();

        expect(toast.error).toHaveBeenCalledWith("Refusé.");
        expect(column(api, 1)).toEqual([10, 11]);
    });

    it("takes the server's board and rows from a successful answer", async () => {
        const next = board();
        next.columns[1].customerIds = [10, 12];
        next.columns[0].customerIds = [11];
        answer = { success: true, customers: CUSTOMERS, pipeline: next };
        const { api, applied } = pipeline();

        api.onStageChange(2, cards(10, 12));
        await flushPromises();

        expect(applied).toHaveLength(1);
        expect(column(api, 2)).toEqual([10, 12]);
    });

    it("refuses a stage without a name before asking the server", async () => {
        const { api } = pipeline();

        api.openStageCreate();
        await api.saveStage();

        expect(sent).toEqual([]);
        expect(api.stageErrors.value.name).toBe(
            "suite.studio.pipeline.errors.stage_name_required",
        );
    });

    it("sends an edited stage to its own address", async () => {
        const { api } = pipeline();

        api.openStageEdit(STAGES[1]);
        api.stageForm.value = { ...api.stageForm.value, name: "Rendez-vous" };
        await api.saveStage();

        expect(sent[0]).toEqual({
            url: "/pipeline/stages/2/update",
            body: { name: "Rendez-vous", colourSlot: undefined, role: "" },
        });
        expect(api.stageForm.value).toBeNull();
    });
});

describe("stageTotals", () => {
    it("sums the estimates per currency and skips the cards without one", () => {
        expect(
            stageTotals([
                { estimatedValueCents: 90000, estimatedValueCurrency: "EUR" },
                { estimatedValueCents: null },
                { estimatedValueCents: 10000, estimatedValueCurrency: "USD" },
                { estimatedValueCents: 250000, estimatedValueCurrency: "EUR" },
            ]),
        ).toEqual([
            { currency: "EUR", cents: 340000 },
            { currency: "USD", cents: 10000 },
        ]);
    });
});
