import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useRequest } from "@/shared/composables/http/frontend/useRequest.js";
import { usePublicCaptcha } from "./usePublicCaptcha.js";

/**
 * Mirrors FormConditionEvaluator on the PHP side.
 *
 * The two have to agree: this one decides what the visitor sees, the server's
 * decides what is validated and stored, and a field visible here but hidden
 * there is an answer that silently disappears. Keep them in step.
 */
function isVisible(field, answers) {
    if (!field.conditions?.length) return true;

    const met = field.conditions.filter((condition) => {
        const answer = answers[String(condition.fieldId)];

        return Array.isArray(answer)
            ? answer.map(String).includes(condition.value)
            : answer !== undefined &&
                  answer !== null &&
                  String(answer) === condition.value;
    }).length;

    return field.conditionsLogic === "or"
        ? met > 0
        : met === field.conditions.length;
}

function blankAnswers(fields) {
    return Object.fromEntries(
        fields.map((field) => [
            String(field.id),
            field.type === "checkbox" ? [] : "",
        ]),
    );
}

export function useFormRender(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    // The same check the comment thread uses, told apart by its action so the
    // provider scores a contact form and a comment separately.
    const captcha = usePublicCaptcha(props.captcha, "form");

    const answers = ref(blankAnswers(props.form.fields ?? []));
    const errors = ref({});
    const sending = ref(false);
    const sent = ref(false);
    const notice = ref(null);

    const visibleFields = computed(() =>
        (props.form.fields ?? []).filter((field) =>
            isVisible(field, answers.value),
        ),
    );

    const steps = computed(() => props.form.steps ?? null);
    const stepIndex = ref(0);

    /**
     * On a single-page form every visible field is on screen. On a multi-step
     * one, only the current step's - and fields with no step belong to the
     * first, so a form that gained steps after its fields still renders.
     */
    const fieldsForStep = computed(() => {
        if (!steps.value?.length) return visibleFields.value;

        return visibleFields.value.filter(
            (field) => (field.step ?? 1) === stepIndex.value + 1,
        );
    });

    const isLastStep = computed(
        () => !steps.value?.length || stepIndex.value >= steps.value.length - 1,
    );

    function goToStep(next) {
        if (!steps.value?.length) return;
        if (next < 0 || next >= steps.value.length) return;

        stepIndex.value = next;
    }

    async function submit() {
        if (sending.value) return;

        // L'aperçu du back-office monte ce même composant pour que l'auteur
        // voie exactement ce que verra le visiteur. Il ne doit rien envoyer :
        // une réponse de test arriverait dans la boîte et dans les exports.
        if (props.preview) {
            notice.value = {
                type: "info",
                text: t("frontend.editorial.forms.preview_notice"),
            };
            return;
        }

        sending.value = true;
        errors.value = {};
        notice.value = null;

        try {
            // Only the visible answers are sent. A field the visitor never saw
            // has no answer to give, and the server would discard it anyway.
            const payload = Object.fromEntries(
                visibleFields.value.map((field) => [
                    String(field.id),
                    answers.value[String(field.id)],
                ]),
            );

            // Beside the answers rather than among them: the server reads
            // answers by field id, and no field is named this.
            payload.captchaToken = await captcha.currentToken();

            const data = await request(props.submitPath, payload);
            if (!data) {
                captcha.reset();

                return;
            }

            if (!data.success) {
                // Spent either way - a provider hands out one token per
                // challenge, and retrying with it is refused.
                captcha.reset();

                errors.value = Object.fromEntries(
                    Object.entries(data.errors ?? {}).map(([field, key]) => [
                        field,
                        t(key),
                    ]),
                );
                if (data.error) {
                    notice.value = { type: "error", text: t(data.error) };
                }

                // Errors can be on a step the visitor has moved past - send
                // them back to the first one that has any, rather than
                // refusing to submit with nothing visible to fix.
                jumpToFirstError();

                return;
            }

            sent.value = true;
        } finally {
            sending.value = false;
        }
    }

    function jumpToFirstError() {
        if (!steps.value?.length) return;

        const firstErrored = visibleFields.value.find(
            (field) => errors.value[String(field.id)],
        );
        if (firstErrored) goToStep((firstErrored.step ?? 1) - 1);
    }

    return {
        captcha,
        answers,
        errors,
        sending,
        sent,
        notice,
        steps,
        stepIndex,
        fieldsForStep,
        isLastStep,
        goToStep,
        submit,
    };
}
