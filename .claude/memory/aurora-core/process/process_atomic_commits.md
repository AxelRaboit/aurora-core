# Commits atomiques par entité lors d'un rollout massif

## Règle

Lors d'un rollout d'une convention sur N entités, **un commit par entité**
(ou par module si les entités sont triviales). Pas de gros commit fourre-tout.

## Pourquoi

- **Bisect** : si une régression apparaît plus tard, `git bisect` permet
  d'isoler l'entité fautive.
- **Lecture** : la doc / commit log devient une narration de progression.
- **Tests verts à chaque commit** : si un commit casse les tests, on
  l'identifie immédiatement.
- **Reverter** : si une décision change, on peut reverter une seule
  entité sans toucher aux autres.

## Comment l'appliquer

### Format de commit

```
feat: instrument <Entité> per the extensibility convention

[Description courte du cas - variante user-style, cascade, etc.]

- <Name>InputInterface + <Name>InputFactory (#[AsAlias])
- <Name>ManagerInterface in Manager/, signatures use entity + DTO interfaces
- <Name>Manager non-final + protected DI + AsAlias + createX + applyInput
  + auditCreated/Updated/Deleted + auditPayload
- <Name>SerializerInterface, non-final + AsAlias
- Controllers + ViewBuilders updated to interfaces and factory-based input
  parsing
```

### Avant chaque commit

1. **Tests verts** : `php bin/phpunit` doit passer.
2. **Build OK** : `npm run build` si la couche Vue a changé.
3. **Pas de `Co-Authored-By` Claude** (préférence utilisateur).
4. **Pas de `--no-verify`** sur les hooks pre-commit.

### Pour les modules avec plusieurs entités triviales

OK de grouper plusieurs entités dans un commit **si** elles sont
instrumentées de manière identique et qu'aucune ne mérite d'être isolée.
Exemple : Crm/Company + Crm/Contact dans un seul commit (`7d3a1b7`).

### Suivi

Maintenir un tracker de progression (liste + hash de commit) pour visualiser
l'avancement lors d'un rollout massif.

## Préférence utilisateur

L'utilisateur a explicitement demandé "module par module avec commits
intermédiaires" au début du rollout, et "ne co-author pas" pour les
commits. À respecter.

## Source

Méthode appliquée pour le rollout d'extensibilité Aurora.
24 entités → ~24 commits, dont quelques-uns groupés (Crm 2 entités, Billing 4 entités, Project 11 managers).
