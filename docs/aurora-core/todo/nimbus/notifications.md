# FileTransfer - Notifications email

> 3 événements emails : recipient reçoit son lien, owner reçoit
> confirmation de téléchargement, recipient reçoit un reminder.

## Contexte

Nimbus a un `TransferNotifier` qui dispatche un `EmailQueueMessage`
custom via Messenger. On adapte : Aurora a (probablement) une infra de
notifications réutilisable. À défaut → Symfony Mailer brut + Messenger
pour l'async, sans rebuild d'une queue dédiée.

Source Nimbus :
- `app/Service/TransferNotifier.php`
- `app/Service/TransferNotifierInterface.php`
- `templates/emails/*.html.twig`

## Architecture cible

`FileTransferNotifierInterface` (avec `#[AsAlias]` sur la classe pour
permettre la substitution client) :

```php
interface FileTransferNotifierInterface
{
    /**
     * Envoie un email par recipient avec son lien personnel.
     * Si $plainPassword fourni → l'inclut dans l'email (uniquement pour le 1er envoi).
     */
    public function notifyReady(FileTransferInterface $transfer, ?string $plainPassword): void;

    /**
     * Envoie un email à l'owner quand un recipient télécharge la 1re fois.
     */
    public function notifyDownloaded(FileTransferInterface $transfer, FileTransferRecipientInterface $recipient): void;

    /**
     * Envoie un reminder à un recipient qui n'a pas téléchargé.
     */
    public function notifyReminder(FileTransferInterface $transfer, FileTransferRecipientInterface $recipient): void;

    /**
     * Envoie un email à l'owner quand son transfer expire (optionnel).
     */
    public function notifyExpired(FileTransferInterface $transfer): void;
}
```

Implémentation :

```php
class FileTransferNotifier implements FileTransferNotifierInterface
{
    public function __construct(
        protected readonly MailerInterface $mailer,
        protected readonly TranslatorInterface $translator,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly MessageBusInterface $bus,
        protected readonly string $fromAddress,
    ) {}

    public function notifyReady(FileTransferInterface $transfer, ?string $plainPassword): void
    {
        foreach ($transfer->getRecipients() as $recipient) {
            $email = (new TemplatedEmail())
                ->from($this->fromAddress)
                ->to($recipient->getEmail())
                ->subject($this->translator->trans('file_transfer.email.ready.subject', [
                    'sender' => $transfer->getSenderName() ?? '-',
                ]))
                ->htmlTemplate('@FileTransfer/email/ready.html.twig')
                ->context([
                    'transfer' => $transfer,
                    'recipient' => $recipient,
                    'downloadUrl' => $this->urlGenerator->generate('file_transfer_public_show', [
                        'token' => $recipient->getToken(),
                    ], UrlGeneratorInterface::ABSOLUTE_URL),
                    'plainPassword' => $plainPassword,
                ]);
            $this->bus->dispatch(new SendEmailMessage($email));  // async
        }
    }

    // notifyDownloaded, notifyReminder, notifyExpired - même pattern
}
```

## Templates Twig

Sous `src/Module/FileTransfer/templates/email/` :

| Template | Variables | Trans namespace |
|---|---|---|
| `ready.html.twig` | transfer, recipient, downloadUrl, plainPassword | `file_transfer.email.ready.*` |
| `downloaded.html.twig` | transfer, recipient, downloadedAt | `file_transfer.email.downloaded.*` |
| `reminder.html.twig` | transfer, recipient, downloadUrl | `file_transfer.email.reminder.*` |
| `expired.html.twig` | transfer | `file_transfer.email.expired.*` |

Chaque template hérite d'un `@FileTransfer/email/base.html.twig` qui
porte le header (logo, brand) et le footer (legal text, unsubscribe si
applicable). **Voir s'il existe un email base Aurora à étendre.**

## Trans keys requises

Sous `translations/file_transfer.<locale>.yaml` :

```yaml
file_transfer:
  email:
    ready:
      subject: "{sender} vous a envoyé des fichiers"
      title: "Vous avez reçu un transfert"
      body: "{sender} vous a envoyé {count} fichier(s)."
      password_hint: "Pour télécharger, utilisez ce mot de passe : {password}"
      cta: "Télécharger"
      expires_at: "Disponible jusqu'au {date}"
    downloaded:
      subject: "{email} a téléchargé votre transfert"
      title: "Téléchargement confirmé"
      body: "{email} a téléchargé votre transfert {reference} le {date}."
    reminder:
      subject: "Rappel : transfert en attente"
      title: "Vous n'avez pas encore téléchargé"
      body: "{sender} vous attend. Cliquez pour télécharger avant {expiresAt}."
      cta: "Télécharger maintenant"
    expired:
      subject: "Votre transfert {reference} a expiré"
      title: "Transfert expiré"
      body: "Le transfert {reference} a expiré le {date}. Les fichiers ont été supprimés."
```

4 locales (fr/en/es/de) - reprendre les YAML Nimbus et préfixer toutes
les clés par `file_transfer.email.`.

## Bridge vers Aurora Notifications

✅ Confirmé : Aurora a `Aurora\Core\Notification\` avec
`NotificationManagerInterface` + `NotificationInterface` + entité
`Notification` + Serializer. `AppNotificationsBell.vue` est branché
dessus côté backend.

Brancher `FileTransferNotifier` dessus en plus de l'email :
- Quand `notifyDownloaded()` est appelé, créer aussi une notification
  in-app pour l'owner (badge sur la bell)
- Quand `notifyExpired()` est appelé, notification in-app pour l'owner
- L'API exacte de création est à vérifier dans `NotificationManager` au
  moment de l'implémentation (méthode `create()` ou équivalent + shape
  du `context`)

Pour les reminders et le 1er envoi recipient (`notifyReady`) → email
uniquement (les recipients sont anonymes/identifiés par token, pas
forcément des users Aurora).

## Anti-spam

- **`notifyReady`** : déclenché une fois par création de transfer. OK.
- **`notifyDownloaded`** : déclenché à chaque `markDownloaded()` qui est
  idempotent (1 fois par recipient). OK.
- **`notifyReminder`** : rate-limit via `Recipient.lastReminderSentAt`
  ≥24 h (manuel) ou ≥48 h (auto via scheduler). Cf. [recipient.md](recipient.md).

## Décisions ouvertes

- **Email base template** : vérifier si Aurora a `@CoreEmail/base.html.twig` ou équivalent. Sinon, créer un base spécifique au module FileTransfer.
- **Async vs sync** : tous les emails passent par MessageBus → async, non-bloquant. En cas de queue down, retry via Messenger.
- **Unsubscribe** : pour les reminders, ajouter un lien `unsubscribe={recipient.token}` qui met `lastReminderSentAt = far-future` pour ne plus rien envoyer ? À discuter. V1 = pas d'unsubscribe.

## Tests obligatoires

- `notifyReady` envoie N emails pour N recipients
- `notifyReady` inclut le password en clair UNIQUEMENT au 1er envoi (pas dans les reminders)
- `notifyDownloaded` n'est appelé qu'à la 1re ouverture (idempotence vérifiée côté `markDownloaded`)
- Template render OK pour 4 locales (fr/en/es/de)
- MessageBus dispatch → SendEmailMessage transport non-bloquant
