# Pattern - gater ce que `/uploads/{path}` accepte de servir

**Règle** : le catch-all `UploadsServeController` ne décide plus seul. Il
demande à `UploadAccessDecider`, qui interroge les `UploadAccessGuardInterface`
enregistrés (tag `aurora.upload_access_guard`, posé dans `config/services.yaml`
sur l'interface). Le premier guard qui réclame la clé répond, par
`UploadAccessEnum` : `Anonymous` (servi à tous, cachable, redirections
permises), `Restricted` (servi mais streamé par l'application, cache privé,
jamais redirigé) ou `Denied` (404, le même que pour un fichier absent).

**Pourquoi** : jusqu'à la 0.9.140 l'endpoint servait n'importe quel fichier
sous `var/uploads` à n'importe qui. Conséquences mesurées le 13/09/2026 :

- tout document GED était téléchargeable en devinant son chemin, ou son id
  via `/document/{id}` dont la séquence s'énumère ;
- les PDF de contrats signés aussi, et leur chemin est
  `contracts/<année>/<référence>.pdf` - une référence séquentielle. La route
  `/backend/studio/contracts/{id}/pdf` et son `IsGranted` se contournaient
  donc en passant par `/uploads/`. Le docblock de `storedPdf()` affirmait que
  le catch-all exigeait une session : il n'en exigeait aucune.

**Comment l'appliquer**

1. Une aire qui contient autre chose que du public écrit son guard. Voir
   `GedUploadAccessGuard` (seuls les `published` sont anonymes) et
   `ContractUploadAccessGuard` (rien ne sort par là, jamais).
2. **Une aire non réclamée reste anonyme.** Défaut délibéré : refuser
   l'inconnu casserait silencieusement un client qui stocke sous son propre
   préfixe, pour protéger des fichiers déjà protégés.
3. **Ne jamais poser un contrôle de privilège dans un guard.** Le firewall
   `admin` est `^/(backend|dev)` ; sur `/uploads/…` aucune identité backend
   n'existe, la question a toujours la même réponse. Le personnel lit par une
   route sous `/backend` - `backend_ged_files` pour la GED.
4. **Couvrir les fichiers dérivés.** Un variant
   (`<dir>/variants/<taille>/<stem>.webp`) et une vignette
   (`ged/thumbnails/Y/m/…`) ont leurs propres clés. `filterPathsInUse()` ne
   regarde que `filePath` et `thumbnailPath` : construire un contrôle dessus
   laisserait les variants ouverts, c'est-à-dire une copie lisible de chaque
   image retenue. `DocumentRepository::findStatusForPath()` couvre les trois
   formes, les variants par dérivation du chemin source.
5. **Le générateur d'URL doit suivre.** Un générateur qui ne reçoit qu'une
   clé ne sait produire que l'adresse publique ; pour une entité à statut il
   faut voir l'entité (`DocumentUrlGenerator::routeFor()`). Sinon l'URL se
   construit sans erreur et répond 404 en production.

**Avant de déployer chez un client existant** : `aurora:ged:audit-public-documents`
liste les documents qu'une page publique référence sans être `published`. Le
statut par défaut d'un upload est `draft` (`useDocumentsForm.js`), et le logo,
le favicon et l'og:image par défaut sont résolus par id sans filtre de statut
(`SiteBrandingExtension`) - un logo en brouillon est le cas attendu, pas
l'exception.

Tests : `tests/Integration/Controller/UploadsServeAccessTest.php` (18 cas,
dont draft/published, variants, vignettes, corbeille, permalink et contrats).
