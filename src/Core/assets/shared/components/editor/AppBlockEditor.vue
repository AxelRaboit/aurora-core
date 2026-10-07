<script setup>
import "./editor.css";
import "./blocks.css";

import { IMAGE_UPLOAD_ENDPOINT, uploadImageFile } from "@/shared/utils/http/uploadImageFile.js";
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
import Raw from "@editorjs/raw";
import { TextColorTool } from "@shared/components/editor/tools/TextColorTool.js";
import { FontSizeTool } from "@shared/components/editor/tools/FontSizeTool.js";
import { UnderlineTool } from "@shared/components/editor/tools/UnderlineTool.js";
import { StrikethroughTool } from "@shared/components/editor/tools/StrikethroughTool.js";
import { BackgroundColorTool } from "@shared/components/editor/tools/BackgroundColorTool.js";
import { ClearFormattingTool } from "@shared/components/editor/tools/ClearFormattingTool.js";
import MediaTextBlock from "@shared/components/editor/tools/MediaTextBlock.js";
import TwoColumnBlock from "@shared/components/editor/tools/TwoColumnBlock.js";
import CalloutBlock, { DEFAULT_TYPES as CALLOUT_TYPES } from "@shared/components/editor/tools/CalloutBlock.js";
import { CALLOUT_ICONS } from "@shared/components/editor/tools/calloutIcons.js";
import LabelBlock, { LABEL_TONES } from "@shared/components/editor/tools/LabelBlock.js";
import AppEmojiPicker from "@shared/components/editor/AppEmojiPicker.vue";
import SocialsBlock from "@shared/components/editor/tools/SocialsBlock.js";
import DragDrop from "editorjs-drag-drop";
import Undo from "editorjs-undo";

/**
 * Generic Editor.js wrapper. Lives in @shared so any module that needs
 * a rich-block editor (translated content bodies, notes, future
 * docs/wiki modules…) can reuse the exact same shell.
 *
 * Module-specific tools are injected by the consumer via the
 * `extraTools` prop - the wrapper merges them into its built-in toolkit
 * at editor init, so nothing here needs to know they exist.
 *
 * Two integration patterns are supported:
 *   - v-model + `:key="<entity-id>"` re-mount: simplest, one editor
 *     instance per selected entity.
 *   - `provide('registerEditor')`: the parent collects every instance and
 *     can flush them all before a save, or ask them all to re-read their own
 *     value - what a multi-locale editor needs to switch locale without
 *     losing the buffer, and what a page of several editors needs at all.
 *
 * That registration is a registry rather than a slot. It used to be one
 * callback the parent overwrote, which was invisible while there was one
 * editor on the page and would have silently dropped the content of every
 * text zone but the last once the content grid put several there.
 */
const { t } = useI18n();

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    placeholder: { type: String, default: "" },
    uploadUrl: { type: String, default: IMAGE_UPLOAD_ENDPOINT },
    /**
     * Module-specific tools dict, merged on top of the built-in set.
     * Shape matches Editor.js' native `tools` config:
     *   { toolName: { class, config?, inlineToolbar? } | ToolClass }
     */
    extraTools: { type: Object, default: () => ({}) },
    /**
     * The block types offered, when a document cannot print them all.
     *
     * Null offers the whole set. A contract, for one, prints titles,
     * paragraphs, lists, quotes and tables, and an image dropped into its
     * wording used to be published and then refused at every freeze. The
     * inline tools (bold, colour...) are not filtered here.
     */
    blockTools: { type: Array, default: null },
    /**
     * Shows the blocks without letting them be edited: a published contract
     * wording was only covered by `pointer-events-none`, and the keyboard
     * still typed into it.
     */
    readOnly: { type: Boolean, default: false },
    /**
     * A small emoji picker above the blocks, for a page written with 👀 and
     * ✅. Off unless asked: a contract renders through a PDF engine that
     * draws no emoji.
     */
    emoji: { type: Boolean, default: false },
});

/** The block tools of the built-in set, as opposed to the inline ones. */
const BLOCK_TOOLS = ["header", "paragraph", "list", "image", "embed", "raw", "table", "quote", "callout", "label", "socials", "mediaText", "twoColumn"];

function offered(tools) {
    if (null === props.blockTools) return tools;

    return Object.fromEntries(
        Object.entries(tools).filter(([name]) => !BLOCK_TOOLS.includes(name) || props.blockTools.includes(name)),
    );
}

const emit = defineEmits(["update:modelValue"]);

const holderElement = ref(null);

// Where the caret last was inside these blocks, so an emoji picked from the
// button above lands there rather than nowhere.
let lastRange = null;
function rememberCaret() {
    const selection = document.getSelection();
    if (selection?.rangeCount && holderElement.value?.contains(selection.anchorNode)) {
        lastRange = selection.getRangeAt(0).cloneRange();
    }
}

