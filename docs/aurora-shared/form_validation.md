# Convention de validation des formulaires

Ce document est la **référence unique** pour implémenter la validation d'un formulaire dans Aurora Core.
Toute déviation doit être justifiée et documentée ici.

---

## Vue d'ensemble du flux

```
Utilisateur soumet  →  [Client] validation minimale (required, email)
                   →  [Fetch] POST /endpoint  →  [Server] DTO + PayloadValidator
                                              ←  422 { success: false, errors: { field: "key" } }
                   ←  translateServerErrors()  →  setErrors()  →  :error sur les inputs
```

---

## Backend

### 1. DTO - structure obligatoire

Deux variantes selon l'usage :

#### A. DTO instrumenté (entité avec page backend CRUD)

Suit la **convention 5-couches Sylius-style** : `<Name>InputInterface` + classe
**non-`final`** + `public readonly` par propriété + factory séparée avec
`#[AsAlias]`. Permet l'override d'un projet client / d'un client de client.
Doc canonique :
[`../aurora-core/dev/entity_extensibility_convention.md` §3](../aurora-core/dev/entity_extensibility_convention.md).

```php
// src/Module/Foo/Dto/FooInputInterface.php
interface FooInputInterface
{
    public function getName(): string;
    public function getStatus(): string;
}

// src/Module/Foo/Dto/FooInput.php - NON-final pour permettre extends côté client
class FooInput implements FooInputInterface
{
    public function __construct(
        #[Assert\NotBlank(message: 'backend.foo.errors.name_required')]
        #[Assert\Length(max: 255, maxMessage: 'backend.foo.errors.name_too_long')]
        public readonly string $name,            // public readonly per-prop (pas readonly class)

        #[Assert\Choice(callback: [FooStatusEnum::class, 'values'])]
        public readonly string $status,

        #[Assert\PositiveOrZero]
        public readonly ?int $relatedId = null,
    ) {}

    public function getName(): string   { return $this->name; }
    public function getStatus(): string { return $this->status; }
}
```

```php
// src/Module/Foo/Dto/FooInputFactory.php
#[AsAlias(FooInputFactoryInterface::class)]
class FooInputFactory implements FooInputFactoryInterface
{
    public function fromArray(array $data): FooInputInterface
    {
        return new FooInput(
            name:      Str::trimFromArray($data, 'name'),
            status:    (string) ($data['status'] ?? ''),
            relatedId: isset($data['relatedId']) ? (int) $data['relatedId'] : null,
        );
    }
}
```

**Pourquoi pas `final readonly class` global** : un parent `readonly class`
contraint l'enfant à être également `readonly class` → un client ne peut pas
ajouter une propriété mutable. La validation Symfony se fait sur la classe,
elle s'applique transparemment aux enfants.

#### B. DTO interne / sub-DTO (pas instrumenté, pas étendu)

Pour les value objects internes (sub-DTOs imbriqués, payloads d'event,
DTOs de réponse) qui **ne sont pas exposés** à l'extension client, garder
`final readonly` reste OK et plus expressif :

```php
final readonly class FooLineInput  // sub-DTO inclus dans FooInput.lines[]
{
    public function __construct(
        public string $sku,
        public int $quantity,
    ) {}
}
```

**Règles communes (A et B)** :
- Propriétés publiques, pas de getters (sauf si Interface contrainte de Manager les exige - cf. variante A)
- Messages de contrainte = clé de traduction (ex: `'backend.foo.errors.name_required'`)
- **Pas** de méthode statique `fromArray()` dans le DTO en cas A - c'est la factory qui le fait (elle peut être décorée par un client)
- Normalisation (`trim()`, cast, nullification) toujours dans la factory ou le constructeur

---

### 2. Validation dans le controller

```php
// src/Module/Foo/Controller/Backend/FooController.php

#[Route('', name: '_create', methods: [HttpMethodEnum::Post->value])]
public function create(Request $request): JsonResponse
{
    // 1. Décoder + hydrater le DTO
    $input = FooInput::fromArray($this->decodeJson($request));

    // 2. Valider via PayloadValidator
    $errors = $this->payloadValidator->errors($input);
    if ([] !== $errors) {
        return $this->jsonInvalidInput($errors);   // → 422, pas de 2e argument
    }

    // 3. Business logic - erreurs métier mappées sur un champ
    try {
        $foo = $this->fooManager->create($input);
    } catch (SlugConflictException) {
        return $this->jsonInvalidInput(['name' => 'backend.foo.errors.slug_taken']);
    }

    return $this->jsonSuccess(['foo' => $this->fooSerializer->serialize($foo)]);
}
```

**Règles :**
- `jsonInvalidInput($errors)` **sans** second argument → HTTP 422 par défaut
- Ne jamais passer `Response::HTTP_OK` comme second argument
- Erreurs métier (unicité, contrainte fonctionnelle) → `jsonInvalidInput(['field' => 'translation.key'])`
- Le champ vide `''` n'est pas un message valide - toujours une clé de traduction

