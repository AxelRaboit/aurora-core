---
name: pref-think-long-term
description: User prefers "design for the long term" over Claude's default YAGNI rule. Architectural refactors happen as soon as the abstraction is sound, even without an immediate concrete user - within explicit guardrails. Applies to aurora-core AND aurora-client.
metadata:
  type: feedback
---

Sur l'écosystème Aurora (core + clients), la règle Claude par défaut
**"Three similar lines is better than a premature abstraction" /
"Don't design for hypothetical future requirements"** est **inversée**.
L'utilisateur préfère **anticiper les évolutions architecturales**
plutôt qu'attendre qu'un besoin force la refacto.

**Why:** Aurora-core est un bundle composer distribué à plusieurs
clients (aurora-client*). Une abstraction saine ajoutée maintenant =
extensibilité gratuite pour TOUS les clients futurs. Une abstraction
absente = chaque client qui en a besoin fork ou patch ad-hoc =
incohérence cross-clients à terme.

La même règle s'applique côté **aurora-client** : un dev qui ajoute
une feature dans son app cliente anticipe la même qualité (séparation
domaine/présentation, hooks d'extension, conventions de naming) pour
que son code reste cohérent avec le bundle qu'il étend.

**How to apply (côté aurora-core ET aurora-client) :**

✅ **Faire le refactor MAINTENANT** quand l'abstraction est :
- Architecturalement saine (SOLID, separation of concerns, inversion
  de dépendances)
- Justifiée par un principe documenté (entity_extensibility_convention.md,
  storage_policy.md, conventions shared/, …)
- Cohérente avec ce qui existe ailleurs dans le repo (un 3e site
  similaire = seuil pour extraire un helper, cf. `Num::clamp`)
- Préparant l'extensibilité (côté core : pour les clients ; côté
  client : pour ses propres évolutions futures)

❌ **Ne PAS faire si** :
- Aucun implémenteur multiple plausible (interface sans 2nd impl =
  fluff)
- Hook sans usecase d'override identifié (méthode `protected` qui ne
  sera jamais surchargée = bruit)
- Coût disproportionné (refacto 50 fichiers pour une amélioration
  marginale)
- Spéculation pure ("et si plus tard…" sans pattern SOLID à invoquer)

**Exemples concrets validés côté core** :
- 22 callers de `Media::getPublicUrl()` → refactor vers `MediaUrlGenerator`
  injecté (séparation domaine/HTTP) ✅ - puis fusion Media→GED en
  2026-05-30, c'est maintenant `DocumentUrlGenerator` qui sert tout
- `Num::clamp` extrait dès 10+ sites inline ✅
- Convention extensibilité Sylius 5 couches partout ✅
- Settings admin pour 2 réglages d'image notes-markdown (1 client) ✅

**Exemples qui ont été refusés** :
- Interface `MarkdownNoteImageServiceInterface` (1 service stateless) ❌
- Sous-dossiers dans `shared/components/overlay/` (4 composants) ❌

Cette philosophie est documentée dans `CLAUDE.md` §3bis (côté core).
Côté aurora-client, la même règle s'applique implicitement - le client
hérite de ce préf via composer + sync mémoire. Toute nouvelle convention
validée par l'utilisateur peut être actée comme mémoire ou doc, mais
ce pref-là reste le défaut Claude pour toutes les sessions sur
l'écosystème.

Lié à [[convention_storage_var_uploads]] (un exemple emblématique
d'application de cette philosophie).
