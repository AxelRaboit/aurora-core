import { computed, onBeforeUnmount, onMounted, provide, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * What goes to the server: what the fingerprint compares, plus the
 * modification date the editor received (see {@see payload}).
 */
function snapshot(form) {
    return JSON.stringify({
        title: form.title,
        summary: form.summary,
        locale: form.locale,
        gridLayout: form.gridLayout,
        gridContent: form.gridContent,
        appearance: form.appearance,
        readingHeader: form.readingHeader,
        visibleToClient: form.visibleToClient,
        thumbnailId: form.thumbnail?.id ?? null,
        // A Studio deliverable's shelf, category, "modèle" box and client;
        // absent for a space deliverable.
        ...(form.scope
            ? {
                  scope: form.scope,
                  categoryId: form.categoryId ?? null,
                  template: !!form.template,
                  customerId: form.customerId ?? null,
              }
            : {}),
    });
}

/**
 * A value without its empty parts, for comparing.
 *
 * The editing grid fills in the content structure as it reads it: a zone
 * without text gets its empty fields, an empty list that arrived as `[]`
 * becomes `{}`. Nothing has changed for all that, and the editor must not
 * report itself modified on opening. A cleared field is still a change: the
 * value it had disappears from the comparison.
 */
function pruned(value) {
    if (Array.isArray(value)) {
        const list = value.map(pruned).filter((entry) => undefined !== entry);

        return list.length ? list : undefined;
    }

    if (value && "object" === typeof value) {
        const entries = Object.entries(value)
            .map(([key, entry]) => [key, pruned(entry)])
            .filter(([, entry]) => undefined !== entry);

        return entries.length ? Object.fromEntries(entries) : undefined;
    }

    return null === value || "" === value ? undefined : value;
}

/** What is compared to know whether something is left to save. */
function fingerprint(form) {
    return JSON.stringify(pruned(JSON.parse(snapshot(form))) ?? null);
}

/**
 * The request: the content, and the modification date that was received.
 *
 * It does not go into the fingerprint, which only says what the author
 * changed. The server compares it with its own: if a colleague saved in the
 * meantime, it answers 409 rather than erasing their work. A shared
 * deliverable has several authors, and the last save must not win silently.
 */
function payload(form, force) {
    return {
        ...JSON.parse(snapshot(form)),
        updatedAt: form.updatedAt ?? null,
        ...(force ? { force: true } : {}),
    };
}

/**
 * The state of a deliverable open in the editor, and its saving.
 *
 * **Block editors are flushed before saving.** Each text zone keeps its input
 * in its own editor and only hands it over on request: that is the
 * `registerEditor` contract block editors expect from their host, the same as
 * the publication editor's.
 *
 * **Leaving the page with changes warns**, as everywhere you compose for a
 * long time: a grid of twenty zones is not retyped.
 *
 * **Without the right to write, nothing saves or arms**: Ctrl+S does not go
 * to the server to get a 403, and the page does not warn that it is losing
 * changes that could not be made.
 *
 * **A save refused because of the version says why**: `conflict` turns on,
 * the screen offers to reload or to save anyway ({@see saveAnyway}).
 */
export function useDeliverableEditor(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const form = ref(JSON.parse(JSON.stringify(props.deliverable)));
    const saved = ref(fingerprint(form.value));
    const saving = ref(false);
    const errors = ref({});
    const conflict = ref(false);

    const editors = new Set();

    provide("registerEditor", (handlers) => {
        editors.add(handlers);

        return () => editors.delete(handlers);
    });

    async function flushEditors() {
        await Promise.all([...editors].map((editor) => editor.flush()));
    }

    const canEdit = computed(() => !!props.canEdit);
    const dirty = computed(
        () => canEdit.value && fingerprint(form.value) !== saved.value,
    );

    /** The server's first error message, translated: what is missing, not "n'a pas pu être enregistré". */
    function firstError(failures) {
        const key = Object.values(failures ?? {}).find(
            (value) => "string" === typeof value && "" !== value,
        );

        return key ? t(key) : null;
    }

    async function save(force = false) {
        if (saving.value || !canEdit.value) return false;

        await flushEditors();

        saving.value = true;
        errors.value = {};
        conflict.value = false;
        try {
            const sent = snapshot(form.value);
            const data = await request(
                props.updatePath,
                payload(form.value, true === force),
            );

            if (!data?.success) {
                // Someone saved first: neither the generic message nor a false
                // "titre invalide", the real reason.
                if (data?.conflict) {
                    conflict.value = true;

                    return false;
                }

                errors.value = data?.errors ?? {};
                toast.error(
                    firstError(data?.errors) ??
                        t("suite.studio.deliverables.save_failed"),
                );

                return false;
            }

            // What was sent, and not what the server returns: replacing the
            // form would reload every text editor in the grid, cursor
            // included, in the middle of typing. The normalized version shows
            // on the next load.
            saved.value = fingerprint(JSON.parse(sent));
            form.value.updatedAt =
                data.deliverable?.updatedAt ?? form.value.updatedAt;
            toast.success(t("suite.studio.deliverables.saved"));

            return true;
        } finally {
            saving.value = false;
        }
    }

    function onBeforeUnload(event) {
        if (!dirty.value) return;

        event.preventDefault();
        event.returnValue = "";
    }

    function onKeydown(event) {
        if (
            (event.metaKey || event.ctrlKey) &&
            "s" === event.key.toLowerCase()
        ) {
            // The browser does not offer to save the page: the shortcut
            // belongs to the editor, whether it can write or not.
            event.preventDefault();
            if (canEdit.value) void save();
        }
    }

    onMounted(() => {
        window.addEventListener("beforeunload", onBeforeUnload);
        window.addEventListener("keydown", onKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener("beforeunload", onBeforeUnload);
        window.removeEventListener("keydown", onKeydown);
    });

    /** Nothing left to keep: after a deletion, the page does not hold back leaving. */
    function markClean() {
        saved.value = fingerprint(form.value);
    }

    /** Overwrite what the colleague saved: the author's choice, after reading the conflict. */
    function saveAnyway() {
        return save(true);
    }

    function dismissConflict() {
        conflict.value = false;
    }

    return {
        form,
        saving,
        errors,
        conflict,
        dirty,
        canEdit,
        save,
        saveAnyway,
        dismissConflict,
        flushEditors,
        markClean,
    };
}
