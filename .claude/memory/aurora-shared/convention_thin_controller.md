---
name: convention_thin_controller
description: Les controllers Aurora doivent rester ultra-fins (routing, auth, DTO → Manager/Service → Serializer → JSON response). Toute logique métier va dans un Manager (mutations + flush) ou un Service (stateless, calculs purs). Convention dure rappelée par l'utilisateur le 2026-05-16, à ne plus oublier.
metadata:
  type: feedback
---

## Règle

**Un controller Aurora ne fait QUE 5 choses, dans cet ordre :**

1. **Résoudre l'utilisateur courant** (`$this->getUser()`)
2. **Charger l'entité ou parser le payload** via une Factory de DTO
3. **Valider** (`PayloadValidator`)
4. **Déléguer** à un Manager (mutation + flush) ou Service (calcul / lecture)
5. **Sérialiser et renvoyer** via le Serializer + `jsonSuccess()` / `jsonInvalidInput()` / `jsonNotFound()`

**Tout le reste - calcul, transformation, orchestration, branchement métier, hydration côté entité, audit, side-effects - est interdit dans le controller.**

## Manager vs Service : quel choix ?

| Cas | Couche | Pourquoi |
|---|---|---|
| Crée / met à jour / supprime une entité (persist + flush) | **`Manager/`** | Cycle de vie d'entité, audit log attaché, hooks d'extension `protected create<X>()`, `applyInput()`, `auditPayload()` |
| Réordonne / déplace / merge / split plusieurs entités liées | **`Manager/`** | Mutation orchestrée, flush en batch |
| Calcul pur sans I/O ni state (validation cross-fields, normalisation, formatage, scoring, traduction de codes) | **`Service/`** | Stateless, testable sans BDD, pas de flush |
| Détection de cycle, résolution de hiérarchie, contraintes de graphe | **`Service/`** | Pure logique sur des données fournies (cf `MarkdownNoteHierarchyService`) |
| Lecture complexe / agrégation cross-entité | **Repository** ou **Service** | Pas de mutation, pas dans le Manager |
| Construction du payload d'une vue (charge, mappe, sérialise) | **`View/<X>ViewBuilder.php`** | Pré-calcul des URLs + données initiales pour le rendu Twig+Vue |

**Règle de décision rapide** : *Le code persiste-t-il (set→flush) en BDD ?* → Manager. *Calcul pur ?* → Service.

## Squelette d'un controller conforme

```php
#[Route('/backend/widget/{id}/duplicate', name: '_duplicate', methods: ['POST'])]
public function duplicate(int $id, Request $request): JsonResponse
{
    /** @var CoreUserInterface $user */
    $user = $this->getUser();

    // 1. Charger l'entité (ownership scoping côté repo)
    $widget = $this->repository->findOneByUserAndId($user, $id);
    if (!$widget instanceof WidgetInterface) {
        return $this->jsonNotFound();
    }

    // 2. Parser + valider l'éventuel payload
    $input = $this->inputFactory->fromArray($this->decodeJson($request));
    $errors = $this->payloadValidator->errors($input);
    if ([] !== $errors) {
        return $this->jsonInvalidInput($errors);
    }

    // 3. Déléguer (toute la logique vit ici)
    $clone = $this->manager->duplicate($widget, $input);

    // 4. Sérialiser et répondre
    return $this->jsonSuccess(['widget' => $this->serializer->serializeDetail($clone)]);
}
```

## Anti-patterns à supprimer dès qu'on les voit dans un controller

| Smell | Correctif |
|---|---|
| `foreach (...) { ... }` qui calcule / filtre / transforme | Pousse dans un Service (`<Module>Calculator`, `<Entity>Filter`) ou méthode de Repository |
| Détection conditionnelle métier (`if ($widget->isLocked() && ...)`) | Pousse dans le Manager ou le Service de logique métier |
| Construction inline d'un tableau structuré pour la réponse (`['foo' => ..., 'bar' => ..., 'computed' => $x * $y]`) | Pousse dans le Serializer (`serializeXxx()`) |
| Code de normalisation du payload (trim, lower, dedupe, default) | Pousse dans la Factory du DTO (`<X>InputFactory::fromArray()`) |
| Manipulation de l'EntityManager (`persist`, `flush`, `remove`, `getRepository`) | **Toujours** dans un Manager |
| Auditing inline (`$this->auditLogger->log(...)`) | Dans le Manager via `auditCreated/Updated/Deleted` hooks |
| Construction d'URLs (`urlGenerator->generate('...')`) sortie de l'`indexView()` | Pousse dans le ViewBuilder |
| Reshape du résultat d'un Service (ex: histogramme `[k => v]` → `[{k, v}]`) | Méthode du Serializer (`serializeXxxCounts`) |

