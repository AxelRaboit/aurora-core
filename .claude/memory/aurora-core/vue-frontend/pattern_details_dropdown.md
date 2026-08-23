---
name: pattern_details_dropdown
description: Dropdowns Twig frontend en `<details data-dropdown>` - l'ouverture est native, seul le clic-extérieur/Échap est ajouté en JS.
metadata:
  type: project
---

## Règle

Un dropdown dans un template Twig frontend (hors passerelle Vue) se fait avec
`<details data-dropdown>` + `<summary>`, **pas** avec un `<select>` ni un
composant Vue dédié.

```twig
<nav class="relative" aria-label="{{ 'shared.xxx'|trans }}">
    <details class="group" data-dropdown>
        <summary class="… list-none [&::-webkit-details-marker]:hidden">
            Libellé
            {{ include('@Shared/components/icon.html.twig', {
                name: 'chevron-down',
                class: 'transition-transform group-open:rotate-180',
            }) }}
        </summary>
        <div class="absolute right-0 top-full z-20 mt-1 … border border-line bg-bg shadow-lg">
            <a href="…">…</a>
        </div>
    </details>
</nav>
```

L'attribut `data-dropdown` est un **opt-in** lu par
`src/Core/assets/shared/utils/detailsDropdown.js` (importé dans `app.js`), qui
ajoute uniquement : fermeture au clic extérieur, fermeture sur Échap avec retour
du focus sur le `<summary>`, et fermeture des autres quand un s'ouvre.

Référence : `Frontend/themes/default/partials/locale_switcher.html.twig`.

## Pourquoi

- **Le contenu reste de vrais `<a href>`** : `hreflang`/`lang`/`aria-current`
  conservés, clic-milieu fonctionnel, crawlable. Un `<select>` aurait forcé du
  texte nu dans les `<option>` (impossible d'y mettre les drapeaux
  `flag-icons`) et rendu la navigation dépendante de JS.
- **Dégradation propre** : sans JS le panneau s'ouvre et se ferme quand même
  depuis son `summary`. Le JS n'est qu'un confort, jamais un prérequis.
- **Accessibilité gratuite** : `<details>/<summary>` est annoncé comme un
  disclosure avec son état étendu/replié, sans ARIA à maintenir à la main.
- Le header garde la même largeur que le site parle 2 langues ou 12.

## Comment l'appliquer

1. Nouveau dropdown en Twig → `<details data-dropdown>`, jamais de `<select>`
   qui navigue au `change`.
2. Mettre `class="group"` sur le `<details>` pour piloter le chevron via
   `group-open:` ; masquer le marker natif avec
   `list-none [&::-webkit-details-marker]:hidden` (Safari a besoin du second).
3. Panneau en `absolute` → le parent porte `relative` ; aligner sur le style du
   panneau de `partials/menu.html.twig` (`z-20`, `border border-line`, `bg-bg`,
   `shadow-lg`) pour rester cohérent dans le même header.
4. Ne rien ajouter dans `detailsDropdown.js` pour un cas particulier : il est
   volontairement générique et sans état. Un dropdown qui a besoin de plus
   (recherche, chargement async) est une passerelle Vue, cf.
   [[convention_frontend_rendering]].
5. Styling utility-first uniquement, cf. [[convention_no_bem_tailwind_first]].
