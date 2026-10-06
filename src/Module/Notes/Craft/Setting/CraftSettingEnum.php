<?php

declare(strict_types=1);

namespace Aurora\Module\Notes\Craft\Setting;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnumInterface;

/**
 * The three rows the Craft integration keeps in the settings table.
 *
 * Deliberately not an {@see ApplicationParameterEnumInterface}, for the same
 * reason as Pexels: that interface exists so that the generic screen draws a
 * field and sends its value to the browser, and a token has no business in
 * the source of a page. The tab therefore draws itself, and
 * {@see CraftSettings} is the only way in.
 */
enum CraftSettingEnum: string
{
    case Enabled = 'suite_notes_craft_enabled';

    /** The address Craft gives when a connection is created. */
    case Endpoint = 'suite_notes_craft_endpoint';

    /** Stored encrypted; see {@see CraftSettings}. */
    case Token = 'suite_notes_craft_token';
}
