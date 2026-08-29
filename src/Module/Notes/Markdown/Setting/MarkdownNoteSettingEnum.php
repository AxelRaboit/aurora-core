<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Markdown\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * Module-level settings surfaced on the `/backend/configuration/settings` page under
 * the "notes" tab. Today only image-upload tunables; future cases (e.g.
 * a per-user upload quota, default tag color) plug in here too.
 *
 * Defaults are picked to match what the JS composables hardcoded before
 * this was made configurable (`useNoteImageResize.js`), so flipping a
 * deployment to read from DB is a no-op until an admin overrides them.
 */
enum MarkdownNoteSettingEnum: string implements ApplicationParameterEnumInterface
{
    /** Cap on the longest edge (px) when downsizing an uploaded image. */
    case ImageMaxEdge = 'notes_markdown_image_max_edge';

    /**
     * WebP quality (0-100). Stored as int because the Settings UI only
     * renders int/text/bool - the Vue side divides by 100 before
     * handing the value to the canvas encoder.
     */
    case ImageQualityPct = 'notes_markdown_image_quality_pct';

    public function getKey(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::ImageMaxEdge => 'backend.parameters.notes_markdown_image_max_edge.label',
            self::ImageQualityPct => 'backend.parameters.notes_markdown_image_quality_pct.label',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::ImageMaxEdge => 'backend.parameters.notes_markdown_image_max_edge.description',
            self::ImageQualityPct => 'backend.parameters.notes_markdown_image_quality_pct.description',
        };
    }

    public function getDefaultValue(): string
    {
        return match ($this) {
            self::ImageMaxEdge => '2048',
            self::ImageQualityPct => '85',
        };
    }

    public function getType(): string
    {
        return 'int';
    }

    public function getGroup(): string
    {
        return 'notes';
    }

    /**
     * No placeholder by default - override on a per-case basis when an
     * example value is genuinely clearer than the description alone.
     */
    public function getPlaceholder(): ?string
    {
        return null;
    }
}
