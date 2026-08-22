---
name: pattern-cross-mount-state-sync
description: Synchroniser l'état entre deux mounts Vue indépendants alimentés par Twig sans reload — CustomEvent sur window + composable qui wrap la prop dans un ref
metadata:
  type: project
---

## Contexte

Aurora monte plusieurs apps Vue indépendantes sur une même page :
- Le layout (`layout.html.twig`) monte `AppSidemenu` avec des props seedées
  depuis `app.user.<x>`
- Une page interne (ex: `/backend/general/profile/sidemenu`) monte `PreferencesApp`
  avec ses propres props

Quand la page interne sauvegarde un état partagé (ex: couleurs de
sections), le layout a déjà rendu — sa prop est figée. Sans intervention,
l'utilisateur doit recharger la page pour voir l'autre mount refléter la
nouvelle valeur.

**Anti-pattern** : recharger la page après save (`window.location.reload()`)
— c'est ce qui était fait initialement. Ça flashe le toast à peine
affiché et casse la convention Aurora "settings = toast only, pas de
reload" (vérifié sur l'historique pré-feature : `useSidemenuPreferences`
faisait juste un toast).

## Solution

**CustomEvent global + composable réactif** :

1. **Constante d'event partagée** entre les deux côtés, exportée depuis
   le composable du consommateur :
   ```js
   // useSidemenuLiveColors.js (côté consommateur)
   export const SIDEMENU_PREFS_EVENT = "aurora:sidemenu-prefs-updated";
   ```

2. **Composable du consommateur** : wrap la prop initiale dans un ref,
   écoute l'event sur `window`, met à jour le ref :
   ```js
   export function useSidemenuLiveColors(initial) {
       const liveSectionColors = ref(normaliseColorMap(initial));
       function handlePrefsUpdated(event) {
           liveSectionColors.value = normaliseColorMap(event.detail?.navSectionColors);
       }
       onMounted(() => window.addEventListener(SIDEMENU_PREFS_EVENT, handlePrefsUpdated));
       onBeforeUnmount(() => window.removeEventListener(SIDEMENU_PREFS_EVENT, handlePrefsUpdated));
       return { liveSectionColors };
   }
   ```

3. **Composable du producteur** : importe la constante, dispatch après
   réponse serveur OK :
   ```js
   import { SIDEMENU_PREFS_EVENT } from "@core/backend/sidemenu/composables/useSidemenuLiveColors.js";

   function broadcastPrefs(navSectionColors) {
       window.dispatchEvent(new CustomEvent(SIDEMENU_PREFS_EVENT, {
           detail: { navSectionColors: { ...navSectionColors } },
       }));
   }
   ```

4. **Composables qui consomment le ref** (ex: `useSidemenuSectionTheme`)
   doivent supporter `isRef` et lire `.value` dans leurs `resolve()` —
   pas capturer la valeur dans le closure au call time, sinon la
   réactivité ne passe pas.

## Les trois usages en place (2026-08)

Le patron ne sert plus seulement à propager un état après un save : il sert
aussi à ce qu'un **bouton déplacé** atteigne la fonctionnalité qu'il déclenche,
restée où elle était.

| Événement | Déclaré dans | Sert à |
|---|---|---|
| `aurora:sidemenu-prefs-updated` | `useSidemenuLiveColors` | recolorer la sidemenu après un save de préférences |
| `aurora:sidemenu-collapsed` | `useSidemenuCollapse` | tenir le menu et le bouton de pliage de la topbar au même état |
| `aurora:open-search` | `useBackendSearch` | ouvrir la palette depuis la topbar, la palette restant dans la sidemenu |

**Règle qui vaut pour les trois** : l'événement est déclaré et **écouté dans le
composable qui possède la fonctionnalité**, jamais dans le SFC. Un
`onMounted` + `addEventListener` dans un `<script setup>` est ce que
[[convention_sfc_thin_presentation]] refuse, et ça sépare le nom de
l'événement de l'endroit qui sait quoi en faire.

**Le nom de l'événement est un contrat entre deux fichiers qui ne s'importent
jamais** — donc il s'épingle dans un test. Voir `useBackendSearch.test.js` :
il assère la chaîne, l'ouverture, et le fait que l'écouteur disparaisse au
démontage (il y en a un par page ; sans nettoyage, un composant mort continue
de réagir).

## Why

- **Toast survit** : pas de reload, le toast Sonner reste affiché son
  temps normal
- **UX instantanée** : la sidemenu se recolore au moment où le bouton
  Save répond OK, pas après un round-trip réseau + parse HTML complet
- **Convention Aurora respectée** : les pages settings ne reloadent
  jamais après save (`useUserSettings`, `useProfileForm`, etc.)
- **Pas de cross-app prop-drilling** : les deux mounts restent
  indépendants — aucun import croisé, juste un contrat d'event

## How to apply

À utiliser à chaque fois qu'un état partagé entre layout-mount et
page-mount doit refléter une modif sans reload :

1. **Si l'état est server-filtered** (hidden items, privileges, etc.) :
   ce pattern ne marche **pas** seul — la donnée déjà filtrée par PHP
   ne peut pas être unfiltered côté JS. Reload reste la solution
   honnête pour ces cas-là. Le sidemenu hide d'ailleurs ne se met à
   jour qu'à la prochaine navigation (limite acceptée).
2. **Si l'état est purement présentational** (couleurs, layout
   préférences) : ce pattern est idéal — le serveur stocke la
   valeur, le client la consomme en classes Tailwind / styles.

## Pièges

- **Réactivité du composable consommateur** : si le composable cible
  prend `overrides = {}` et le déstructure / itère dans son closure,
  les changements de ref ne propagent pas. Il doit lire le ref dans
  ses fonctions exposées (`resolve()`, `headerClasses()`, etc.) pour
  que Vue track la dépendance au call-time du template.
- **Normalisation des payloads** : PHP encode un assoc vide `[]` en
  JSON `[]` (Array, pas Object). Le composable doit normaliser tout
  payload entrant en `Record` plain (cf. `normaliseColorMap`).
- **Event name = contrat** : passer la constante en export, pas un
  magic string dupliqué — un rename d'un côté pète silencieusement
  l'autre.

## Voir aussi

- [[pattern_user_sidemenu_preferences]] — la feature concrète où ce
  pattern a été appliqué
- [[convention_sfc_thin_presentation]] — la logique reste dans les
  composables (`useSidemenuLiveColors`), jamais dans le `.vue`
