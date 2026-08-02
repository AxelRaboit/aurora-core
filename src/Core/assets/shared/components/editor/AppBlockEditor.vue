<script setup>
import "./editor.css";
import "./blocks.css";

import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { ref, onMounted, onBeforeUnmount, inject } from "vue";
import { useI18n } from "vue-i18n";
import EditorJS from "@editorjs/editorjs";
import Header from "@editorjs/header";
import List from "@editorjs/list";
import Quote from "@editorjs/quote";
import Code from "@editorjs/code";
import Delimiter from "@editorjs/delimiter";
import Table from "@editorjs/table";
import Embed from "@editorjs/embed";
import Image from "@editorjs/image";
import Marker from "@editorjs/marker";
import InlineCode from "@editorjs/inline-code";
import { TextColorTool } from "@shared/components/editor/tools/TextColorTool.js";
import { FontSizeTool } from "@shared/components/editor/tools/FontSizeTool.js";
import { UnderlineTool } from "@shared/components/editor/tools/UnderlineTool.js";
import { StrikethroughTool } from "@shared/components/editor/tools/StrikethroughTool.js";
import { BackgroundColorTool } from "@shared/components/editor/tools/BackgroundColorTool.js";
import { ClearFormattingTool } from "@shared/components/editor/tools/ClearFormattingTool.js";
import MediaTextBlock from "@shared/components/editor/tools/MediaTextBlock.js";
import TwoColumnBlock from "@shared/components/editor/tools/TwoColumnBlock.js";
import CalloutBlock from "@shared/components/editor/tools/CalloutBlock.js";
import DragDrop from "editorjs-drag-drop";
import Undo from "editorjs-undo";

/**
 * Generic Editor.js wrapper. Lives in @shared so any module that needs
 * a rich-block editor (translated content bodies, notes, future
 * docs/wiki modules…) can reuse the exact same shell.
 *
 * Module-specific tools are injected by the consumer via the
 * `extraTools` prop — the wrapper merges them into its built-in toolkit
 * at editor init, so nothing here needs to know they exist.
 *
 * Two integration patterns are supported:
 *   - v-model + `:key="<entity-id>"` re-mount: simplest, one editor
 *     instance per selected entity.
 *   - `provide('registerEditorFlush'/'registerEditorRender')` callbacks:
 *     parent captures them and can flush before save or push fresh
 *     blocks without remounting — what a multi-locale editor needs when
 *     switching locale without losing the buffer.
 */
const { t } = useI18n();

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    placeholder: { type: String, default: "" },
    uploadUrl: { type: String, default: "/backend/media/media/upload" },
    /**
     * Module-specific tools dict, merged on top of the built-in set.
     * Shape matches Editor.js' native `tools` config:
     *   { toolName: { class, config?, inlineToolbar? } | ToolClass }
     */
    extraTools: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["update:modelValue"]);

const holderEl = ref(null);
const registerFlush  = inject("registerEditorFlush",  null);
const registerRender = inject("registerEditorRender", null);

let editor = null;
let ready = false;
let lastEmittedJson = JSON.stringify(props.modelValue);

function emitIfChanged(blocks) {
    const json = JSON.stringify(blocks);
    if (json === lastEmittedJson) return;
    lastEmittedJson = json;
    emit("update:modelValue", blocks);
}

/**
 * Editor.js must never see a Vue proxy.
 *
 * `modelValue` reaches us deeply reactive, so every block is a `Proxy`. A tool
 * that copies its data with `structuredClone()` — `@editorjs/list` does —
 * throws `DataCloneError`, because proxies are not structured-cloneable.
 * Editor.js catches that, drops the block, and substitutes its Stub: the
 * infamous « The block can not be displayed correctly ». Nothing throws where
 * you can see it, the public site renders the block perfectly, and the report
 * arrives as somebody saying a list is broken.
 *
 * A JSON round-trip because block data is JSON by definition — it is what gets
 * persisted — so nothing survives the trip that Editor.js could have used. It
 * also hands over a copy rather than our state, which is what we want anyway:
 * the editor owns its buffer and gives it back through `save()`.
 */
function toPlainBlocks(blocks) {
    return JSON.parse(JSON.stringify(blocks ?? []));
}

