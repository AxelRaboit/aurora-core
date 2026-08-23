# FileTransfer - Routes publiques

> UX visiteur : ce qu'un recipient voit quand il clique sur son lien
> email. Routes `/t/{token}` sous le préfixe public (hors backend admin).

## Contexte

Nimbus expose 3 routes principales côté public :
- `GET /t/{token}` - page de download (token de Transfer OU de Recipient)
- `GET /t/{token}/download/{filename}` - download d'un fichier individuel
- `GET /t/{token}/preview/{filename}` - preview inline (images, PDF)
- `POST /t/{token}/unlock` - soumission du mot de passe
- `GET /manage/{ownerToken}` - page de gestion par le sender (réservé au porteur du ownerToken)

Source Nimbus :
- `app/Controller/TransferController.php`
- `templates/transfer/*.html.twig`
- `assets/transfer/*.vue`

## Reconnaissance du token

Le `{token}` dans `/t/{token}` peut être :
1. Le `FileTransfer.token` (public link, partagé en clair) → mode public, pas de recipient tracking
2. Le `FileTransferRecipient.token` (lien personnel par email) → recipient identifié, tracking actif

Le controller distingue :

```php
public function show(string $token, Request $request): Response
{
    // 1. Try recipient lookup
    $recipient = $this->recipientRepo->findOneByToken($token);
    if ($recipient) {
        $transfer = $recipient->getTransfer();
        $request->getSession()->set("ft_recipient_{$transfer->getToken()}", $recipient->getToken());
        return $this->renderTransferPage($transfer, $recipient);
    }

    // 2. Try public transfer lookup
    $transfer = $this->transferRepo->findByToken($token);
    if ($transfer && $transfer->isPublic()) {
        return $this->renderTransferPage($transfer, null);
    }

    // 3. Not found / wrong token
    throw $this->createNotFoundException();
}
```

## Routes

| Méthode | Route | Auth | Purpose |
|---|---|---|---|
| `GET` | `/t/{token}` | aucune | Page principale de download |
| `POST` | `/t/{token}/unlock` | aucune | Soumet le mot de passe, set session flag |
| `GET` | `/t/{token}/download` | aucune (mais password si protégé) | Download : ZIP si N>1 fichiers, sinon direct |
| `GET` | `/t/{token}/download/{filename}` | aucune (idem) | Download d'un fichier individuel |
| `GET` | `/t/{token}/preview/{filename}` | aucune (idem) | Preview inline |
| `GET` | `/manage/{ownerToken}` | possession ownerToken | Page gestion sender |

## Page principale `/t/{token}`

3 états selon le status + password :

### A. Transfer Ready, pas de mot de passe → page download

