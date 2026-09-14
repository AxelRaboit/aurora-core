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
5. **`Anonymous` n'est cachable que grâce à une opt-out explicite.** Le
   `SessionListener` de Symfony réécrit `Cache-Control` à la fin de toute
   requête qui *lit* la session - la condition est `getUsageIndex() !== 0`,
   **pas** `isStarted()` - et ici `LocaleSubscriber` la lit à chaque requête
   pour choisir la langue. Pire, le listener calcule
   `$maxAge = hasCacheControlDirective('public') ? 0 : getMaxAge()` : déclarer
   la réponse publique est précisément ce qui ramène le cache à zéro.
   `BinaryFileServer::servePublic()` pose donc
   `AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER`, l'échappatoire que
   Symfony documente pour ce cas ; Symfony la retire avant l'envoi. Mesuré en
   prod le 13/09/2026 : chaque image publique repartait en
   `max-age=0, must-revalidate, private`. Ne jamais poser cette opt-out sur
   `serve()`, dont les appelants sont gatés.

   À noter : `_stateless` sur la route **ne corrige rien**. Dans le listener,
   le `return` lié à `_stateless` est *après* le bloc de cache ; il ne fait
   que lever une exception en debug ou logger un avertissement.

6. **Le générateur d'URL doit suivre.** Un générateur qui ne reçoit qu'une
   clé ne sait produire que l'adresse publique ; pour une entité à statut il
   faut voir l'entité (`DocumentUrlGenerator::routeFor()`). Sinon l'URL se
   construit sans erreur et répond 404 en production.

**Avant de déployer chez un client existant** : `aurora:ged:audit-public-documents`
liste les documents qu'une page publique référence sans être `published`. Le
statut par défaut d'un upload est `draft` (`useDocumentsForm.js`), et le logo,
le favicon et l'og:image par défaut sont résolus par id sans filtre de statut
(`SiteBrandingExtension`) - un logo en brouillon est le cas attendu, pas
l'exception.

**`Restricted` n'a aucun producteur livré.** Les deux guards d'aurora-core
répondent `Anonymous` ou `Denied` : le troisième cas est le vocabulaire offert
à l'aire d'un client, pas du code mort à supprimer. Depuis la 0.9.168 la suite
enregistre son propre producteur sous `when@test`
(`tests/Integration/Support/RestrictedAreaUploadAccessGuard.php`, préfixe
`restricted-test/`) et vérifie la branche locale : servi, `private`, jamais
`immutable`.

**Ce qui reste non couvert, et pourquoi.** La moitié distante - les en-têtes
privés de `streamThrough()` et la règle « un fichier restreint n'est jamais
redirigé, quel que soit le mode de livraison » - n'est atteignable par aucun
double. `UploadsServeController::serveRemote()` se restreint sur la classe
concrète `R2StorageAdapter`, qui est `final readonly`, et `StorageManager`
indexe les adaptateurs par un `StorageDiskEnum` unique : un faux adaptateur ne
passe pas le `instanceof`, donc un test écrit avec lui passerait même si la
condition `Anonymous` disparaissait - c'est une assurance fausse, pire que pas
de test. Deux sorties le jour où ça compte : remplacer le `instanceof` par une
capacité (`SupportsDirectLinks`), ou couvrir sous `make test-r2` avec un vrai
bucket.

Tests : `tests/Integration/Controller/UploadsServeAccessTest.php` (22 cas,
dont draft/published, variants, vignettes, corbeille, permalink, contrats et
les trois cas restreints).