---

### 3. HTTP status codes

| Situation | Code | Méthode trait |
|---|---|---|
| Erreurs de validation | `422 Unprocessable Entity` | `jsonInvalidInput($errors)` |
| Conflit (slug, email) | `409 Conflict` | `jsonFailure(JsonErrorCode::Conflict, 409)` |
| Succès | `200 OK` | `jsonSuccess([...])` |
| Non trouvé | `404 Not Found` | `jsonNotFound()` |
| Interdit | `403 Forbidden` | `jsonForbidden()` |

---

### 4. Format de réponse

```json5
// Succès
{ "success": true, "foo": { "id": 1, "name": "…" } }

// Erreurs de validation (422)
{ "success": false, "errors": { "name": "backend.foo.errors.name_required", "status": "backend.foo.errors.status_invalid" } }

// Erreur métier (400 ou 409)
{ "success": false, "error": "conflict" }
```

Les valeurs du champ `errors` sont **toujours des clés de traduction** - jamais du texte en clair.

---

### 5. Contraintes Symfony disponibles

| Besoin | Contrainte |
|---|---|
| Champ requis | `#[Assert\NotBlank]` |
| Longueur max/min | `#[Assert\Length(max: 255)]` |
| Format email | `#[Assert\Email]` |
| Valeur dans une liste | `#[Assert\Choice(callback: [Enum::class, 'values'])]` |
| Entier positif | `#[Assert\Positive]` ou `#[Assert\PositiveOrZero]` |
| Tableau d'entiers | `#[Assert\All([new Assert\Positive()])]` |
| Unicité email | `#[UniqueEmail]` (custom - `src/Core/Auth/Validator/`) |
| Égalité (confirmation mdp) | `#[Assert\EqualTo(propertyPath: 'password')]` |

---

### 6. Clés de traduction des erreurs

Structure attendue dans `messages.fr.yaml` et `messages.en.yaml` :

```yaml
# src/Module/Foo/translations/messages.fr.yaml
backend:
    foo:
        errors:
            name_required: "Le nom est obligatoire."
            name_too_long: "Le nom ne peut pas dépasser 255 caractères."
            status_required: "Le statut est obligatoire."
            status_invalid: "Le statut choisi est invalide."
            slug_taken: "Ce nom génère un identifiant déjà utilisé."
```

---

## Frontend

### 1. Composable de base : `useForm()`

```js
import { useForm } from "@/shared/composables/form/useForm.js";

const { errors, validate, setErrors, clearErrors } = useForm();
```

| Méthode | Usage |
|---|---|
| `errors.value` | `ref({})` - objet `{ field: "message traduit" }` |
| `validate(checks)` | Validation client-side, retourne `boolean` |
| `setErrors(obj)` | Injecte les erreurs serveur (déjà traduites) |
| `clearErrors()` | Vide les erreurs (à l'ouverture d'une modal) |

**Ne pas** utiliser `reactive({})` ni des `ref('')` individuels. Toujours `useForm()`.

---

### 2. Validation client-side minimale

Valider uniquement ce qui évite des allers-retours réseau inutiles : champs requis et format email.
**La vraie validation reste côté serveur.**

```js
import { required, email, compose } from "@/shared/utils/validation/validators.js";

async function submitCreate() {
    // Validation minimale côté client
    if (!validate({
        name:  () => required(t("backend.foo.errors.name_required"))(form.value.name),
        email: () => compose(
            required(t("backend.foo.errors.email_required")),
            email(t("backend.foo.errors.email_invalid")),
        )(form.value.email),
    })) return;

    // Envoi serveur
    const data = await request(createPath, form.value);
    if (!data) return; // useRequest a déjà affiché un toast d'erreur réseau

    if (!data.success) {
        setErrors(translateServerErrors(t, data.errors));
        return;
    }

    // Succès
    toast.success(t("shared.common.saved"));
    clearErrors();
    emit("created", data.foo);
}
```

---

### 3. Fetch - utiliser `useRequest`

```js
import { useRequest } from "@/shared/composables/http/useRequest.js";

const { request, loading } = useRequest();
```

`useRequest` gère automatiquement :
- Header `Content-Type: application/json`
- Whitelist des status 422, 409, 400 (pas de throw sur ces codes)
- Toast d'erreur réseau sur les autres codes non-2xx
- Indicateur `loading`

Ne jamais utiliser `fetch()` directement pour des mutations de formulaire.

---

### 4. Translation des erreurs serveur

Les erreurs retournées par le serveur sont des **clés de traduction**. Il faut les traduire **avant** `setErrors()`.

```js
import { translateServerErrors } from "@/shared/utils/validation/translateServerErrors.js";

// Toujours :
setErrors(translateServerErrors(t, data.errors));

// Jamais :
setErrors(data.errors);  // ← les clés brutes s'afficheraient dans l'UI
```

