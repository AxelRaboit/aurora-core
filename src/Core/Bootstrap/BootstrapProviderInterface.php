<?php

declare(strict_types=1);

namespace Aurora\Core\Bootstrap;

/**
 * Declares the rows a module cannot function without.
 *
 * This exists because that data used to live in fixtures. Fixtures are a
 * development convenience — DoctrineFixturesBundle is registered `dev`/`test`
 * only, so `doctrine:fixtures:load` does not even exist in production — yet
 * they carried the locales, the built-in post types and the built-in
 * taxonomies. A production install therefore came up with no active locale
 * (every frontend route 404s, see LocaleTrait) and no post type at all, so no
 * content could be created. The data was correctly identified as bootstrap —
 * both fixture classes said so in their docblocks — but filed somewhere that
 * never runs where it matters.
 *
 * Seed data is not demo data. Demo data illustrates the product and is
 * disposable; this is the floor the product stands on, and it belongs in a
 * command that runs everywhere. `aurora:install` collects every provider.
 *
 * Implementations are tagged `aurora.bootstrap_provider` by the `_instanceof`
 * rule in services.yaml — a module ships one and it is picked up, the same way
 * ApplicationParameterProviderInterface and MenuLocationProviderInterface work.
 *
 * **Implementations must be idempotent.** `aurora:install` is meant to be safe
 * on an existing production database — it runs on every deploy — so a provider
 * creates what is missing and never overwrites what an administrator has since
 * edited. Renaming a locale in the backend must survive the next deploy.
 */
interface BootstrapProviderInterface
{
    /**
     * Creates whatever is missing and returns a line per creation, for the
     * command to report. Returning nothing means everything was already in
     * place, which is the normal outcome on an established install.
     *
     * The provider owns its own persist/flush: seeding a locale and seeding a
     * post type have nothing to say to each other, and one failing should not
     * roll back the other.
     *
     * @return iterable<string> human-readable labels, e.g. "locale fr"
     */
    public function bootstrap(): iterable;

    /**
     * Higher runs first. Core seeds at 100 so module data lands on top of the
     * locales it may want to translate into.
     */
    public function getPriority(): int;
}
