# Auditer avant de généraliser une convention

## Règle

Avant d'appliquer une convention sur N entités (rollout massif), faire un
**pilote sur 4-5 entités structurellement diverses** + un audit
intermédiaire pour figer les variantes.

## Pourquoi

Une convention validée sur 1 entité (Agency) ne garantit rien. Sur Aurora,
le pilote large (Agency / Deal / User / Post / Order) a révélé **5 cas non
prévus** :

1. Sub-DTOs (PostTranslationInput) - sont-ils instrumentés ?
2. Manager à hooks multiples (User) - pas de `applyInput()` adapté
3. Cascade (OrderLine instancié par OrderManager) - combien de hooks ?
4. Composables Vue séparés (Deal create/edit) - un ou deux slots ?
5. Editor full-page (Post) - placement du slot ?

L'audit a permis de **réduire 5 variantes apparentes à 2 vraies variantes
structurelles** en reformulant les 3 autres comme règles dures dans la
convention. Sans cet audit, on aurait généralisé 5 variantes sur 24
entités → encyclopédie d'exceptions ingérable dans 6 mois.

## Comment l'appliquer

### Phase 1 : pilote large (3-5 entités)

Choisir des entités qui couvrent la diversité des cas :
- 1 standard simple (Agency)
- 1 avec cascade (Order, Menu)
- 1 avec hooks multiples (User)
- 1 avec UX complexe (Post editor full-page)
- 1 modulaire (Deal)

### Phase 2 : audit

Auditer rigoureusement :
- ✅ Conformité couche par couche (DTO/Manager/Serializer/Vue)
- ✅ Conventions de nommage
- ✅ Détection des variantes apparentes vs structurelles
- ✅ Détection des règles mal formulées (qui se présentent comme des
  variantes mais sont en fait des généralisations)

### Phase 3 : harmonisation pré-rollout

Pour chaque variante apparente :
- Reformulable comme règle ? → mettre à jour la convention
- Vraie contrainte structurelle ? → la documenter explicitement

### Phase 4 : rollout massif

Avec les variantes figées + règles dures, appliquer mécaniquement aux N
entités restantes. Une variante non-prévue qui apparaît pendant le
rollout = **stop**, retour à l'audit.

## Source

Méthode appliquée sur le rollout d'extensibilité Aurora (Septembre-Octobre
2025). 5 entités pilotes → audit → 4 règles dures fixées → rollout sur 19
entités restantes en 5 sessions sans surprise structurelle.

Cf l'historique de commits :
- Phase 1 : commits Agency/Deal/User/Post/Order
- Phase 2 : commit `5d3643d` (refacto issue audit)
- Phase 3 : commits `72e4989` + harmonisation styles DTO
- Phase 4 : 19 commits `feat: instrument <Module>`