---

### 5. Affichage dans le template

Tous les composants de formulaire (`AppInput`, `AppTextarea`, `AppSelect`, `AppMultiselect`, `AppDatePicker`) exposent une prop `:error`.

```vue
<AppInput
    v-model="form.name"
    :label="t('backend.foo.nameLabel')"
    :error="errors.name"
/>

<AppTextarea
    v-model="form.description"
    :label="t('backend.foo.descriptionLabel')"
    :error="errors.description"
/>
```

La prop `:error` attend une **chaîne déjà traduite** (pas une clé).

---

### 6. Pattern complet - composable de création

```js
// src/Module/Foo/assets/backend/composables/useFooCreate.js

import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { required } from "@/shared/utils/validation/validators.js";
import { useForm } from "@/shared/composables/form/useForm.js";
import { useRequest } from "@/shared/composables/http/useRequest.js";
import { translateServerErrors } from "@/shared/utils/validation/translateServerErrors.js";

export function useFooCreate(createPath, onCreated) {
    const { t } = useI18n();
    const { errors, validate, setErrors, clearErrors } = useForm();
    const { request, loading: saving } = useRequest();

    const show = ref(false);
    const form = ref({ name: "", status: "draft" });

    function open() {
        form.value = { name: "", status: "draft" };
        clearErrors();
        show.value = true;
    }

    async function submit() {
        if (!validate({
            name: () => required(t("backend.foo.errors.name_required"))(form.value.name),
        })) return;

        const data = await request(createPath, form.value);
        if (!data) return;

        if (!data.success) {
            setErrors(translateServerErrors(t, data.errors));
            return;
        }

        toast.success(t("backend.foo.toast.created"));
        show.value = false;
        onCreated?.(data.foo);
    }

    return { show, form, errors, saving, open, submit };
}
```

---

### 7. Pattern complet - composable d'édition

```js
// src/Module/Foo/assets/backend/composables/useFooEdit.js

export function useFooEdit(updatePath, onUpdated) {
    const { t } = useI18n();
    const { errors, validate, setErrors, clearErrors } = useForm();
    const { request, loading: saving } = useRequest();

    const show = ref(false);
    const editing = ref(null);
    const form = ref({ name: "", status: "" });

    function open(foo) {
        editing.value = foo;
        form.value = { name: foo.name, status: foo.status };
        clearErrors();
        show.value = true;
    }

    async function submit() {
        if (!validate({
            name: () => required(t("backend.foo.errors.name_required"))(form.value.name),
        })) return;

        const data = await request(updatePath.replace("{id}", editing.value.id), form.value);
        if (!data) return;

        if (!data.success) {
            setErrors(translateServerErrors(t, data.errors));
            return;
        }

        toast.success(t("shared.common.saved"));
        show.value = false;
        onUpdated?.(data.foo);
    }

    return { show, form, errors, saving, open, submit };
}
```

---

## Checklist - nouveau formulaire

### Backend
- [ ] Créer `src/Module/Foo/Dto/FooInput.php` - `final readonly`, factory `fromArray()`
- [ ] Ajouter les `#[Assert\...]` sur chaque propriété avec des clés de traduction
- [ ] Ajouter les clés dans `messages.fr.yaml` et `messages.en.yaml`
- [ ] Dans le controller : `$errors = $this->payloadValidator->errors($input);`
- [ ] Retourner `$this->jsonInvalidInput($errors)` sans second argument (→ 422)
- [ ] Erreurs métier : `$this->jsonInvalidInput(['field' => 'module.foo.errors.key'])`

### Frontend
- [ ] Créer `useFooCreate.js` et/ou `useFooEdit.js`
- [ ] Utiliser `useForm()` pour les erreurs (jamais `reactive({})` ni refs individuelles)
- [ ] Utiliser `useRequest()` pour les requêtes (jamais `fetch()` direct)
- [ ] Validation client minimale : `validate({ name: () => required(...)(...) })`
- [ ] Erreurs serveur : `setErrors(translateServerErrors(t, data.errors))`
- [ ] Template : `:error="errors.fieldName"` sur chaque composant de formulaire

---

## Ce qu'il ne faut pas faire

| ❌ À éviter | ✅ À faire |
|---|---|
| `jsonInvalidInput($errors, Response::HTTP_OK)` | `jsonInvalidInput($errors)` |
| `setErrors(data.errors)` sans traduire | `setErrors(translateServerErrors(t, data.errors))` |
| `const nameError = ref('')` | `const { errors } = useForm()` |
| `await fetch(url, {...})` direct | `const { request } = useRequest()` |
| Messages en clair dans les Constraints | Clés de traduction uniquement |
| Validation client exhaustive | Uniquement required + email format |
| `if (!data.success && !data.success)` | `if (!data.success)` |
