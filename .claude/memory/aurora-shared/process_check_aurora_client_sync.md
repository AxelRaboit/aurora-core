---
name: process_check_aurora_client_sync
description: Après tout changement dans aurora-core (nouvelle feature, refacto, breaking change), TOUJOURS vérifier que le projet aurora-client est à jour et fonctionne avec la nouvelle version. À répéter à chaque session — c'est une lacune récurrente.
metadata:
  type: feedback
---

## Règle

**Chaque fois qu'on modifie aurora-core, on vérifie aurora-client.**

aurora-client est le **projet de démonstration et template de départ** d'aurora-core. Il consomme aurora-core via composer (`vendor/axelraboit/aurora/`). Tout changement dans core peut :

- Casser une extension client (interface/méthode renommée, signature modifiée)
- Rendre obsolète un override (hook devenu inutile, factory à mettre à jour)
- Exposer un nouveau hook que le client devrait adopter
- Ajouter une convention que le client n'applique pas encore
- Changer des routes/URLs que le client référence

**Le client doit refléter les capacités courantes de core, sinon il n'est plus un démo valide ni un point de départ fiable pour les nouveaux projets clients.**

## Cas qui exigent une vérif systématique de aurora-client

| Changement dans aurora-core | Vérif obligatoire côté aurora-client |
|---|---|
| Split de controller (`X → X + Y`) | Recherche les routes par leur nom (`urlGenerator->generate('backend_xxx')`) — les noms doivent rester fonctionnels |
| Nouvelle entité instrumentée (Sylius 5-couches) | Possible nouvel exemple d'extension à ajouter dans aurora-client (vérifie `App\AuroraBundle::$resolve_target_entities`) |
| Nouvelle convention dure (thin controller, thin SFC, autosave, etc.) | Audit du code client : applique-t-il déjà la convention ? Sinon, ouvrir un follow-up |
| Refacto de Manager (changement de signature de hook protected) | Si le client override le hook, sa signature doit suivre |
| Nouvelle interface / nouveau type-hint exposé | Vérifie que les overrides client typehint l'interface, pas la concrete |
| Suppression / renommage d'une méthode publique | Breaking change — grep dans aurora-client AVANT de merger |
| Nouveau composable Vue partagé (`src/Core/assets/shared/composables/`) | Le client peut bénéficier — chercher si des composables locaux dupliquent la logique |
| Nouvelle clé i18n `shared.common.*` ou similar | Le client charge ces clés via vendor — vérifier qu'aucun client-side override de la même clé ne crée de conflit |
| Nouveau hook d'extension (`#[AsAlias]` exposé) | Documenter dans `docs/aurora-core/dev/extending_*.md` comment le client peut l'utiliser |

## Comment l'appliquer

### 1. Avant de finaliser un changement dans aurora-core

Ouvrir aurora-client en parallèle et :

```bash
# Grep pour les usages potentiels du symbole modifié
cd "$AURORA_CLIENT_DIR"   # chemin local du checkout aurora-client
grep -rn "<ClassName>\|<methodName>\|backend_xxx_routename" src/ assets/ templates/ config/
```

### 2. Après un split de controller (cas typique)

Tous les **noms de routes** doivent être préservés à l'identique côté core. Mais aurora-client peut référencer ces routes côté Twig (`path('backend_xxx_yyy')`) ou JS (props passées au composant Vue). Si le split renomme accidentellement une route, le client casse en runtime.

```bash
# Liste rapide des routes utilisées par aurora-client
cd "$AURORA_CLIENT_DIR"   # chemin local du checkout aurora-client
grep -rnE "(path|url)\(['\"]backend_" templates/ src/
grep -rn "backend_" assets/ | grep -v node_modules
```

### 3. Après un changement de convention (mémoire dans aurora-shared)

Une nouvelle règle dans `aurora-shared/` s'applique au client aussi. Le client peut être non-conforme — c'est OK historiquement, mais documenter le gap et ouvrir une todo si le périmètre client est en violation flagrante.

