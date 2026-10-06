<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\GitHub\Setting;

/**
 * The two rows the GitHub integration keeps in the settings table.
 *
 * Not an `ApplicationParameterEnumInterface`: the tab draws itself, like
 * those of Pexels and Craft, because a list of accounts is checked before
 * being saved and the generic screen has nothing to do that with.
 * {@see GitHubSettings} is the only way in.
 */
enum GitHubSettingEnum: string
{
    case Enabled = 'suite_editorial_github_enabled';

    /** The identifiers, one per line, in display order. */
    case Logins = 'suite_editorial_github_logins';
}
