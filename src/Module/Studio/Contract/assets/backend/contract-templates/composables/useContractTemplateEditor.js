import { computed, nextTick, provide, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * The wording editor of one template version.
 *
 * Two things make this more than a form. A version carries one document per
 * language, and the block editor holds its blocks in its own state until asked
 * for them - so switching language, saving and publishing all have to flush the
 * editors first, or the last thing typed is simply not in the payload.
 *
 * The editor registry is the pattern the post editor established: one instance
 * per locale, collected here, driven from here. A single callback would keep
 * only the last editor to mount, which is invisible while there is one and
 * silently loses text once there are three.
 */
export function useContractTemplateEditor(props) {
    const { t } = useI18n();

    const version = ref({ ...props.version });
    const isPublished = computed(() => version.value.isPublished === true);

    const locales = computed(() =>
        (props.locales ?? []).map((locale) => ({
            code: locale.code,
            label: locale.label,
        })),
    );

    const activeLocale = ref(locales.value[0]?.code ?? "fr");

    /**
     * One entry per language, whether the version carries it or not: a language
     * with no wording yet has to be typeable, and an empty tab is how somebody
     * discovers it exists.
     */
    const wording = ref(
        Object.fromEntries(
            locales.value.map((locale) => {
                const existing = props.version.translations?.[locale.code];

                return [
                    locale.code,
                    {
                        title: existing?.title ?? "",
                        blocks: existing?.content?.blocks ?? [],
                    },
                ];
            }),
        ),
    );

    /**
     * Which language prevails between the translations.
     *
     * Null while the version has one language, because there is nothing to
     * arbitrate. It becomes required at publication as soon as a second
     * language carries a title, and the button says so before the server does.
     */
    const governingLocale = ref(props.version.governingLocale ?? null);

    const editors = new Set();
    provide("registerEditor", (handlers) => {
        editors.add(handlers);

        return () => editors.delete(handlers);
    });

    async function flushEditors() {
        await Promise.all([...editors].map((editor) => editor.flush()));
    }

    async function switchLocale(next) {
        if (next === activeLocale.value) return;

        await flushEditors();
        activeLocale.value = next;
        await nextTick();
        await Promise.all([...editors].map((editor) => editor.render()));
    }

    /**
     * A language is sent only when it has a title.
     *
     * That is how the editor says "I am not writing this one", and it matches
     * what the factory keeps: sending an empty title would publish a version
     * whose Spanish document is a heading over a blank page.
     */
    function payload() {
        const translations = {};

        for (const [locale, entry] of Object.entries(wording.value)) {
            if (!entry.title.trim()) continue;

            translations[locale] = {
                title: entry.title,
                content: { blocks: entry.blocks ?? [] },
            };
        }

        return {
            translations,
            // Cleared rather than sent when its language stopped being
            // written: dropping the Spanish document and leaving the clause
            // pointing at it is a save the server would refuse, and the person
            // who dropped it did not mean to answer this question again.
            governingLocale:
                governingLocale.value !== null &&
                Object.hasOwn(translations, governingLocale.value)
                    ? governingLocale.value
                    : null,
        };
    }

    const { request } = useRequest();

    const saving = ref(false);
    const publishing = ref(false);
    const errors = ref({});
    const showPublish = ref(false);
    const showDiscard = ref(false);

    const writtenLocales = computed(() =>
        Object.entries(wording.value)
            .filter(([, entry]) => entry.title.trim().length > 0)
            .map(([locale]) => locale),
    );

    /** Only a language somebody wrote can be the one that prevails. */
    const governingOptions = computed(() =>
        locales.value.filter((locale) =>
            writtenLocales.value.includes(locale.code),
        ),
    );

    const needsGoverningLocale = computed(
        () =>
            writtenLocales.value.length > 1 &&
            !writtenLocales.value.includes(governingLocale.value),
    );

    const canPublish = computed(
        () =>
            !isPublished.value &&
            writtenLocales.value.length > 0 &&
            !needsGoverningLocale.value,
    );

    /**
     * @param {object} [options]
     * @param {boolean} [options.silent] no toast - for a caller that saves as a
     *   means rather than as the act, like opening the preview
     */
    async function save({ silent = false } = {}) {
        if (saving.value || isPublished.value) return false;

        await flushEditors();

        saving.value = true;
        errors.value = {};

        try {
            // noGuard: publishing saves first, so two requests run in
            // sequence and the composable's own guard would drop the second.
            const data = await request(props.savePath, payload(), {
                noGuard: true,
            });

            if (data?.errors) {
                errors.value = data.errors;

                return false;
            }

            if (data?.version) version.value = data.version;

            if (!silent)
                toast.success(t("backend.studio.contract_templates.saved"));

            return true;
        } finally {
            saving.value = false;
        }
    }

    async function publish() {
        // Saved first, always. Publishing what is on screen rather than what
        // was last saved is the only behaviour that cannot surprise anybody,
        // and a published version cannot be corrected afterwards.
        const saved = await save();

        if (!saved) {
            showPublish.value = false;

            return;
        }

        publishing.value = true;

        try {
            const data = await request(
                props.publishPath,
                {},
                { noGuard: true },
            );

            if (data?.errors) {
                errors.value = data.errors;

                return;
            }

            if (data?.version) version.value = data.version;
            showPublish.value = false;
            toast.success(t("backend.studio.contract_templates.published"));
        } finally {
            publishing.value = false;
        }
    }

    async function discard() {
        const data = await request(props.discardPath, {}, { noGuard: true });

        if (data?.errors) {
            errors.value = data.errors;
            showDiscard.value = false;

            return;
        }

        window.location.assign(data?.indexPath ?? props.indexPath);
    }

    function versionPath(id) {
        return buildPath(props.editorPath, { versionId: id });
    }

    return {
        version,
        isPublished,
        locales,
        activeLocale,
        wording,
        switchLocale,
        writtenLocales,
        governingLocale,
        governingOptions,
        needsGoverningLocale,
        canPublish,
        saving,
        publishing,
        errors,
        showPublish,
        showDiscard,
        save,
        publish,
        discard,
        versionPath,
    };
}