### 4. Si le changement core est breaking

Tester d'abord dans aurora-client :

```bash
cd "$AURORA_CLIENT_DIR"   # chemin local du checkout aurora-client

# ⚠️ TOUJOURS `make aurora-update`, jamais bare `composer update`.
# Le target encapsule : composer update + composer install des sub-deps
# (aurora + tools) + pnpm install (vendor + racine) + cache:clear +
# migrate + privileges:sync + sync-jsconfig/security/claude-md.
make aurora-update

make ft
# Lancer le projet et vérifier que les flows utilisateur principaux marchent
```

### Note : `pull-update` vs `aurora-update` côté client

Deux targets parallèles pour deux scénarios :

| Scénario | Target | Pourquoi |
|---|---|---|
| Pull la PR d'un collègue | **`make pull-update`** | `composer install` (respecte le lock) + sub-deps + migrate + syncs. Pas d'`update` accidentel. |
| Bump explicite d'aurora-core | `make aurora-update` | `composer update axelraboit/aurora` + idem. À utiliser SEULEMENT quand on veut une version + récente que le lock. |

Erreur fréquente : utiliser `make aurora-update` pour un simple pull → on
upgrade aurora-core sans le vouloir, on perturbe les coéquipiers à la prochaine
PR (lockfile divergent). Toujours `make pull-update` pour le workflow quotidien.

### Piège : `composer update` brut côté client = vendor cassé

Symptôme typique après un simple `composer update axelraboit/aurora` (sans `make aurora-update`) :

```
$ make ft
vendor/axelraboit/aurora/node_modules/.bin/eslint: No such file or directory
make[2]: *** [Makefile:238: fix-js] Error 127
```

Cause : composer ne réinstalle pas les `vendor/` ni `node_modules/`
imbriqués dans le vendor d'aurora. Le Makefile client référence ces
binaires (`$(AURORA)/node_modules/.bin/eslint`, `$(AURORA)/tools/...`)
donc tout casse.

**Fix de récupération sans tout réinstaller :**

```bash
composer install --working-dir=vendor/axelraboit/aurora --no-scripts
pnpm --dir=vendor/axelraboit/aurora install
```

Mais **le bon réflexe est `make aurora-update`** dès le départ — ça
inclut composer + pnpm + migrate + syncs config en une commande.

### 5. Reporter dans le commit aurora-core

Quand un changement core impacte le client, le mentionner dans le commit :

```
refactor(xxx): rename Foo::bar() to Foo::baz()

BREAKING for clients overriding Foo::bar() — see aurora-client commit
abc1234 for the corresponding update.
```

## Pourquoi

**Why:** Aurora-client est à la fois (1) le **showcase** présentable aux nouveaux utilisateurs et (2) le **template de départ** que les nouveaux projets clients clonent. Si client diverge de core :
- Les nouveaux clients héritent d'un point de départ obsolète (overrides cassés, conventions non appliquées)
- La démo perd en crédibilité — un visiteur qui ouvre aurora-client voit du code qui ne reflète plus core
- Les anciens conseils ("regarde comment Agency est étendu") cessent d'être valables

**How to apply (résumé):**
- À chaque modification core : `grep` dans aurora-client pour les symboles/routes touchés
- Pour les breaking changes : test runtime côté client AVANT le commit core
- Pour les nouveautés : décider si le client devrait adopter / illustrer la nouveauté (souvent oui)
- Mentionner le client dans le commit core si impact

## Référence

Règle rappelée par l'utilisateur le **2026-05-16** :
*"quand on fait de nouvelle chose, ou qu'on modifie des choses dans
aurora-core, toujours voir si aurora-client, le projet, est à jour"*.
C'est une lacune récurrente (le travail dans core absorbe l'attention,
on oublie le client). Cette mémoire existe pour ramener systématiquement
le réflexe au début de chaque session.

Voir aussi : `aurora-core/architecture/project_aurora_client_role.md`
(côté user-memory non versionnée) — rôle d'aurora-client comme démo + template.
