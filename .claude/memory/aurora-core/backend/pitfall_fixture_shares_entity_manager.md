# Piège : en test d'intégration, la fixture et le code testé partagent l'entity manager

**Règle.** Dans une fixture de test, attacher un enfant par **les deux côtés**
(`$parent->addChild($child)`), pas seulement par le setter propriétaire
(`$child->setParent($parent)`). Sinon, rafraîchir le parent
(`$em->refresh($parent)` ou `$em->clear()`) avant d'exercer du code qui lit la
collection.

```php
// Suffisant pour la base, insuffisant pour le test
$field = new FormField();
$field->setForm($form);

// Les deux côtés
$field = new FormField();
$form->addField($field);
```

**Pourquoi.** La ligne est écrite dans les deux cas - ce n'est pas un problème
de persistance, et `orphanRemoval` n'y est pour rien (vérifié le 14/09/2026 :
même flush, côté propriétaire seul, la ligne est bien en base).

Ce qui change, c'est la **copie en mémoire**. Un parent neuf a sa collection
déjà initialisée et vide ; le setter propriétaire ne la remplit pas. Or un
handler ou un service appelé en ligne depuis le test lit à travers **le même**
entity manager, donc la même copie : il voit un parent sans enfants, alors que
la base en a.

**Ce que ça coûte.** Constaté en écrivant `FormSubmissionNotificationTest` : le
handler appelé en direct recevait un formulaire sans champs, donc
`submitterEmail()` ne trouvait aucune adresse. Pas de mail de confirmation, pas
de `Reply-To` - un échec qui ressemble à un bug de la fonctionnalité, pas de la
fixture. Une demi-heure perdue à chercher au mauvais endroit.

**Comment l'appliquer.**

1. Fixture de test qui construit un graphe d'entités → `addChild()`, toujours.
2. Si la fixture ne peut pas (setter propriétaire imposé), `refresh()` le
   parent avant l'acte de test. `MenuSectionHighlightTest` fait exactement ça,
   et son commentaire explique pourquoi.
3. Attention au cas mixte : une requête HTTP dans un test **redémarre le
   kernel**, donc le serveur répond depuis un autre entity manager que celui de
   la fixture. Les deux copies coexistent, et lire la collection côté test ne
   dit rien de ce que le serveur a vu. Garder les identifiants plutôt que les
   collections.
