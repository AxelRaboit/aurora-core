---
name: project_accounting_contract_seal
description: Le module Accounting scelle un contrat avant de l'envoyer. Six mesures tiennent la preuve, et aucune n'est décorative : les toucher change ce que vaut une signature déjà recueillie.
metadata:
  type: project
---

## Ce que le module promet

Un contrat préparé dans le back-office part par un lien public, se signe en
ligne, se contresigne, et produit un PDF signé. La promesse juridique est
volontairement modeste et honnête : **signature électronique simple** au sens de
l'article 1367 du Code civil et de l'article 25 du règlement eIDAS. Elle est
recevable, et sa force dépend de la preuve qui l'accompagne. Pas de signature
avancée ni qualifiée : ça demande un prestataire de service de confiance, et
prétendre le contraire serait vendre ce que le code ne fait pas.

Le module vit sur `feat/accounting-module` puis dans la 0.9.82.

## Les six mesures qui tiennent la preuve

Elles répondent toutes à la même question : que vaut une signature si le
document a pu bouger après coup.

### 1. Le gel a lieu à l'envoi, pas à la signature

`ContractManager::freeze()` écrit **d'un seul appel** la référence, le snapshot
structuré, le HTML rendu, l'empreinte, l'algorithme, le numéro de forme
canonique et le statut.

**Why:** un contrat qui se scelle au moment de la signature laisse une fenêtre
entre « la personne a lu » et « la personne a signé ». Geler à l'envoi supprime
la fenêtre : ce qui est lu est déjà scellé.

**How to apply:** ne jamais ajouter d'écriture partielle du sceau. Une colonne
du sceau qui se remplit séparément est une fenêtre rouverte.

### 2. L'immuabilité est sur l'entité, pas dans le service

`AbstractContract` refuse ses setters une fois gelé, et
`AbstractContractTemplateVersion::assertEditable()` refuse tout après
publication. `AbstractContractSignature` refuse le moindre setter dès qu'elle a
un id.

**Why:** une règle tenue par « le service qui pense à appeler la bonne méthode »
tient jusqu'au premier nouveau chemin de code. Sur l'entité, il n'y a pas de
chemin.

**How to apply:** un nouveau setter sur ces entités appelle la garde en première
ligne. `setStatus()` sur le contrat est la seule exception, et elle est
documentée : tous les états d'après-gel passent par elle.

### 3. La forme canonique porte son numéro

`ContractCanonicalizer::VERSION` est stocké **avec** chaque contrat. Quatre
règles écrites : clés triées récursivement, listes dans leur ordre, pas
d'échappement de slash ni d'unicode, espaces coupants réduits - en préservant
l'espace insécable, qui est de la typographie française et pas du blanc.

**Why:** sans numéro stocké, changer la canonicalisation rend faux tout
l'historique d'un coup, sans distinguer « altéré » de « plus vérifiable ».

**How to apply:** toute modification des règles incrémente `VERSION`. Les
contrats antérieurs se vérifient sous la leur ou se déclarent non vérifiables,
jamais altérés.

### 4. Le HTML rendu est conservé à côté du snapshot

L'empreinte couvre les deux (`ContractSeal` est le seul endroit qui le dit).

**Why:** le snapshot dit ce que contenait le document, le HTML dit ce que la
personne a vu. Re-rendre depuis le snapshot pour l'afficher ou pour le PDF, ce
serait faire confiance au moteur du jour.

**How to apply:** la page publique et le PDF impriment `renderedHtml` tel quel.
Aucun re-rendu, jamais.

### 5. La langue faisant foi est dans le document scellé

La clause est rendue dans le HTML, après la dernière partie, et donc hachée.
Elle est obligatoire dès qu'une version porte une deuxième langue.

**Why:** un encart posé par le gabarit de page serait vrai aujourd'hui, absent
du PDF que le client garde, et hors de l'empreinte - le seul endroit où une
clause sur l'autorité du texte ne doit pas être.

**How to apply:** tout ce qui a valeur d'engagement passe par le renderer, pas
par un template Twig.

### 6. `aurora:contracts:verify` est l'alarme

Recalcule l'empreinte de tout le stock, sort en 1 au moindre écart, et sépare
« altéré » de « non vérifiable sous cette forme canonique ».

**How to apply:** à brancher sur le scheduler le jour où il y a du volume. Un
sceau que personne ne vérifie est une case cochée.

## Le lien public : sélecteur + jeton haché

`selector` (16 octets, en clair, unique, indexé) et `hashedToken` (SHA-256 de 32
octets). Le jeton en clair n'existe qu'en mémoire, le temps de composer l'URL.

**Why:** une fuite de base ne doit pas permettre de signer. Et un mot de passe
sur la page ne protégerait rien : il partirait dans le même mail que le lien.
C'est l'OTP qui prouve quelque chose - le contrôle de la boîte contractuelle.

**How to apply:** comparaison par `hash_equals`, jamais `===`. Toutes les façons
d'échouer rendent la même page 404 : distinguer « inconnu » de « expiré »
renseigne un inconnu sur ses essais.

## L'ordre de signature, et pourquoi il a été retourné

**Le client signe le premier, le prestataire contresigne** et cette
contresignature conclut le contrat et déclenche le PDF.

**Why:** décision d'Axel déléguée puis retournée sur recommandation. Le document
étant scellé avant que quiconque le voie, aucune signature ne peut être
invalidée par une modification ultérieure - la classe de bugs disparaît au lieu
d'être gardée.

## L'export PDF, et pourquoi il ne contredit pas la mesure 4

Depuis la 0.9.162, la liste des contrats a une action « Exporter en PDF »
(`/backend/studio/contracts/{id}/export`). Elle rend **trois** réponses, et
l'ordre des tests est la sécurité :

1. contrat conclu → les octets stockés, tels quels, jamais un re-rendu ;
2. contrat scellé → `renderedHtml` imprimé verbatim, comme la mesure 4 l'exige ;
3. brouillon → l'aperçu du manager, parce qu'il n'y a encore rien de scellé.

**Why:** un contrat se lit sur papier bien avant d'être signé, et le seul PDF du
module était celui frappé à la contresignature. Le risque n'est pas de produire
un fichier, c'est d'en produire un qui ressemble au signé.

**How to apply:** la copie de travail porte un bandeau en première page, n'a pas
de bloc de preuve, pas de cadre de signature vide, et un nom de fichier
suffixé (`CTR-2026-0001-projet.pdf`). `ContractPdfGenerator::renderProvisional()`
**refuse** un contrat qui a déjà son fichier : c'est la garde qui empêche qu'une
route serve un sosie. La route `/pdf` garde son sens exact - le fichier signé, ou
404 - parce que c'est elle que la page document annonce comme « le PDF signé ».

## Ce qui reste ouvert, et n'est pas un oubli

- **Horodatage RFC 3161** par un tiers. Aujourd'hui l'heure est celle du serveur.
- **Durées de conservation** et base RGPD de l'IP et de la trace de signature.
- **Bouton de refus explicite** : un signataire ne peut que signer ou ignorer.
- **Avenants** et **relances automatiques**.
- **Import des trames réelles depuis Craft**, volontairement après la mise en
  production : les trames de production ne servent pas de jeu d'essai.

Voir aussi [[project_notes_share_link_read_only]] et
[[project_planning_share_link_write_access]] : même mécanique de lien invité,
même arbitrage sur les limites de débit, arbitré ici dans le sens de l'écriture
parce que la signature est justement une écriture.
