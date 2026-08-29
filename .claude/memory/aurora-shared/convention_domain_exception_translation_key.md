---
name: convention-domain-exception-translation-key
description: Pour les rejets métier surfacés au front via JSON, déclarer une DomainException dédiée + constante TRANSLATION_KEY. JAMAIS de str_starts_with sur le message.
metadata:
  type: feedback
---

Quand un Manager lève une erreur métier (transition d'état invalide,
contrainte non remplie, etc.) qu'on veut **traduire côté front avec une
clé i18n**, créer une classe d'exception dédiée qui :

1. Étend `\DomainException`
2. Vit dans `Aurora\Module\<Module>\<SubModule>\Exception\`
3. Expose la clé i18n via une **constante `public const string TRANSLATION_KEY`**
4. Accepte les données contextuelles (ids, valeurs) en `public readonly` properties

Le controller catche **l'exception typée** et renvoie
`$this->jsonFailure($e::TRANSLATION_KEY)`.

**Exemples existants** (tous suivent ce pattern) :
- `Aurora\Module\Photo\Gallery\Exception\MaxPicksReachedException`
- `Aurora\Module\Configuration\Setting\Exception\CascadeViolationException`
- Côté client `aurora-welding` : `App\Module\Welding\WorkflowStep\Exception\RequiredTasksUndoneException`
  (le module a été extrait d'aurora-core, l'exception vit maintenant dans le client)

**Why** : sans typage, on est tenté de faire
`if (str_starts_with($e->getMessage(), 'mymodule.'))` pour identifier les
erreurs "i18n-friendly". C'est fragile : ça mélange la sémantique du
message (debug humain) avec un canal de communication (clé de traduction),
casse à la moindre refacto du wording, et ne se voit pas dans le typage.

**How to apply** :
- Manager : `throw new <X>Exception(...)` avec les ids/valeurs en props
  publiques pour le debug - le message reste lisible pour humain (logs,
  stack traces).
- Controller : `try { ... } catch (<X>Exception) { return $this->jsonFailure(<X>Exception::TRANSLATION_KEY); }`.
- Anti-pattern : capter `RuntimeException` générique + sniffer le message.
- Anti-pattern : mettre la clé i18n dans le `$message` du parent constructor.

Lié : [[convention_thin_controller]] (le controller mappe domaine ↔ HTTP,
il ne décide pas du wording - la clé de trad est exposée par le domaine).
