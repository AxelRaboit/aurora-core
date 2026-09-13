---
name: convention-storage-var-uploads
description: Tout fichier stocké par Aurora passe par StorageAdapterInterface, jamais par app.upload_dir. Le support par défaut reste var/uploads/<categorie>/, Apache ne sert rien directement, et la construction d'URL vit dans des services dédiés.
metadata:
  type: feedback
---

**Aurora stocke tous les fichiers** (médias éditoriaux, photos profil,
images notes, factures OCR, PDF signés, et toute future catégorie
ajoutée par un client) **sous `var/uploads/<categorie>/`**, hors
document root. **Aucun fichier n'est servable directement par Apache** -
chaque accès passe par un controller PHP qui délègue à
`Aurora\Core\Storage\BinaryFileServer` (path-traversal guard +
`BinaryFileResponse` + X-Sendfile en prod).

**Depuis la 0.9.127 : `var/uploads` n'est plus qu'un support parmi deux.**
Le disque reste le défaut et rien ne change pour une installation qui n'y
touche pas, mais un administrateur peut confier les fichiers à un stockage
objet. Ce qui suit vaut toujours ; ce qui change est **par où** on y accède.

**Why:** uniformiser la sécurité d'accès (auth granulaire par
catégorie possible), couper le "security through obscurity" des UUIDs
en `public/uploads/`, et préparer X-Sendfile pour les volumes prod.
Décision actée 2026-05-16 lors de la migration de `public/uploads/`
vers `var/uploads/`.

**How to apply** (côté aurora-core ET aurora-client) :

1. **Nouveau stockage** → `var/uploads/<categorie>/` (jamais `public/`)
2. **Service** qui écrit ou lit injecte `StorageManager` et demande
   `active()` pour écrire, `forDisk($entite->getStorageDisk())` pour lire
   ou supprimer. **Ne plus injecter `%app.upload_dir%`** : depuis la
   0.9.127 le stockage est une couche, et le dossier peut ne pas être un
   dossier. Le code qui exige un vrai nom de fichier (GD, `pdftoppm`, une
   pièce jointe) passe par `LocalWorkspace`.
3. **URL serve** : un controller dédié (route nommée `<module>_serve`)
   délègue à `BinaryFileServer::serve()` ou `servePublic()`. Le
   catch-all `/uploads/{path}` (UploadsServeController) couvre le cas
   public ; les catégories auth-gated (OCR, PDF, notes per-user)
   définissent leur propre route sous `/backend/<module>/files/...`
   qui prend précédence.

   **Depuis la 0.9.140, le catch-all n'est plus public par défaut pour
   une aire qui se réclame.** Il interroge `UploadAccessDecider`, et une
   aire pose sa règle en enregistrant un `UploadAccessGuardInterface`
   (tag `aurora.upload_access_guard`). Une aire que personne ne réclame
   reste anonyme, donc rien ne casse chez un client qui stocke sous son
   propre préfixe. `ged/` ne sert que les documents `published`,
   `contracts/` ne sert rien du tout.

   **Le firewall décide où la question peut être posée.** `admin` est
   `^/(backend|dev)` : sur `/uploads/…` aucune identité backend n'est
   restaurée, donc un guard qui testerait un privilège là refuserait le
   personnel comme les inconnus. Une catégorie qui doit rester lisible
   par le back-office a donc *besoin* de sa route sous `/backend`
   (cf. `backend_ged_files`) - ce n'est pas une préférence de style.
4. **URL construction** : injecter `UrlGeneratorInterface` ou un URL
   generator dédié (cf. `DocumentUrlGenerator`, `UserProfilePhotoUrlGenerator`
   comme exemples canoniques côté core). **Jamais** concaténer
   `'/uploads/...'` dans une entité - l'URL est presentation, pas
   domaine.
5. **Cleanup orphelines** : si la catégorie a un cycle de vie (notes
   images, OCR files, …), définir un hook `protected cleanup<X>` dans
   le Manager qui appelle `<X>Service::delete()`. Pattern de
   référence : `MarkdownNoteManager::cleanupOrphanedImages`.

**Performance prod :** `BinaryFileResponse::trustXSendfileTypeHeader(true)`
est déjà activé par `XSendfileBootSubscriber`. Apache (paquet
`libapache2-mod-xsendfile` + `XSendFile On` + `XSendFilePath
/var/www/<app>/var/uploads`) sert les bytes directement après que PHP
ait fait l'auth check. Dev local sans le module → fallback PHP
`readfile()`, transparent.

**Anti-patterns** :
- Injecter `%app.upload_dir%` → passer par `StorageManager`
- Concaténer un chemin absolu pour appeler GD ou un binaire →
  `LocalWorkspace::readable()` / `writable()` / `target()`
- `is_file()` / `filesize()` sur un objet stocké → `exists()` / `stat()`,
  et se souvenir de ce qu'on vient d'écrire plutôt que le redemander : sur
  un stockage objet, chaque question est une requête facturée
- `$document->getPublicUrl()` ou similaire qui hardcode `/uploads/...`
  → utiliser `DocumentUrlGenerator::publicUrl()` à la place
- Fichier dans `public/uploads/` → migration vers `var/uploads/<categorie>/`
- nginx en prod → Aurora cible Apache + `mod_xsendfile`

**Doc canonique** : `docs/aurora-core/dev/storage_backends.md` (contrat,
les trois verbes de `LocalWorkspace`, ajout d'un support, ce qui se facture).
Résumé dans CLAUDE.md §5bis. Lisible côté client via
`vendor/axelraboit/aurora/docs/...`.
**Apache config** : `docs/aurora-client/deployment/apache_xsendfile.md`.
Lié à [[pref_think_long_term]] (l'extraction des URL builders hors des
entités est l'application directe de cette philosophie).
