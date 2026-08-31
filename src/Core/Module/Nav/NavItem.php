<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Nav;

final readonly class NavItem
{
    /**
     * @param string               $route       Symfony route name. **Stable token** : sert d'identifiant immuable
     *                                          pour persister les préférences utilisateur
     *                                          (`CoreUserInterface::getHiddenNavItems()`). Renommer une route =
     *                                          breaking change (perte silencieuse de la préférence côté users).
     * @param NavItem[]            $children
     * @param array<string, mixed> $routeParams Paramètres passés au générateur d'URL. Non vide = plusieurs
     *                                          entrées partagent un même nom de route (les onglets de réglages
     *                                          sont onze entrées sur `..._settings_tab`), et deux choses
     *                                          changent alors : il faut une `$key` distincte, et l'entrée
     *                                          active se reconnaît à son chemin, pas à son nom de route.
     * @param ?string              $key         Identifiant stable, quand le nom de route n'en est pas un.
     *                                          Défaut : le nom de route, ce qu'il a toujours été. Renseigné,
     *                                          il devient ce que persistent les préférences utilisateur -
     *                                          donc immuable au même titre qu'un nom de route. Sans lui,
     *                                          masquer un onglet de réglages les masquerait tous les onze.
     */
    public function __construct(
        public string $route,
        public string $labelKey,
        public string $icon,
        public ?string $requiredPrivilege = null,
        public string $activeColor = 'accent',
        public ?string $activeRoutePrefix = null,
        public array $children = [],
        public ?string $descriptionKey = null,
        public array $routeParams = [],
        public ?string $key = null,
    ) {}

    /** Ce que les préférences utilisateur persistent, et ce que la palette indexe. */
    public function stableKey(): string
    {
        return $this->key ?? $this->route;
    }
}
