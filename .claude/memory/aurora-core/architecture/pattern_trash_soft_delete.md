# Corbeille : soft delete par module, purge partagée

## Règle

Une entité dont la suppression détruit du travail irremplaçable porte un
`deletedAt` et non un `remove()` direct. Le geste utilisateur (`delete()`)
devient réversible, et un second verbe (`forceDelete()`) porte la destruction.

Trois obligations vont avec la colonne :

1. **Tous les finders visibles filtrent** `deletedAt IS NULL` : listing,
   recherche, statistiques de contenu, et tout ce que voit le front public.
   Un document en corbeille qui reste visible quelque part est le bug que ce
   pattern doit empêcher.
2. **Les finders "physiques" ne filtrent pas** : ce qui décide si un fichier
   est encore référencé (`filterPathsInUse`, `countOnDisk` en GED) doit compter
   les lignes en corbeille, sinon la purge d'un voisin efface le fichier d'un
   document restaurable.
3. **La purge passe par le même réglage** `ApplicationParameterEnum::TrashAutoPurgeDays`
   que la corbeille des publications, via un `RecurringMessageProvider` du
   module. Jamais un délai maison.

La corbeille est un **filtre sur la liste existante** (`?trashed=1` porté
jusqu'au repository), pas un écran séparé : les autres filtres continuent de
fonctionner à l'intérieur, et il n'y a qu'une forme de payload à maintenir.

## Pourquoi

Parce que les deux moitiés du geste ont des conséquences opposées. Effacer la
ligne et les octets d'un seul coup, c'est une action que personne ne peut
annuler sur un fichier qui n'existe souvent nulle part ailleurs. Les séparer
donne à l'utilisateur une fenêtre de rattrapage, et au système un endroit unique
où la destruction arrive vraiment.

Deux délais de rétention différents seraient deux promesses faites à la même
personne sans le lui dire : d'où le réglage partagé.

## Comment l'appliquer

Implémenté en GED (documents), en Editorial (publications), en Notes et en
Studio (livrables, espaces clients, contenus : voir plus bas). Pour un nouveau
module, suivre `Module/Ged/Document` :

- entité : `deletedAt` + `isTrashed()`, colonne indexée (le `IS NULL` est sur
  le chemin chaud de tous les listings) ;
- repository : paramètre `bool $trashed` sur le finder paginé, plus
  `countTrashed()`, `findAllTrashed()`, `findTrashedBefore()` ;
- manager : `delete()` (soft, idempotent), `restore()`, `forceDelete()`,
  `emptyTrash()`, `purgeTrashedBefore()`, et un `destroy()` protégé partagé par
  les deux derniers pour que la purge et les boutons ne divergent jamais ;
- contrôleur : `?trashed=1` sur l'index, plus `restore`, `force-delete`,
  `bulk-restore`, `empty-trash`, toutes sous le privilège `delete` ;
- audit : `x.trashed` et `x.restored` en plus de `x.deleted`, avec leur libellé
  dans `Module/Dev/Audit/translations` (un test le vérifie) ;
- front : la corbeille est une vue dans `useDocumentFilters`, hors de
  `hasActiveFilter` et de `resetFilters` (réinitialiser les filtres ne doit pas
  faire sortir de la corbeille).

### Une unicité doit devenir partielle

Si l'entité porte une colonne `unique` que l'utilisateur choisit (slug, code,
référence saisie), la corbeille la garde en otage : recréer une catégorie
« factures » échouerait sur une contrainte, à cause d'une ligne que rien
n'affiche. Ne pas maquiller la valeur en la préfixant - c'est du code qui se
souvient d'une règle que la base sait dire :

```sql
DROP INDEX uniq_<ancien>;
CREATE UNIQUE INDEX uniq_<x>_live ON <table> (slug) WHERE deleted_at IS NULL;
```

Côté mapping, retirer `unique: true` de la colonne et déclarer sur la concrete
`#[ORM\UniqueConstraint(name: …, columns: ['slug'], options: ['where' => '(deleted_at IS NULL)'])]`,
sinon le schéma repart en unicité totale au premier diff. Le finder qui teste
la disponibilité (`slugExists`) doit filtrer les vivants lui aussi, et la
restauration ne recalculer la valeur **que** si elle a été prise entre-temps :
changer une adresse que personne ne disputait casse des liens pour rien.
Couvert par `CategorySlugIsUniqueAmongTheLivingTest`, en intégration parce que
seul PostgreSQL applique la règle.

### Une cascade doit se souvenir d'où elle vient

Quand supprimer un parent emporte ses enfants (dossier GED), chaque ligne qui
tombe enregistre **qui** l'a emportée (`trashed_with_folder_id`). Restaurer le
parent ne remonte que ce qui porte son id : sans ça, une restauration
ressuscite ce que quelqu'un avait supprimé à la main des jours plus tôt, et
déduire la différence des horodatages est une devinette. La suppression
définitive du parent libère ce qui était tombé avec lui plutôt que de le
détruire : les enfants sont la part que personne n'a demandé à perdre.

### Studio : livrables, espaces clients, contenus

Les trois suivent la même forme, plus courte que la GED : pas de filtre
`?trashed=1` sur la liste, l'écran commun de la corbeille (General) lit une
`TrashSourceInterface` par type et poste vers les routes `restore`,
`force-delete`, `empty-trash` du module. Le manager porte `trash()`,
`restore()`, `forceDelete()`, `purgeTrashedBefore()` (le verbe `delete()` a
disparu des interfaces des espaces et des contenus, pour qu'aucun appelant
ne confonde les deux gestes).

- **Un parent à la corbeille cache ses enfants sans les toucher** : un espace
  à la corbeille n'est vu de personne (`SpaceVisibility::canSee()`), ce qui
  ferme d'un coup ses écrans (`SpaceVisibilitySubscriber`, sur les arguments des contrôleurs), ses livrables
  et sa recherche ; sa page client et ses liens tombent dans
  `resolveUsable()`. Les contenus d'un espace à la corbeille ne sont pas
  listés dans la corbeille des contenus : ils reviennent avec lui.
- **Un enfant à la corbeille garde son parent intermédiaire** : un contenu
  garde son étape ; supprimer l'étape (permis si plus aucun contenu *vivant*)
  range ceux de la corbeille dans la première étape restante avant la
  cascade, sinon la cascade de l'étape les détruirait.
- **Un parent à la corbeille bloque toujours ce qui le nomme** : un client
  n'est pas supprimable tant qu'un de ses espaces, même à la corbeille,
  existe (`countForCustomer()` ne filtre pas, c'est un finder « physique »).
- Tests : `DeliverableTrashTest`, `CustomerSpaceTrashTest`,
  `SpaceContentTrashTest`.

Piège rencontré : le wording de confirmation. `delete_warning` disait « Cette
action est irréversible » alors qu'elle ne l'est plus. Le message irréversible
appartient désormais à la suppression définitive.
