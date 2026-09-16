---
name: convention-domain-exception-translation-key
description: Les rejets metier surfaces au front passent par une exception typee (FieldException si le refus vise un champ), attrapee par son type. JAMAIS de str_starts_with sur le message.
metadata:
  type: feedback
---

> **Corrigé le 2026-09-16 par mesure.** Ce document prescrivait une constante
> `public const string TRANSLATION_KEY` sur chaque exception, et citait trois
> classes « qui suivent toutes ce pattern ». Aucune exception du dépôt ne
> porte cette constante - ni celles qui existent encore, ni celle qui était
> donnée en exemple (`CascadeViolationException`). La convention décrite
> ci-dessous est celle qu'on peut lire dans le code.

Ce qui tient, et qui est le vrai fond : **le type porte le sens, jamais le
message**. Le reste suit deux formes selon qui refuse.

## Forme 1 - le refus vise un champ : `FieldException`

`Aurora\Core\Validation\Exception\FieldException` (elle étend
`InvalidArgumentException`) transporte **le nom du champ** et un message
**déjà traduit** - il est écrit pour un humain, pas relu plus loin.

```php
// Manager
throw new FieldException('parent', $this->translator->trans('backend.x.errors.cycle'));

// Controller
catch (FieldException $e) {
    return $this->jsonInvalidInput([$e->getField() => $e->getMessage()]);
}
```

C'est la forme par défaut : un éditeur à qui on dit « cette cible n'existe
pas » sans lui dire sous quel champ ne sait pas quoi corriger.

## Forme 2 - le refus vise l'opération : exception typée nue

Quand le refus ne se rattache à aucun champ (un contrat gelé, une version
publiée, une étape déjà franchie), l'exception ne porte **ni clé ni
message traduit** : elle est attrapée par son type et le contrôleur décide
de la réponse dans une petite méthode nommée.

```php
// src/Module/Studio/Contract/Exception/FrozenContractIsImmutableException.php
final class FrozenContractIsImmutableException extends LogicException
{
    public static function forContract(?int $id, ?string $reference): self { /* message pour les logs */ }
}

// ContractsController
catch (FrozenContractIsImmutableException) {
    return $this->frozenRefusal();
}

private function frozenRefusal(): JsonResponse
{
    return $this->jsonInvalidInput([
        'status' => $this->translator->trans('backend.studio.contracts.errors.already_frozen'),
    ]);
}
```

**Pourquoi la clé est côté contrôleur et pas côté domaine** : la même règle
métier se refuse différemment selon l'écran qui la heurte, et le domaine n'a
pas à connaître le wording. Le message de l'exception, lui, reste écrit pour
les logs et les stack traces - c'est sa seule fonction.

`LogicException` plutôt que `DomainException` quand aucune action utilisateur
ne devrait pouvoir l'atteindre : elle répond à un onglet périmé ou à une
requête rejouée, pas à une erreur de saisie.

## Ce qui reste interdit

- Attraper une `RuntimeException` générique et renifler son message.
- `str_starts_with($e->getMessage(), 'mymodule.')` pour reconnaître les
  erreurs « traduisibles » : ça mélange la sémantique du message (debug) avec
  un canal de communication, et ça casse au premier changement de wording.
- Mettre une clé i18n dans le `$message` du constructeur parent.

## Exemples vivants (mesurés le 2026-09-16)

- `Aurora\Core\Validation\Exception\FieldException` - la forme 1
- `Aurora\Module\Studio\Contract\Exception\{FrozenContractIsImmutable,
  PublishedVersionIsImmutable,ContractPdfAlreadyGenerated,UnrenderableBlock}Exception`
- `Aurora\Module\Studio\Contract\Signature\Exception\SignedContractIsImmutableException`
- `Aurora\Module\Configuration\Setting\Exception\CascadeViolationException`
  (données contextuelles en `public readonly`, pas de clé)

Lié : [[convention_thin_controller]].