Affiche :
- Logo / brand (lié à la setting siteName Aurora)
- Reference code (ex: `7F3A2-9B1`)
- Sender name + message
- Liste des fichiers (nom, taille, icône MIME)
- Bouton "Tout télécharger" (ZIP si N>1, single sinon)
- Bouton individuel par fichier
- Bouton preview (icône eye) pour images/PDF
- Expiration : "Disponible jusqu'au {date}"
- Mode public : compteur de téléchargements
- Mode email : aucune mention du recipient (déjà identifié, c'est lui)

Template : `@FileTransfer/public/show.html.twig` qui monte `PublicTransferApp.vue`.

### B. Transfer Ready + password protégé → page unlock

Affiche :
- Reference code
- Input password + bouton Unlock
- Lien retour

Si succès : session flag `ft_unlocked_{token}` = true → reload → état A.

Template : `@FileTransfer/public/password.html.twig` + Vue `PublicTransferPasswordApp.vue`.

### C. Transfer Expired / Deleted / Pending → page unavailable

Affiche :
- Message localisé selon le status (`expired`, `deleted`, `not yet ready`)
- Pas de download possible

Template : `@FileTransfer/public/unavailable.html.twig`.

## Téléchargement

```php
public function downloadAll(string $token): Response
{
    $transfer = $this->loadTransferWithGuards($token);  // 404 / 423 password / 410 expired

    if (count($transfer->getFiles()) === 1) {
        $file = $transfer->getFiles()->first();
        $this->trackDownload($transfer, $request);
        return $this->storage->getAdapterForFile($file)->createDownloadResponse(
            $this->storage->buildStorageKey($transfer, $file->getFilename()),
            $file->getOriginalName(),
            $file->getMimeType(),
            inline: false,
        );
    }

    // Multiple → ZIP on the fly
    return $this->buildZipResponse($transfer);
}

private function buildZipResponse(FileTransferInterface $transfer): StreamedResponse
{
    return new StreamedResponse(function () use ($transfer) {
        $zip = new ZipStream(/* outputName: $transfer->getReference() . '.zip' */);
        foreach ($transfer->getFiles() as $file) {
            $adapter = $this->storage->getAdapterForFile($file);
            $key = $this->storage->buildStorageKey($transfer, $file->getFilename());
            $localPath = $adapter->getLocalPath($key);  // R2 → downloads tmp, Local → direct path
            $zip->addFileFromPath($file->getOriginalName(), $localPath);
            if ($adapter instanceof R2StorageAdapter) {
                register_shutdown_function('unlink', $localPath);
            }
        }
        $zip->finish();
    });
}
```

Library : `maennchen/zipstream-php` (composer).

## Tracking téléchargement

```php
private function trackDownload(FileTransferInterface $transfer, Request $request): void
{
    if ($transfer->isPublic()) {
        $transfer->incrementPublicDownloadCount();
        $this->em->flush();
        return;
    }

    $recipientToken = $request->getSession()->get("ft_recipient_{$transfer->getToken()}");
    if (!$recipientToken) return;  // accès par lien public sans recipient identifié

    $recipient = $this->recipientRepo->findOneByToken($recipientToken);
    if ($recipient) {
        $this->recipientManager->markDownloaded($recipient);  // idempotent, notifie owner si 1re fois
    }
}
```

## Page de gestion `/manage/{ownerToken}`

Réservée au sender. Affiche :
- Status du transfer
- Reference, expiration
- Liste des fichiers (read-only)
- Liste des recipients avec leur status (téléchargé / pending / dernier reminder) + bouton "Relancer"
- QR code du lien public (si mode public)
- Bouton "Supprimer le transfert" (avec confirmation)

Template : `@FileTransfer/public/manage.html.twig` + Vue `ManageTransferApp.vue`.

## Codes HTTP

- `200` - page rendue (Ready)
- `404` - token inconnu
- `410 Gone` - transfer Expired ou Deleted
- `423 Locked` - Ready mais mot de passe requis
- `429 Too Many Requests` - rate-limit sur `/unlock` (anti brute-force)

## Rate-limiting `/unlock`

Symfony RateLimiter (token bucket) :
- 5 tentatives / 10 min par IP+token combo
- Au-delà → 429 avec retry-after header

Setting : `fileTransfer.unlockMaxAttempts` (5), `fileTransfer.unlockWindowMinutes` (10).

## Décisions ouvertes

- **Path préfixe** : Nimbus utilise `/t` (court, mémorable). On garde. Pas de namespace `/file-transfer/t/` qui casserait les liens email Nimbus à la migration.
- **ZIP filename** : `{reference}.zip` (ex: `7F3A2-9B1.zip`). Préférable à un timestamp ou au senderName (peut contenir des caractères filesystem-hostiles).
- **Session lifetime** : flags `ft_recipient_*` et `ft_unlocked_*` expirent avec la session PHP standard (~ 24 h). Pas de cookie permanent - chaque visite re-passe le password.

## Tests obligatoires

- GET `/t/{recipientToken}` → page rendue + session `ft_recipient_<transfer>` settée
- GET `/t/{publicToken}` (mode public) → page rendue sans session recipient
- GET `/t/{unknown}` → 404
- GET `/t/{expiredToken}` → 410
- POST `/t/{token}/unlock` correct password → session `ft_unlocked` settée, redirect
- POST `/t/{token}/unlock` mauvais password 5 fois → 429
- GET `/t/{token}/download` mode email → markDownloaded() appelé une seule fois (idempotence)
- GET `/t/{token}/download` mode public → publicDownloadCount incrémenté
- ZIP multi-fichiers : MIME correct, filename = `{reference}.zip`, fichiers extractibles
- Migration : URL email Nimbus `/t/{token}` → marche tel quel sur Aurora après import
