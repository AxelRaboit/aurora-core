---
name: Studio - la fiche client s'écrit sur sa page, et seulement là
description: Une page par client (suite_studio_customers_show) porte toute la fiche et est le seul chemin d'écriture ; l'onglet Informations d'un espace est en lecture ; Customer.user retiré
type: project
---

## Règle

La fiche d'un client (identité légale, représentant, contact, SIREN, fixe,
liens, notes) **s'écrit à un seul endroit** : sa page,
`/suite/studio/customers/{id}` (`suite_studio_customers_show`, lue avec
`studio.customers.view`), qui enregistre par `suite_studio_customers_update`
(`studio.customers.edit`) avec `CustomerInput` et `CustomerManager::update()`.
La liste ne modifie plus (« Ouvrir », nom cliquable) ; la fenêtre de création
garde le même `CustomerFormFields`.

L'onglet Informations d'un espace est **en lecture** : `CustomerInformationCard`
(ce que le client voit, inchangé) et un lien « Modifier la fiche » vers la page,
rendu par le serveur (`customerPath`) seulement avec `customers.view` **et**
`customers.edit`. Sa route d'écriture (`workspace_space_information_save`) n'existe
plus.

Ce qui entoure un client (contrats, livrables de Studio, espaces) se calcule
dans `CustomerRelatedViewBuilder::related($customer, $exceptSpace)`, pour la
page comme pour l'onglet : `null` pour une liste que le lecteur ne peut pas
ouvrir, `DeliverableAccess::canRead()` pour les livrables, `visibleSpaces()`
pour les espaces.

`Customer.user` (« Compte utilisateur ») est retiré (06/10/2026,
`Version20261006220000`) : affiché, enregistré, lu par rien.

**Why:** deux formulaires écrivaient la même fiche avec deux listes de champs
différentes ; le SIREN, le fixe, les liens et les notes ne se saisissaient que
depuis un espace, le capital et le RCS que depuis la liste. Deux saisies
partielles obligeaient aussi deux écritures (`update` qui vide ce qu'il ne
reçoit pas, `updateInformation` qui n'écrit que ses colonnes) : la première
erreur de l'une aurait effacé l'autre moitié.

**How to apply:** un champ neuf de la fiche va dans `CustomerInput` (+ fabrique,
`applyInput`, `CustomerSerializer`, `customerFormModel.js`,
`CustomerFormFields.vue`) ; s'il est lu par le client, aussi dans
`CustomerInformationSerializer`/`CustomerInformationCard`. Ne jamais rouvrir une
écriture partielle de la fiche ailleurs (onglet d'espace, conversion mise à
part : `convertToClient()` ne touche que statut et adresse). Détail des
ruptures : `docs/aurora-client/MIGRATION_STUDIO.md` section 11.

## Liens

- [[project_studio_customer_spaces]] - l'espace client dont l'onglet
  Informations montre la fiche.