## Limite de taille

Un controller backend complet (CRUD + 2-3 actions métier) ne devrait **pas dépasser ~250 lignes** au total. Un controller à 400+ lignes signale qu'il a absorbé de la logique qui appartient à un Manager/Service.

### Splitter par sous-domaine quand le controller dépasse

Au-delà de **~10 endpoints** ou **~250 lignes**, splitter par **sous-domaine cohérent** plutôt que de tout entasser. Critère du split : un groupe d'endpoints qui forme une **feature cross-cutting** vs le CRUD de l'entité racine.

Exemple : `Module/Notes/Markdown/Controller/Backend/`
- `MarkdownNotesController` - CRUD note + actions liées à une note (move, backlinks, mentions, graph, reorder)
- `MarkdownTagsController` - opérations cross-cutting sur les tags (list/rename/merge/delete) qui touchent N notes simultanément

**Préserver les noms de routes** lors d'un split : si l'ancien controller avait `backend_notes_markdown_tags_list`, le nouveau controller doit conserver ce nom exact via `#[Route('/backend/notes/markdown/tags', name: 'backend_notes_markdown_tags')]` au niveau classe + `name: '_list'` au niveau méthode. Sinon le ViewBuilder + les `urlGenerator->generate(...)` callers cassent silencieusement.

### Piège du `/{id}` après split

Quand on splite et qu'un controller garde `/{id}` (route show générique) et qu'un autre nouveau controller introduit `/tags` (ou tout segment littéral), **l'ordre de chargement des controllers par Symfony** peut faire matcher `/{id}` AVANT `/tags`. Résultat : `GET /backend/notes/markdown/tags` est routé vers `show($id='tags')`.

**Correctif obligatoire** : ajouter `requirements: ['id' => '\d+']` sur la route `/{id}`. Si le ViewBuilder génère encore l'URL avec un placeholder template comme `__id__`, étendre la requirement : `'\d+|__id__'`.

```php
#[Route('/{id}', name: '_show', requirements: ['id' => '\d+|__id__'], methods: [HttpMethodEnum::Get->value])]
public function show(int $id): JsonResponse { ... }
```

Vérification rapide :

```bash
find src/Module src/Core -path "*/Controller/*.php" -exec wc -l {} \; | sort -rn | head -10
```

Si une méthode dépasse **30 lignes**, c'est qu'elle fait trop. Découper.

## Pourquoi cette règle

**Why:** Aurora est un bundle distribué. Un client qui étend une feature doit :
- Override le **Manager** pour changer un comportement métier (créer / persister différemment).
- Override le **Service** pour changer un calcul.
- Override la **Factory** pour ajouter un champ au DTO.
- Override le **Serializer** pour ajouter un champ à la réponse.
- Override le **ViewBuilder** pour ajouter une variable au template.

**Mais le controller ne devrait JAMAIS avoir besoin d'être override.** Si la logique métier vit dans le controller, le client devra dupliquer la route entière pour étendre un détail - perte d'extensibilité.

**Le controller est l'orchestrateur HTTP. Son rôle est de connecter routing → DTO → Manager/Service → Serializer.** Rien d'autre.

**How to apply:**

### À l'écriture d'une nouvelle action

Pose-toi la question avant chaque ligne dans le controller :
- *"Cette ligne fait-elle de la BDD ?"* → Manager
- *"Cette ligne calcule quelque chose ?"* → Service
- *"Cette ligne transforme un résultat ?"* → Serializer
- *"Cette ligne parse un payload ?"* → Factory de DTO
- *"Cette ligne charge des URLs / données initiales pour le template ?"* → ViewBuilder

Si la réponse est **oui** à une de ces questions, **sors la ligne du controller**.

### Au refacto / audit

```bash
# Audit pour repérer les controllers gros / méthodes longues
find src -path "*/Controller/*.php" -exec wc -l {} \; | sort -rn | head -10
```

À chaque controller > 300 lignes ou méthode > 30 lignes, faire un pass de
nettoyage : extraire vers Service / Manager / Serializer / Factory selon
le smell observé.

## Référence

Convention rappelée par l'utilisateur le **2026-05-16** :
*"controllers doivent avoir que le stricte minimum, la logique métier
doivent etre dans des services quand non mutable, et managers quand
mutable (set / flush etc)"*.

Voir aussi (mémoires `aurora-core/backend/`, applicables uniquement quand on écrit du code dans le bundle core) :
- `convention_dto_factory.md` - pattern Input + InputFactory + AsAlias (extensibilité Sylius-style)
- `convention_extensibility.md` - résumé exécutif des 5 couches
- `structure_manager_vs_service.md` - distinction détaillée Manager / Service
