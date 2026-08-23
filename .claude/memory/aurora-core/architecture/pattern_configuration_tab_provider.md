---
name: pattern-configuration-tab-provider
description: ConfigurationTabProviderInterface - comment un module contribue ses propres onglets dans la page admin Settings, via tagged-iterator + SettingDefinitionRegistry.
metadata:
  type: project
---

## Règle

La page admin Settings est extensible : tout service implémentant
`Aurora\Module\Configuration\Setting\Configuration\ConfigurationTabProviderInterface` voit ses
`ConfigurationTab[]` agrégés par `SettingDefinitionRegistry` (tagged
`aurora.configuration_tab_provider`). Aucun patch sur `SettingsViewBuilder` ou
`SettingsController` n'est nécessaire pour ajouter un onglet.

- DTOs : `ConfigurationTab { id, priority, fields, alwaysVisible, devOnly?,
  componentName?, moduleToggle? }` +
  `SettingFieldDescriptor { key, type, labelKey, descriptionKey, defaultValue,
  options?, placeholderKey? }`. Tous deux non-`final` (extensibles côté
  client si besoin). Le champ `moduleToggle` (depuis bf8bd2fe) accepte un
  `ModuleParameterEnum|string|null` et masque l'onglet quand le module
  est désactivé. Le champ `placeholderKey` (depuis 5ee3b1b9) est une
  clé i18n qui apparaît dans le placeholder de l'input - `null` =
  champ vide (le label + la description suffisent).
- Registry méthodes : `getTabs()` (triées par priority), `getField($key)`,
  `isAdminAccessible($key)`.
- L'implémentation built-in `CoreConfigurationTabProvider` wrappe la legacy
  `ApplicationParameterEnum` - chaque groupe enum (`general`, `reading`, …)
  devient une `ConfigurationTab`. À mesure que des modules s'approprient leurs
  settings, leurs cases quittent l'enum et réapparaissent dans le provider du
  module.
- Validation des writes : `SettingsController::update()` valide via
  `$registry->getField($key)` au lieu de `ApplicationParameterEnum::tryFrom`.
  Toute clé connue du registry est admin-writable par construction.
- Wire format Twig → Vue : `groups` (Record<tabId, field[]>) +
  `tabs` (list<{id, priority, alwaysVisible}>) - la JS dérive l'ordre du
  backend, plus de `GROUP_ORDER` hardcodé.
- `alwaysVisible: true` sur une tab préserve les onglets à UI custom Vue
  (`navigation`, `appearance`) qui n'ont pas de champs persistés propres.

## Pourquoi

Avant ce pattern, ajouter un setting "module" obligeait à patcher
`ApplicationParameterEnum` (god enum) + `useSettingsTabs.js` (GROUP_ORDER
hardcodé) + parfois `SettingsViewBuilder` pour des options custom. Impossible
pour aurora-client d'ajouter ses propres settings sans forker.

Le pattern suit la même mécanique que les autres registries d'extension
Aurora (`ModuleInterface`, `MediaUsageProviderInterface`,
`MenuLocationProviderInterface`) - service tagué + iterator dans un registry.
Cohérent avec la convention "Sylius-style" : le core fournit le contrat, le
client/module implémente.

Phase B (terminée aux commits `b893048c` pilot + `b881109d` suite) :
tous les settings owned par un module ont quitté `ApplicationParameterEnum`
pour vivre avec leur module. L'enum core a perdu 26 cases sur 39 (~70%
plus petit). Les cases restantes sont strictement core (User/Media/Audit
prefixes, site globals, nav aliases, color presets). Convention :

- `src/Module/<Name>/Setting/<Name>SettingEnum.php` - enum implémentant
  `ApplicationParameterEnumInterface` (port direct du contrat existant).
- `src/Module/<Name>/Setting/<Name>ConfigurationTabProvider.php` - itère
  l'enum, construit la `ConfigurationTab`. `final readonly`.
- Traductions `backend.parameters.<key>.*` + `backend.settings.tabs.<id>` +
  `_description` vivent dans le `messages.<locale>.yaml` du module.
- Setting keys persistées **inchangées** entre core et module (zéro
  migration SQL).
- `SettingRepository::getOrDefault()` accepte l'interface - les enums
  modules y passent directement.

Modules migrés en Phase B : ecommerce, crm, billing, editorial, photo,
ged, pdfform, erp. Le tab partagé `sequences` est maintenant alimenté
par 9 providers (Core + 8 modules) via le merge-by-id du registry -
première mise à l'épreuve grandeur nature de ce mécanisme.

Modules sans prefix dédié (project, planning, hr, vault) : pas de
migration nécessaire, ils n'utilisent que `SequencePrefixEnum` directement
sans setting personnalisable.

Phase C (livrée) : registre Vue côté assets. Voir
[[pattern-settings-tab-vue-registry]] pour le détail de l'API
`registerSettingsTabComponent()` et la convention de props pour les
composants custom-rendered.

## Comment l'appliquer

**Ajouter un tab depuis un module** (core ou client) :

```php
final readonly class MyModuleConfigurationTabProvider implements ConfigurationTabProviderInterface
{
    public function __construct(private TranslatorInterface $translator) {}

    public function getTabs(): array
    {
        return [
            new ConfigurationTab(
                id: 'my_module',
                priority: 75,
                fields: [
                    new SettingFieldDescriptor(
                        key: 'my_module.foo',
                        type: 'bool',
                        labelKey: 'backend.parameters.my_module_foo.label',
                        descriptionKey: 'backend.parameters.my_module_foo.description',
                        defaultValue: '0',
                        placeholderKey: 'backend.parameters.my_module_foo.placeholder', // optional
                    ),
                ],
            ),
        ];
    }
}
```

Le tag se pose automatiquement (`_instanceof` dans `config/services.yaml`).
Pas oublier les clés de trad `backend.settings.tabs.<id>` + `_description`
côté i18n + le rebuild via `app:translations:dump-js`.

**Pour un tab à UI custom Vue** (comme `navigation`/`appearance` aujourd'hui) :
mettre `alwaysVisible: true` côté PHP, gérer le rendu dans `SettingsApp.vue`
via un `v-show="activeTab === 'my_module'"`. Phase C généralisera ce point
via un registre Vue, mais pour l'instant c'est un patch ciblé.

Voir [[architecture-module-parameter-enum]] pour la distinction
`ApplicationParameterEnum` / `ModuleParameterEnum` (toggles on/off, à part).

Doc canonique : commits `3404e167` (Phase A intro) - pas de fichier doc
dédié dans `docs/aurora-core/dev/` à date, à créer si Phase B/C sont engagées.