async function flush() {
    if (editor && ready) {
        const data = await editor.save();
        emitIfChanged(data.blocks);
    }
}

async function renderBlocks(blocks) {
    if (!editor || !ready) return;
    await editor.render({ blocks: toPlainBlocks(blocks) });
    const data = await editor.save();
    emitIfChanged(data.blocks);
}

onMounted(async () => {
    editor = new EditorJS({
        holder: holderEl.value,
        placeholder: props.placeholder || t("backend.editor.placeholder"),
        data: { blocks: toPlainBlocks(props.modelValue) },
        i18n: {
            messages: {
                ui: {
                    blockTunes: {
                        toggler: {
                            "Click to tune":   t("backend.editor.ui.block_tunes.toggler.Click to tune"),
                            "or drag to move": t("backend.editor.ui.block_tunes.toggler.or drag to move"),
                        },
                    },
                    inlineToolbar: {
                        converter: {
                            "Convert to": t("backend.editor.ui.inline_toolbar.converter.Convert to"),
                        },
                    },
                    toolbar: {
                        toolbox: {
                            Add: t("backend.editor.ui.toolbar.toolbox.Add"),
                        },
                    },
                    popover: {
                        Filter:           t("backend.editor.ui.popover.Filter"),
                        "Nothing found":  t("backend.editor.ui.popover.Nothing found"),
                        "Nothing found. Try searching for something else.": t("backend.editor.ui.popover.nothing_found_extended"),
                    },
                },
                toolNames: {
                    "Text":           t("backend.editor.tool_names.text"),
                    "Heading":        t("backend.editor.tool_names.heading"),
                    "List":           t("backend.editor.tool_names.list"),
                    "Ordered List":   t("backend.editor.tool_names.ordered_list"),
                    "Unordered List": t("backend.editor.tool_names.unordered_list"),
                    "Checklist":      t("backend.editor.tool_names.checklist"),
                    "Quote":          t("backend.editor.tool_names.quote"),
                    "Code":           t("backend.editor.tool_names.code"),
                    "Delimiter":      t("backend.editor.tool_names.delimiter"),
                    "Table":          t("backend.editor.tool_names.table"),
                    "Image":          t("backend.editor.tool_names.image"),
                    "Embed":          t("backend.editor.tool_names.embed"),
                    "Marker":         t("backend.editor.tool_names.marker"),
                    "InlineCode":     t("backend.editor.tool_names.inline_code"),
                    "Underline":      t("backend.editor.tool_names.underline"),
                    "Strikethrough":  t("backend.editor.tool_names.strikethrough"),
                    "Text Color":     t("backend.editor.tool_names.text_color"),
                    "Background Color": t("backend.editor.tool_names.text_background"),
                    "Font Size":      t("backend.editor.tool_names.font_size"),
                    "Clear formatting": t("backend.editor.tool_names.clear_formatting"),
                    "Callout":        t("backend.editor.tool_names.callout"),
                    "Image + Text":   t("backend.editor.tool_names.media_text"),
                    "Two Columns":    t("backend.editor.tool_names.two_column"),
                },
                blockTunes: {
                    delete: {
                        Delete:           t("backend.editor.block_tunes.delete.Delete"),
                        "Click to delete": t("backend.editor.block_tunes.delete.Click to delete"),
                    },
                    moveUp: {
                        "Move up": t("backend.editor.block_tunes.move_up.Move up"),
                    },
                    moveDown: {
                        "Move down": t("backend.editor.block_tunes.move_down.Move down"),
                    },
                },
            },
        },
        tools: {
            // Blocs de texte
            header: {
                class: Header,
                inlineToolbar: true,
                config: { levels: [2, 3, 4], defaultLevel: 2 },
            },
            paragraph: {
                inlineToolbar: true,
            },

            // Listes (unordered, ordered, checklist — fournis par @editorjs/list v2)
            list: {
                class: List,
                inlineToolbar: true,
                config: { defaultStyle: "unordered" },
            },

            // Médias
            image: {
                class: Image,
                config: {
                    uploader: {
                        uploadByUrl: async (url) => ({ success: 1, file: { url } }),
                        uploadByFile: async (file) => {
                            const body = new FormData();
                            body.append("image", file);
                            try {
                                const response = await fetch(props.uploadUrl, { method: HttpMethod.Post, body });
                                if (!response.ok) return { success: 0 };
                                return response.json();
                            } catch {
                                return { success: 0 };
                            }
                        },
                    },
                    captionPlaceholder: t("backend.editor.image.caption_placeholder"),
                },
            },
            embed: {
                class: Embed,
                config: {
                    services: {
                        youtube: true,
                        vimeo: true,
                        twitter: true,
                        instagram: true,
                        codepen: true,
                    },
                },
            },
            table: {
                class: Table,
                inlineToolbar: true,
                config: { rows: 2, cols: 3, withHeadings: true },
            },

            // Mise en forme
            quote: {
                class: Quote,
                inlineToolbar: true,
                config: {
                    quotePlaceholder:   t("backend.editor.quote.placeholder"),
                    captionPlaceholder: t("backend.editor.quote.caption_placeholder"),
                },
            },
            delimiter: Delimiter,
            code: Code,

            // Outils inline
            marker: {
                class: Marker,
            },
            inlineCode: {
                class: InlineCode,
            },
            underline: {
                class: UnderlineTool,
            },
            strikethrough: {
                class: StrikethroughTool,
            },
            textColor: {
                class: TextColorTool,
            },
            textBackground: {
                class: BackgroundColorTool,
            },
            fontSize: {
                class: FontSizeTool,
            },
            clearFormatting: {
                class: ClearFormattingTool,
            },

            // Callout
            callout: {
                class: CalloutBlock,
                config: {
                    titlePlaceholder:   t("backend.editor.callout.title_placeholder"),
                    messagePlaceholder: t("backend.editor.callout.message_placeholder"),
                },
            },

            // Mise en page
            mediaText: {
                class: MediaTextBlock,
                config: {
                    flipLeft:           t("backend.editor.media_text.flip_left"),
                    flipRight:          t("backend.editor.media_text.flip_right"),
                    captionPlaceholder: t("backend.editor.media_text.caption_placeholder"),
                    textPlaceholder:    t("backend.editor.media_text.text_placeholder"),
                    urlPlaceholder:     t("backend.editor.media_text.url_placeholder"),
                    changeUrl:          t("backend.editor.media_text.change_url"),
                    confirm:            t("backend.editor.media_text.confirm"),
                    browse:             t("backend.editor.media_text.browse"),
                    orLabel:            t("backend.editor.media_text.or"),
                    mediaIdPlaceholder: t("backend.editor.media_text.media_id_placeholder"),
                    mediaIdNotFound:    t("backend.editor.media_text.media_id_not_found"),
                },
            },
            twoColumn: { class: TwoColumnBlock },

            // Module-specific tools — Editor.js shape
            // `{ toolName: { class, config?, inlineToolbar? } | Class }`.
            // Spread last so a consumer can override built-in configs too.
            ...props.extraTools,
        },
        onChange: async () => {
            if (!editor) return;
            const data = await editor.save();
            emitIfChanged(data.blocks);
        },
    });
    const localEditor = editor;
    try {
        await localEditor.isReady;
    } catch {
        return;
    }
    if (editor !== localEditor) return;
    new DragDrop(localEditor);
    new Undo({ editor: localEditor });
    ready = true;
    registerFlush?.(flush);
    registerRender?.(renderBlocks);

    // Editor.js was constructed with whatever `modelValue` held at mount, and
    // never looks at it again. A parent that hydrates its form in its own
    // onMounted — which runs *after* this one — therefore handed us an empty
    // array, and the editor stayed blank until something forced a remount.
    // Switching locale and back did exactly that, which is how it looked like
    // a translation bug rather than a boot-order one.
    //
    // Booting is also async (`await isReady` above), so the value can have
    // moved on by now even when the parent is not the cause. Catch up once,
    // here — not through a watcher, which would fire on every keystroke and
    // re-render the document under the caret.
    if (JSON.stringify(props.modelValue) !== lastEmittedJson) {
        await renderBlocks(props.modelValue);
    }
});

onBeforeUnmount(async () => {
    registerFlush?.(null);
    registerRender?.(null);
    await flush();
    if (editor && ready) editor.destroy();
    editor = null;
    ready = false;
});
</script>

<template>
    <div ref="holderEl" class="editor-block-holder" />
</template>
