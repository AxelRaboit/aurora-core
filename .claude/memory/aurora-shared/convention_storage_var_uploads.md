---
name: convention-storage-var-uploads
description: All files stored by Aurora (core OR client extensions) live under var/uploads/<category>/. Apache never serves directly - every byte goes through PHP via a serve controller. URL building lives in dedicated services, never on entities.
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

**Why:** uniformiser la sécurité d'accès (auth granulaire par
catégorie possible), couper le "security through obscurity" des UUIDs
en `public/uploads/`, et préparer X-Sendfile pour les volumes prod.
Décision actée 2026-05-16 lors de la migration de `public/uploads/`
vers `var/uploads/`.

**How to apply** (côté aurora-core ET aurora-client) :

1. **Nouveau stockage** → `var/uploads/<categorie>/` (jamais `public/`)
2. **Service** qui écrit/lit injecte
   `#[Autowire('%app.upload_dir%/<categorie>')]` - la valeur
   `app.upload_dir` pointe vers `var/uploads`.
3. **URL serve** : un controller dédié (route nommée `<module>_serve`)
   délègue à `BinaryFileServer::serve()` ou `servePublic()`. Le
   catch-all `/uploads/{path}` (UploadsServeController) couvre le cas
   public ; les catégories auth-gated (OCR, PDF, notes per-user)
   définissent leur propre route sous `/backend/<module>/files/...`
   qui prend précédence.
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
- `$document->getPublicUrl()` ou similaire qui hardcode `/uploads/...`
  → utiliser `DocumentUrlGenerator::publicUrl()` à la place
- Fichier dans `public/uploads/` → migration vers `var/uploads/<categorie>/`
- nginx en prod → Aurora cible Apache + `mod_xsendfile`

**Doc résumée** : CLAUDE.md §5bis + cette mémoire (lisible
côté client via `vendor/axelraboit/aurora/docs/...`).
**Apache config** : `docs/aurora-client/deployment/apache_xsendfile.md`.
Lié à [[pref_think_long_term]] (l'extraction des URL builders hors des
entités est l'application directe de cette philosophie).
