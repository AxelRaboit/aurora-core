---
name: convention-no-cross-module-dep
description: Un module **du consommateur** (`App\Module\X`) ne dépend jamais d'un module frère — le câblage passe par un point d'extension. Ne s'applique PAS aux six modules d'aurora-core, qui forment un seul noyau au couplage assumé jusque dans les entités.
metadata:
  type: feedback
---

## Règle

**Un module appartenant au projet consommateur ne dépend jamais d'un module
frère.** Depuis `App\Module\X/` (ou ses assets), les seules dépendances permises
sont :

- `Aurora\Core` / `@core` — le socle du bundle
- `Aurora\Module\…` — les modules d'aurora-core, qui sont tous obligatoires
- `@shared` — les composants partagés
- ses propres sous-dossiers

`App\Module\Tracking → App\Module\Bnb` est interdit, **même** si c'est juste un
import JS d'une classe utilitaire, **même** s'il ne sert qu'à un `{@see}` dans
un docblock — un `use` inutilisé fait tomber le grep de vérification plus bas et
finit par être recopié comme un précédent.

## Ce à quoi elle ne s'applique pas

**Les six modules d'aurora-core** — `Configuration`, `Dev`, `Editorial`, `Ged`,
`General`, `Platform` — ne sont pas des modules à la carte. Ils forment un seul
noyau, et leur graphe de dépendances est dense et cyclique :

```
Configuration  ->  Dev Ged Platform
Dev            ->  Configuration Platform
Editorial      ->  Configuration Dev Ged Platform
Ged            ->  Configuration Dev
General        ->  Configuration Platform
Platform       ->  Configuration Dev
```

Ce n'est pas de la dette. Le couplage descend jusqu'aux entités —
`AbstractPost::$thumbnail` est un `ManyToOne` vers `DocumentInterface`, et
`AuroraBundle` câble en dur les entités de Ged dans `resolve_target_entities`.
Editorial sans Ged ne démarre pas : le mapping Doctrine ne résout plus.

**Ne pas relever ces liens en audit, et ne pas proposer d'interface dans
`src/Core/` pour les casser** : l'indirection ne protégerait rien, puisque le
module cible reste obligatoire. Un audit qui relève une règle inapplicable la
fait ignorer, et une règle ignorée par habitude ne protège plus contre le cas
qu'elle visait.

## Pourquoi

**Why:** la règle est née d'une fuite réelle — un
`import ProductGridBlock from "@ecommerce/…"` dans `EditorBlock.vue` (Editorial),
rendue visible par l'extraction de l'éditeur vers `@shared`. Le danger qu'elle
vise : du code mort chargé, un tree-shaking impuissant, et **désactiver un
module qui fait exploser le build d'un autre**.

Ce danger existe là où deux modules sont réellement optionnels l'un pour
l'autre. C'était le cas des douze modules à la carte ; ils sont **archivés en
lecture seule sur GitHub** depuis le 2026-08-08 (`bdae6959`), avec
`aurora-editorial`, et un projet consommateur ne requiert plus que
`axelraboit/aurora`. Editorial est donc du core aujourd'hui, et la question
« activer Editorial sans Ecommerce » ne se pose plus dans ce dépôt.

Elle se pose toujours **chez le consommateur**, dont les modules sont écrits,
activés et retirés un par un — et le jour où des packages à la carte
reviendraient, la règle s'appliquerait à eux telle quelle.

## Comment l'appliquer

1. Quand une feature nécessite de plugger un truc d'un module B dans un
   composant d'un module A, **A expose un point d'extension typé** (prop
   `extraXxx`, slot scoped, hook composable). Même patron que `extraFields`.
2. **C'est le projet qui câble** A et B ensemble en passant la prop. Ni
   aurora-core, ni un module, ne fait le câblage lui-même.
3. Côté Vue, l'extension passe par les props du composant racine (ex:
   `PostsApp` → `extra-editor-tools`), injectées par le Twig via le ViewBuilder.
   Le client écrit son overlay Twig ou son ViewBuilder substitué.
4. Côté PHP, même règle : pas de `use App\Module\Autre\…` dans
   `src/Module/Soi/`. Si besoin, passer par une interface d'`Aurora\Core` que
   les deux implémentent ou consomment.

## Comment vérifier

Depuis un projet consommateur :

```bash
# Dépendances PHP entre modules du projet — devrait ne rien sortir
for m in $(ls src/Module/); do
  hits=$(grep -rhoE 'App\\Module\\[A-Za-z]+' "src/Module/$m" 2>/dev/null \
    | sort -u | sed 's/App\\Module\\//' | grep -v "^$m$" | tr '\n' ' ')
  [ -n "$hits" ] && echo "$m -> $hits"
done
```

```bash
# Imports JS entre modules du projet
grep -rE "^import .* from ['\"]@[a-z-]+/" src/Module/ \
  | grep -vE "@(core|shared)/"
```

Un module d'aurora-core qui apparaît dans le premier résultat n'est **pas** une
faute — voir « Ce à quoi elle ne s'applique pas ».

Voir [[convention-thin-controller]] (même esprit côté backend),
[[convention_mirrored_contract_php_js]] (l'autre contrat qu'un test tient à
notre place) et [[pref-think-long-term]].