function insertEmoji(emoji) {
    if (!lastRange) return;

    const selection = document.getSelection();
    selection.removeAllRanges();
    selection.addRange(lastRange);
    // The editable's own command, so Editor.js hears an input and the undo
    // stack keeps it.
    document.execCommand("insertText", false, emoji);
    rememberCaret();
}
const registerEditor = inject("registerEditor", null);
let unregister = null;

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
 * that copies its data with `structuredClone()` - `@editorjs/list` does -
 * throws `DataCloneError`, because proxies are not structured-cloneable.
 * Editor.js catches that, drops the block, and substitutes its Stub: the
 * infamous « The block can not be displayed correctly ». Nothing throws where
 * you can see it, the public site renders the block perfectly, and the report
 * arrives as somebody saying a list is broken.
 *
 * A JSON round-trip because block data is JSON by definition - it is what gets
 * persisted - so nothing survives the trip that Editor.js could have used. It
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
        holder: holderElement.value,
        placeholder: props.placeholder || t("suite.editor.placeholder"),
        data: { blocks: toPlainBlocks(props.modelValue) },
        i18n: {
            messages: {
                ui: {
                    blockTunes: {
                        toggler: {
                            "Click to tune":   t("suite.editor.ui.block_tunes.toggler.Click to tune"),
                            "or drag to move": t("suite.editor.ui.block_tunes.toggler.or drag to move"),
                        },
                    },
                    inlineToolbar: {
                        converter: {
                            "Convert to": t("suite.editor.ui.inline_toolbar.converter.Convert to"),
                        },
                    },
                    toolbar: {
                        toolbox: {
                            Add: t("suite.editor.ui.toolbar.toolbox.Add"),
                        },
                    },
                    popover: {
                        Filter:           t("suite.editor.ui.popover.Filter"),
                        "Nothing found":  t("suite.editor.ui.popover.Nothing found"),
                        "Nothing found. Try searching for something else.": t("suite.editor.ui.popover.nothing_found_extended"),
                    },
                },
                toolNames: {
                    "Text":           t("suite.editor.tool_names.text"),
                    "Heading":        t("suite.editor.tool_names.heading"),
                    "List":           t("suite.editor.tool_names.list"),
                    "Ordered List":   t("suite.editor.tool_names.ordered_list"),
                    "Unordered List": t("suite.editor.tool_names.unordered_list"),
                    "Checklist":      t("suite.editor.tool_names.checklist"),
                    "Quote":          t("suite.editor.tool_names.quote"),
                    "Code":           t("suite.editor.tool_names.code"),
                    "Delimiter":      t("suite.editor.tool_names.delimiter"),
                    "Raw HTML":       t("suite.editor.tool_names.raw"),
                    "Table":          t("suite.editor.tool_names.table"),
                    "Image":          t("suite.editor.tool_names.image"),
                    "Embed":          t("suite.editor.tool_names.embed"),
                    "Marker":         t("suite.editor.tool_names.marker"),
                    "InlineCode":     t("suite.editor.tool_names.inline_code"),
                    "Underline":      t("suite.editor.tool_names.underline"),
                    "Strikethrough":  t("suite.editor.tool_names.strikethrough"),
                    "Text Color":     t("suite.editor.tool_names.text_color"),
                    "Background Color": t("suite.editor.tool_names.text_background"),
                    "Font Size":      t("suite.editor.tool_names.font_size"),
                    "Clear formatting": t("suite.editor.tool_names.clear_formatting"),
                    "Callout":        t("suite.editor.tool_names.callout"),
                    "Image + Text":   t("suite.editor.tool_names.media_text"),
                    "Two Columns":    t("suite.editor.tool_names.two_column"),
                    "Label":          t("suite.editor.tool_names.label"),
                    "Social networks": t("suite.editor.tool_names.socials"),
                },
                blockTunes: {
                    delete: {
                        Delete:           t("suite.editor.block_tunes.delete.Delete"),
                        "Click to delete": t("suite.editor.block_tunes.delete.Click to delete"),
                    },
                    moveUp: {
                        "Move up": t("suite.editor.block_tunes.move_up.Move up"),
                    },
                    moveDown: {
                        "Move down": t("suite.editor.block_tunes.move_down.Move down"),
                    },
                },
            },
        },
        readOnly: props.readOnly,
        tools: offered({
            // Blocs de texte
            header: {
                class: Header,
                inlineToolbar: true,
                config: { levels: [2, 3, 4], defaultLevel: 2 },
            },
            paragraph: {
                inlineToolbar: true,
            },

            // Listes (unordered, ordered, checklist - fournis par @editorjs/list v2)
            list: {
                class: List,
                inlineToolbar: true,
                config: { defaultStyle: "unordered" },
            },

            // Media
            image: {
                class: Image,
                config: {
                    uploader: {
                        uploadByUrl: async (url) => ({ success: 1, file: { url } }),
                        // Editor.js wants {success: 1, file: {url}}; the
                        // endpoint answers with the filed document. Handing it
                        // the raw body used to "work" only because the request
                        // never reached a route at all.
                        uploadByFile: async (file) => {
                            const uploaded = await uploadImageFile(file, props.uploadUrl);

                            // `documentId` travels with the address, and the
                            // block keeps whatever else `file` carries. Without
                            // it the library has only a URL to recognise its own
                            // picture by, so nothing can answer "which notes use
                            // this image" - and deleting one would empty a note
                            // in silence. Blocks written before this have no id
                            // and still render: the address is unchanged.
                            return uploaded
                                ? {
                                    success: 1,
                                    file: {
                                        url: uploaded.url,
                                        documentId: uploaded.id,
                                    },
                                }
                                : { success: 0 };
                        },
                    },
                    captionPlaceholder: t("suite.editor.image.caption_placeholder"),
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
            // HTML written by hand, for what the other blocks cannot do. Rendered by
            // RawHtmlSanitizer on the server side: broad, but closed to scripts, event
            // handlers and frames to an unlisted host.
            raw: {
                class: Raw,
                config: {
                    placeholder: t("suite.editor.raw.placeholder"),
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
                    quotePlaceholder:   t("suite.editor.quote.placeholder"),
                    captionPlaceholder: t("suite.editor.quote.caption_placeholder"),
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
                    titlePlaceholder:   t("suite.editor.callout.title_placeholder"),
                    messagePlaceholder: t("suite.editor.callout.message_placeholder"),
                    types: CALLOUT_TYPES.map(({ value }) => ({
                        value,
                        label: t(`suite.editor.callout.types.${value}`),
                    })),
                    iconLabels: Object.fromEntries(
                        CALLOUT_ICONS.map(({ value }) => [value, t(`suite.editor.callout.icons.${value}`)]),
                    ),
                    noIconLabel: t("suite.editor.callout.no_icon"),
                },
            },

            // A badge above a column, the name of a competitor.
            label: {
                class: LabelBlock,
                config: {
                    placeholder: t("suite.editor.label.placeholder"),
                    tiltLabel:   t("suite.editor.label.tilt"),
                    toneLabels:  Object.fromEntries(LABEL_TONES.map((tone) => [tone, t(`suite.editor.label.tones.${tone}`)])),
                },
            },
            // The accounts of a brand, each with the logo of its network.
            socials: {
                class: SocialsBlock,
                config: {
                    handlePlaceholder: t("suite.editor.socials.handle_placeholder"),
                    urlPlaceholder:    t("suite.editor.socials.url_placeholder"),
                    addLabel:          t("suite.editor.socials.add"),
                    removeLabel:       t("suite.editor.socials.remove"),
                },
            },

            // Mise en page
            mediaText: {
                class: MediaTextBlock,
                config: {
                    flipLeft:           t("suite.editor.media_text.flip_left"),
                    flipRight:          t("suite.editor.media_text.flip_right"),
                    captionPlaceholder: t("suite.editor.media_text.caption_placeholder"),
                    textPlaceholder:    t("suite.editor.media_text.text_placeholder"),
                    urlPlaceholder:     t("suite.editor.media_text.url_placeholder"),
                    changeUrl:          t("suite.editor.media_text.change_url"),
                    confirm:            t("suite.editor.media_text.confirm"),
                    browse:             t("suite.editor.media_text.browse"),
                    upload:             t("shared.media.upload"),
                    uploading:          t("shared.media.uploading"),
                    uploadFailed:       t("shared.media.upload_failed"),
                    orLabel:            t("suite.editor.media_text.or"),
                },
            },
            twoColumn: { class: TwoColumnBlock },

            // Module-specific tools - Editor.js shape
            // `{ toolName: { class, config?, inlineToolbar? } | Class }`.
            // Spread last so a consumer can override built-in configs too.
            ...props.extraTools,
        }),
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

    // `render` takes nothing and re-reads this instance's own value: with
    // several editors on a page, the parent cannot know what each one holds,
    // and passing blocks in would make it guess.
    unregister = registerEditor?.({
        flush,
        render: () => renderBlocks(props.modelValue),
    });

    // Editor.js was constructed with whatever `modelValue` held at mount, and
    // never looks at it again. A parent that hydrates its form in its own
    // onMounted - which runs *after* this one - therefore handed us an empty
    // array, and the editor stayed blank until something forced a remount.
    // Switching locale and back did exactly that, which is how it looked like
    // a translation bug rather than a boot-order one.
    //
    // Booting is also async (`await isReady` above), so the value can have
    // moved on by now even when the parent is not the cause. Catch up once,
    // here - not through a watcher, which would fire on every keystroke and
    // re-render the document under the caret.
    if (JSON.stringify(props.modelValue) !== lastEmittedJson) {
        await renderBlocks(props.modelValue);
    }
});

onBeforeUnmount(async () => {
    unregister?.();
    unregister = null;
    await flush();
    if (editor && ready) editor.destroy();
    editor = null;
    ready = false;
});
</script>

<template>
    <div v-if="emoji && !readOnly">
        <div class="mb-1 flex justify-end">
            <AppEmojiPicker v-on:pick="insertEmoji" />
        </div>
        <div ref="holderElement" class="editor-block-holder" v-on:keyup="rememberCaret" v-on:mouseup="rememberCaret" />
    </div>
    <div v-else ref="holderElement" class="editor-block-holder" />
</template>
