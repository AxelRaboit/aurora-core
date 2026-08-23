# Manager vs Service - qu'est-ce qui va où

## Règle (rappel concis)

| Critère | `Manager/` | `Service/` |
|---|---|---|
| Persiste / flush des entités | ✅ Oui | ❌ Non |
| Exposé comme point d'extension Sylius | ✅ Interface + AsAlias | ❌ Pas obligatoirement |
| Logique métier orchestrée (transactions, audit log) | ✅ | ❌ |
| Calcul stateless / helper | ❌ | ✅ |
| Validation / parsing | ❌ | ✅ |
| Provider externe (HTTP client wrapper, etc.) | ❌ | ✅ |

**Heuristique rapide** : si la classe appelle `$em->persist()`, `$em->flush()`,
ou émet des audit logs CRUD → `Manager/`. Si elle prend des données et
retourne un calcul / parse / format sans toucher la base → `Service/`.

## Ce qui va dans `Manager/`

- **Lifecycle entités** : create / update / delete avec persist + flush +
  audit log.
- **Workflows métier complexes** : transitions de statut (Order:
  markPaid → cancel), cascade de modifications (suppression Project →
  suppression cascade ProjectColumn/Task/Sprint).
- **Async dispatch** : ProcessOcrJobMessage envoyé depuis OcrJobManager.
- **Cross-entity orchestration** : InvoiceManager qui crée Invoice +
  appelle TiersManager.findOrCreateClientFromDraft().

### Squelette Manager (cas standard)

```php
#[AsAlias(AgencyManagerInterface::class)]
class AgencyManager implements AgencyManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly AuditLogger $auditLogger,
    ) {}

    public function create(AgencyInputInterface $input): AgencyInterface { /* persist + flush + audit */ }
    public function update(AgencyInterface $agency, AgencyInputInterface $input): void { /* flush + audit */ }
    public function delete(AgencyInterface $agency): void { /* remove + flush + audit */ }

    protected function createAgency(): AgencyInterface { return new Agency(); }
    protected function applyInput(AgencyInterface $agency, AgencyInputInterface $input): void { /* setters */ }
    protected function auditCreated/Updated/Deleted(...): void { /* hook audit */ }
    protected function auditPayload(AgencyInterface $agency): array { return ['name' => $agency->getName()]; }
}
```

Cf [`convention_manager_hooks.md`](convention_manager_hooks.md).

## Ce qui va dans `Service/`

- **Helpers stateless** : `PostTextExtractor` (extrait texte d'un document),
  `PrimaryColorPalette` (calcule la palette d'un thème), `ExifReader`
  (lit metadata photo).
- **Validators** : `CommentSubmissionValidator`, `FormSubmissionValidator`
  (valident un payload sans persister).
- **Providers externes** : `StripeService`, `OllamaVisionClient` (wrappers
  d'API tierces).
- **Resolvers / Builders / Renderers** : `ThemeResolver` (résout le theme
  actif), `MenuRenderer` (rend l'arbre Menu côté front), `SearchSnippetBuilder`.
- **Context holders** : `FrontContext`, `BillingContext`, `GalleryAccessService`
  (encapsulent du contexte runtime).

### Squelette Service

```php
final readonly class PostTextExtractor
{
    public function extract(PostTranslationInterface $translation): string
    {
        // logique pure : prend des données, retourne un calcul
        // pas de persist, pas de flush
    }
}
```

**Notes** :
- Les Services sont souvent `final readonly` car ils n'ont pas vocation à
  être étendus (pas de point d'extension Sylius).
- Pas d'`#[AsAlias]` sauf si on prévoit une décoration explicite.
- Constructeur en property promotion `private readonly`.

## Cas limites (et comment trancher)

### "Mon service crée une entité mais ne la persiste pas"

→ `Service/`. Il **construit** l'entité (peut-être pour la passer à un
Manager qui persistera). Pas de persist = pas Manager.

### "Mon manager fait surtout de la validation"

Si la validation aboutit à un persist (ex: `submit()` qui valide puis
persiste un Comment), c'est un **Manager**. Si la validation est un
service pur (renvoie array d'erreurs), c'est un **Service**.

Cf séparation `CommentManager` (persiste) vs `CommentSubmissionValidator`
(valide juste).

### "C'est un wrapper d'API externe avec persist"

Cas Stripe : `StripeService` (wrapper API) + `OrderManager.markPaid()`
(persiste). Le wrapper API est un Service, le Manager utilise le service
pour finaliser le persist.

### "C'est une factory qui instancie une entité complexe"

Si la factory **persiste**, c'est un Manager (ex: `OcrJobManager.createFromUpload()`).
Si la factory retourne juste l'instance pour que le caller persiste, c'est
un Service ou un Builder.

## Dossiers connexes

- `MessageHandler/` : handlers de messages async (Symfony Messenger). Le
  handler **peut** appeler un Manager pour le persist.
- `EventSubscriber/` : listeners d'events Symfony (kernel.request,
  doctrine.postPersist, …). Souvent stateless ou délégant à Manager/Service.
- `Twig/` : extensions Twig (`<Module>Extension.php`). Délègue à Service.
- `Security/` : Voters et AccessChecker. Stateless, similaires à Service.
- `View/` : `<Plural>ViewBuilder` qui construisent les payloads Twig pour
  les pages admin (cf [`structure_view_builder.md`](structure_view_builder.md)).

## Anti-patterns

- ❌ Un "ServiceManager" qui mélange persist + helper stateless. Splitter.
- ❌ Un Service qui fait `$em->flush()` quelque part. C'est un Manager
  caché - refactoriser.
- ❌ Un Manager qui ne persiste rien (juste des calculs). Renommer en
  Service.
