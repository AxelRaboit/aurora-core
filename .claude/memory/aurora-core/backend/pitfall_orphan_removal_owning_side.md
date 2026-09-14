# Piège : `setParent()` seul sur une collection `orphanRemoval` efface l'enfant

**Règle.** Sur une association dont la collection porte `orphanRemoval: true`,
attacher l'enfant par le setter de son côté propriétaire **ne suffit pas**. Il
faut passer par `addXxx()` du parent, qui met les deux côtés à jour.

```php
// Non : le flush qui devait l'enregistrer le supprime
$field = new FormField();
$field->setForm($form);

// Oui
$field = new FormField();
$form->addField($field);
```

**Pourquoi.** Un parent fraîchement construit a sa collection déjà
initialisée, et vide. `orphanRemoval` dit à Doctrine que tout ce qui n'est pas
dans la collection n'a plus de raison d'exister : l'enfant part au flush qui
était censé le créer. Aucune erreur, aucune exception - juste un parent sans
enfants.

**Ce que ça coûte quand c'est dans un test.** Constaté le 14/09/2026 sur
`FormCaptchaTest` : le formulaire soumis n'avait en réalité aucun champ. Un
formulaire sans question accepte une charge vide, donc la requête répondait
200, le test passait, et il ne prouvait rien de ce qu'il annonçait. Le défaut
n'est apparu qu'en écrivant un test qui lisait le *contenu* de la soumission.

**Comment l'appliquer.**

1. Écrire une fixture ou un test qui attache un enfant → chercher
   `orphanRemoval` sur la collection avant de choisir le setter.
2. Doute sur un test vert : lui faire affirmer quelque chose sur les données
   enregistrées, pas seulement sur le code HTTP ou sur un compte de lignes.
3. Concernés aujourd'hui dans Editorial/Form : `Form::$fields` (orphanRemoval)
   et `Form::$translations` (orphanRemoval). `Form::$submissions` ne l'a pas,
   donc `setForm()` y suffit.
