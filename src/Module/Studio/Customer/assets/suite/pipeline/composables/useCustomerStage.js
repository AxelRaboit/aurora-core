import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * Changing a customer's stage from their page: the board's drop, without
 * the board.
 *
 * The same two exceptions as the board: won is a conversion (handed to
 * `askConversion`), lost asks why. The customer goes on top of the stage it
 * enters, the page not knowing the order of the others.
 *
 * @param {object} options
 * @param {string} options.movePath
 * @param {import("vue").Ref<object>} options.customer the saved sheet
 * @param {import("vue").Ref<Array>} options.stages
 * @param {() => void} options.askConversion
 * @param {(row: object) => void} options.onMoved told of the customer's row read again
 */
export function useCustomerStage({
    movePath,
    customer,
    stages,
    askConversion,
    onMoved,
}) {
    const { t } = useI18n();
    const { request } = useRequest();

    const moving = ref(false);
    const pendingLoss = ref(null);
    const lostReason = ref("");

    function change(stageId) {
        const stage = stages.value.find(
            (candidate) => candidate.id === stageId,
        );
        if (!stage || stage.id === customer.value.pipelineStageId) return;

        if ("won" === stage.role) {
            askConversion();

            return;
        }

        if ("lost" === stage.role) {
            lostReason.value = customer.value.lostReason ?? "";
            pendingLoss.value = stage;

            return;
        }

        send(stage.id);
    }

    async function send(stageId, reason = null) {
        moving.value = true;
        try {
            const data = await request(
                movePath,
                {
                    stageId,
                    customerIds: [customer.value.id],
                    lostReason: reason,
                },
                { noGuard: true },
            );
            if (!data) return false;
            if (!data.success) {
                toast.error(
                    Object.values(data.errors ?? {})[0] ??
                        t("shared.common.error"),
                );

                return false;
            }
            const row = (data.customers ?? []).find(
                (candidate) => candidate.id === customer.value.id,
            );
            if (row) onMoved(row);
            toast.success(t("suite.studio.pipeline.stage_changed"));

            return true;
        } finally {
            moving.value = false;
        }
    }

    async function confirmLoss() {
        if (!pendingLoss.value) return;
        if (await send(pendingLoss.value.id, lostReason.value))
            pendingLoss.value = null;
    }

    function cancelLoss() {
        pendingLoss.value = null;
    }

    return { moving, change, pendingLoss, lostReason, confirmLoss, cancelLoss };
}
