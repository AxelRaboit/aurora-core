/**
 * Which panes the shared note shows while it is being written.
 *
 * The same three ways of looking at a note as the editor - edit, split and
 * preview - with the same rule: no split on a phone, where two columns do not
 * fit. Asked for on a phone, split falls back to edit, so the choice a reader
 * made on a laptop never leaves the page empty on the other device.
 */
export const SHARE_EDITOR_MODES = ["edit", "split", "preview"];

export function shareEditorView(mode, narrow) {
    const effective = narrow && "split" === mode ? "edit" : mode;

    return {
        mode: effective,
        showEditor: "preview" !== effective,
        showPreview: "edit" !== effective,
    };
}

/** The modes a reader can pick: no split on a narrow screen. */
export function shareEditorModes(narrow) {
    return narrow
        ? SHARE_EDITOR_MODES.filter((one) => "split" !== one)
        : SHARE_EDITOR_MODES;
}
