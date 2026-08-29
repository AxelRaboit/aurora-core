import { ref, onBeforeUnmount, watch } from "vue";
import { useNoteImageResize } from "@notes/backend/markdown/composables/useNoteImageResize.js";

/**
 * Drag-drop + clipboard-paste image upload for the markdown textarea.
 *
 * Watches the textarea for two interaction patterns:
 *   - file `drop` event (drag a file from the OS)
 *   - `paste` event whose clipboard has an image item (Ctrl+V from a
 *     screenshot tool, etc.)
 *
 * For each detected image the composable POSTs to the upload endpoint
 * (multipart, single field `image`) and, on success, splices an
 * `![filename](url)` snippet into the textarea at the caret. The caller
 * just hands it `textareaRef` + `applyInsert` (an
 * `(text, caret) => void` that updates v-model + the caret) and the
 * composable owns mount/unmount of the event listeners.
 *
 * Failures surface via `lastError` (the most recent error message) so
 * the consumer SFC can render a small toast/inline notice. No retry -
 * dropping the same file again is the user's path back.
 *
 * @param {object} deps
 * @param {import('vue').Ref<HTMLTextAreaElement|null>} deps.textareaRef
 * @param {(file: File) => Promise<{ok: boolean, payload: any}>} deps.uploadImage
 *   API hook from `useMarkdownNotesApi`.
 * @param {(snippet: string, caretAfter: number) => void} deps.applyInsert
 *   Splices the snippet into the textarea at the caret and emits the
 *   new content (caller's responsibility - typically `useNoteEditorTextarea`).
 * @param {(key: string, params?: object) => string} deps.t - vue-i18n's `t`.
 */
export function useNoteImageUpload({
    textareaRef,
    uploadImage,
    applyInsert,
    t,
    maxEdge,
    quality,
}) {
    const uploading = ref(false);
    const lastError = ref(null);
    const { resize } = useNoteImageResize({ maxEdge, quality });
    let detach = null;

    function attach(textarea) {
        const onDragOver = (event) => {
            // Block the browser's default drop behaviour (which would
            // navigate to the file) so we can intercept it. Only when
            // the drag carries files - text drags should keep their
            // native drop-as-text behaviour.
            if (event.dataTransfer?.types?.includes("Files")) {
                event.preventDefault();
            }
        };

        const onDrop = async (event) => {
            const files = Array.from(event.dataTransfer?.files ?? []).filter(
                (file) => file.type.startsWith("image/"),
            );
            if (files.length === 0) return;
            event.preventDefault();
            for (const file of files) {
                await uploadOne(file, textarea);
            }
        };

        const onPaste = async (event) => {
            const items = Array.from(event.clipboardData?.items ?? []);
            const files = items
                .filter(
                    (item) =>
                        item.kind === "file" && item.type.startsWith("image/"),
                )
                .map((item) => item.getAsFile())
                .filter(Boolean);
            if (files.length === 0) return;
            event.preventDefault();
            for (const file of files) {
                await uploadOne(file, textarea);
            }
        };

        textarea.addEventListener("dragover", onDragOver);
        textarea.addEventListener("drop", onDrop);
        textarea.addEventListener("paste", onPaste);

        return () => {
            textarea.removeEventListener("dragover", onDragOver);
            textarea.removeEventListener("drop", onDrop);
            textarea.removeEventListener("paste", onPaste);
        };
    }

    // `immediate: true` so we wire up on the first assignment (Vue
    // populates `textareaRef.value` during mount - that fires the watch
    // with `previous === undefined`). The same handler also rebinds
    // when the textarea is re-created (e.g. v-if toggling the editor
    // pane), so we don't need a separate onMounted hook - having both
    // would double-attach the listeners and upload each drop twice.
    watch(
        textareaRef,
        (textarea, previous) => {
            if (previous && detach) {
                detach();
                detach = null;
            }
            if (textarea) {
                detach = attach(textarea);
            }
        },
        { immediate: true },
    );
    onBeforeUnmount(() => {
        detach?.();
        detach = null;
    });

    async function uploadOne(file, textarea) {
        uploading.value = true;
        lastError.value = null;
        try {
            // Best-effort client-side downscale. Failures fall back to
            // the original file inside `resize` - they don't bubble up.
            const optimised = await resize(file);
            const { ok, payload } = await uploadImage(optimised);
            if (!ok) {
                lastError.value =
                    payload?.errors?.image ??
                    payload?.message ??
                    t("notes.markdown.image.upload_failed");
                return;
            }
            const snippet = `![${payload.filename}](${payload.url})`;
            const caret = textarea.selectionStart ?? textarea.value.length;
            const value = textarea.value;
            const before = value.substring(0, caret);
            const after = value.substring(caret);
            // Add a leading newline if the cursor is mid-line so the
            // image lands on its own paragraph (markdown convention).
            const needsBreak = before !== "" && !before.endsWith("\n");
            const insertion = (needsBreak ? "\n" : "") + snippet + "\n";
            const newContent = before + insertion + after;
            applyInsert(newContent, caret + insertion.length);
        } catch (error) {
            lastError.value =
                error?.message ?? t("notes.markdown.image.upload_failed");
        } finally {
            uploading.value = false;
        }
    }

    return { uploading, lastError };
}
