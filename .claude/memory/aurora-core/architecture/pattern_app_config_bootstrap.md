---
name: pattern-app-config-bootstrap
description: Exposer un ApplicationParameter aux composants Vue via window.__auroraConfig dans la layout Twig, fallback dur dans le composant.
metadata:
  type: feedback
---

# Pattern : bootstrap config Vue via `window.__auroraConfig`

## Règle

Pour exposer un `ApplicationParameter` (ou tout paramètre serveur) aux
composants Vue - admin OU frontend - on l'injecte dans
`window.__auroraConfig.<key>` depuis la layout Twig (`src/Core/templates/Core/backend/layout.html.twig`,
section `<script>` après les déclarations `__flash__`/`__privileges__`).

La valeur est produite par une **extension Twig dédiée par domaine** (ex:
`AppearanceExtension` pour les paramètres visuels), qui lit le repo une
seule fois par requête (cache in-memory dans l'extension).

Les composants Vue lisent depuis `window.__auroraConfig?.<key>` **avec un
fallback hardcodé** dans le composant - pas de crash si la clé n'est pas
là (tests Vitest, SSR shell, frontend sans bootstrap admin).

## Pourquoi

- Server-rendered + injecté inline → pas de round-trip AJAX au mount d'un
  composant qui doit déjà afficher la valeur (presets de couleur, thème
  primaire, etc.).
- Une seule lecture serveur par requête (extension Twig caching). Le
  `SettingRepository` cache déjà tout en mémoire après le premier hit.
- Cohérent avec ce qui existe déjà (`__flash__`, `__privileges__`,
  `__isDev__`, `__isAdmin__`).
- Marche en SSR-shell + Vue mount : Vue ne dépend pas d'un fetch.

## Comment l'appliquer

1. **Nouvelle extension Twig par domaine** (un fichier par groupe de
   settings, ex: `AppearanceExtension`, `BrandingExtension`). Pas tout
   dans un seul mega-helper - un domaine = une extension. Pattern :

   ```php
   final class AppearanceExtension
   {
       private ?array $cache = null;
       public function __construct(private readonly SettingRepository $settingRepository) {}

       #[AsTwigFunction(name: 'app_color_presets')]
       public function getColorPickerPresets(): array
       {
           if (null !== $this->cache) return $this->cache;
           // ...lecture + parsing depuis SettingRepository
           return $this->cache = $parsed;
       }
   }
   ```

2. **Ligne dans la layout** (`src/Core/templates/Core/backend/layout.html.twig`) :

   ```twig
   <script>
       window.__auroraConfig = window.__auroraConfig || {};
       window.__auroraConfig.colorPickerPresets = {{ app_color_presets()|json_encode|raw }};
   </script>
   ```

3. **Fallback hardcodé dans le composant Vue** :

   ```js
   const DEFAULT_PRESETS = [/* ... */];
   const presets = computed(() => {
       const fromConfig = typeof window !== "undefined"
           ? window.__auroraConfig?.colorPickerPresets
           : null;
       return Array.isArray(fromConfig) && fromConfig.length > 0
           ? fromConfig
           : DEFAULT_PRESETS;
   });
   ```

4. **Validation côté contrôleur** : si l'utilisateur peut éditer le
   setting, valider et normaliser dans le SettingsController (regex
   `/^#[0-9a-fA-F]{6}$/` côté serveur, jamais juste côté client).

## Liens

- [[convention_twig_locale_extension]] - précédent : `LocaleExtension`
  expose `locale_flag()` / `locale_name()` au layout, même style
  d'attribut `#[AsTwigFunction]`.
- Précédent : `SidemenuExtension::getNavSectionAliases()` lit un
  `ApplicationParameter` JSON pour le sidemenu.
- Setting concerné : `ApplicationParameterEnum::ColorPickerPresets`
  (group `appearance`, type `json`).

## Source

Créé le 2026-05-14, suite à la feature "Appearance tab" sur
`/backend/configuration/settings` (palette du AppColorPicker éditable).
