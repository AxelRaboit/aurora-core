# FileTransfer - Frontend Vue

> Vue Apps + composables + composants partagés. Découpage backend (user
> auth) vs frontend (public visiteur).

## Contexte

Nimbus a un mix Twig + Vue islands (pas d'app Vue full-page). On reprend
ce pattern (qui est aussi celui d'Aurora) : un controller render un
template Twig minimal, qui monte une `<XApp>` Vue avec ses props.

Source Nimbus :
- `assets/transfer/*.vue` - flow upload + manage + show
- `assets/components/*` - composants partagés (AppButton, AppModal, AppInput, etc. - déjà dans Aurora donc à ne PAS porter)

## Liste des Vue Apps

### Backend (auth user, depuis sidebar)

| App | Fichier | Route Symfony |
|---|---|---|
| **NewTransferApp** | `assets/backend/new-transfer/NewTransferApp.vue` | `/backend/file-transfer/new` |
| **MyTransfersApp** | `assets/backend/my-transfers/MyTransfersApp.vue` | `/backend/file-transfer/my-transfers` |
| **ManageTransferApp** | `assets/backend/manage/ManageTransferApp.vue` | `/manage/{ownerToken}` (public route mais auth implicite via token) |
| **AdminTransfersApp** | `assets/backend/admin-transfers/AdminTransfersApp.vue` | `/backend/file-transfer/transfers` |
| **AdminTransferDetailApp** | `assets/backend/admin-transfers/AdminTransferDetailApp.vue` | `/backend/file-transfer/transfers/{ownerToken}` |
| **StatsApp** | `assets/backend/stats/StatsApp.vue` | `/backend/file-transfer/stats` |

### Frontend (public, depuis lien email)

| App | Fichier | Route Symfony |
|---|---|---|
| **PublicTransferApp** | `assets/frontend/public/PublicTransferApp.vue` | `/t/{token}` (état Ready) |
| **PublicTransferPasswordApp** | `assets/frontend/public/PublicTransferPasswordApp.vue` | `/t/{token}` (état Locked) |
| **PublicTransferUnavailableApp** | `assets/frontend/public/PublicTransferUnavailableApp.vue` | `/t/{token}` (état Expired/Deleted) |

## Composable phare - `useTusUpload`

Wrapper autour de `tus-js-client` (npm) pour gérer N uploads parallèles
avec progress, pause/resume, error retry :

```js
// assets/backend/new-transfer/composables/useTusUpload.js
import { ref } from 'vue'
import * as tus from 'tus-js-client'

export function useTusUpload({ endpoint = '/tus', chunkSize = 5 * 1024 * 1024 } = {}) {
  const uploads = ref([])  // [{ id, file, progress, uploadKey, status, error }]
  const isUploading = computed(() => uploads.value.some(u => u.status === 'uploading'))

  function addFile(file) {
    const id = crypto.randomUUID()
    const entry = { id, file, progress: 0, uploadKey: null, status: 'queued', error: null }
    uploads.value.push(entry)
    startUpload(entry)
    return id
  }

  function startUpload(entry) {
    entry.status = 'uploading'
    entry.tus = new tus.Upload(entry.file, {
      endpoint,
      chunkSize,
      metadata: {
        filename: entry.file.name,
        filetype: entry.file.type,
      },
      onError: (err) => { entry.status = 'error'; entry.error = err.message },
      onProgress: (loaded, total) => { entry.progress = Math.round((loaded / total) * 100) },
      onSuccess: () => {
        entry.status = 'done'
        entry.uploadKey = entry.tus.url.split('/').pop()
      },
    })
    entry.tus.start()
  }

  function removeFile(id) {
    const entry = uploads.value.find(u => u.id === id)
    if (!entry) return
    if (entry.tus && entry.status === 'uploading') entry.tus.abort()
    if (entry.uploadKey) {
      // Server-side cleanup
      fetch(`/backend/file-transfer/api/tus-abandon/${entry.uploadKey}`, { method: 'DELETE' })
    }
    uploads.value = uploads.value.filter(u => u.id !== id)
  }

  function getUploadKeys() {
    return uploads.value.filter(u => u.status === 'done').map(u => u.uploadKey)
  }

  function reset() {
    uploads.value.forEach(u => u.tus?.abort())
    uploads.value = []
  }

  return { uploads, isUploading, addFile, removeFile, getUploadKeys, reset }
}
```

## NewTransferApp - le morceau le plus complexe

### Structure UX

```
┌─────────────────────────────────────────────────┐
│ [Drop zone - drag&drop OR click to browse]      │
│                                                  │
│ Files:                                          │
│  📄 invoice.pdf      2.4 MB    [✓ 100%] [✗]    │
│  📄 photos.zip      14.2 MB    [   65%] [⏸]    │
│                                                  │
│ [+ Add more files]                              │
├─────────────────────────────────────────────────┤
│ Mode: ○ Email recipients  ● Public link         │
├─────────────────────────────────────────────────┤
│ Recipients (email mode only):                   │
│  alice@example.com                       [✗]    │
│  bob@example.com                         [✗]    │
│  [+ Add recipient]                              │
├─────────────────────────────────────────────────┤
│ Sender name : ________________                  │
│ Message    : [                       ]          │
│ Password   : __________  (optional)             │
│ Expires in : [ 24 hours ▼ ]                     │
├─────────────────────────────────────────────────┤
│              [Cancel]  [Send transfer]          │
└─────────────────────────────────────────────────┘
```

### Code skeleton

```vue
<script setup>
import { ref, computed } from 'vue'
import { useTusUpload } from './composables/useTusUpload'
import AppButton from '@/shared/components/action/AppButton.vue'
import AppInput from '@/shared/components/input/AppInput.vue'
// …

const props = defineProps({
    maxSizeMb: Number,
    maxFiles: Number,
    maxRecipients: Number,
    maxExpiryHours: Number,
})

const { uploads, isUploading, addFile, removeFile, getUploadKeys, reset } = useTusUpload()
const mode = ref('email')  // email | public
const recipients = ref([{ email: '' }])
const senderName = ref('')
const message = ref('')
const password = ref('')
const expirationHours = ref(24)
const submitting = ref(false)
const error = ref(null)

const canSubmit = computed(() => {
    if (uploads.value.length === 0) return false
    if (uploads.value.some(u => u.status !== 'done')) return false
    if (mode.value === 'email' && !recipients.value.some(r => r.email)) return false
    return !isUploading.value && !submitting.value
})

async function submit() {
    submitting.value = true
    try {
        const response = await fetch('/backend/file-transfer/api/transfers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                tusUploadKeys: getUploadKeys(),
                isPublic: mode.value === 'public',
                recipients: mode.value === 'email' ? recipients.value.filter(r => r.email) : [],
                senderName: senderName.value,
                senderMessage: message.value,
                password: password.value || null,
                expirationHours: expirationHours.value,
            }),
        })
        if (!response.ok) throw new Error((await response.json()).error)
        const { ownerToken } = await response.json()
        window.location.href = `/manage/${ownerToken}`
    } catch (e) {
        error.value = e.message
    } finally {
        submitting.value = false
    }
}
</script>
```

### Drop zone

Composant réutilisable `AppFileDropZone.vue` (à voir s'il existe déjà dans Aurora - `DocumentsApp.vue` (GED) ou `MediaTextBlock` en a peut-être un) qui :
- accepte fichiers ET dossiers (folder drop = walk les fichiers)
- multiple
- preview thumb pour images
- emit `@files-added (files: File[])`

## MyTransfersApp

Liste paginée des transferts du user courant :

```vue
<template>
    <div class="space-y-4">
        <AppPageHeader :title="t('file_transfer.myTransfers.title')">
            <AppButton :href="newUrl">{{ t('file_transfer.myTransfers.new') }}</AppButton>
        </AppPageHeader>

        <AppTable :rows="transfers" :columns="columns">
            <template #reference="{ row }">
                <code>{{ row.reference }}</code>
            </template>
            <template #status="{ row }">
                <StatusBadge :status="row.status" />
            </template>
            <template #files="{ row }">
                {{ row.fileCount }} ({{ formatBytes(row.totalSize) }})
            </template>
            <template #recipients="{ row }">
                {{ row.downloadedCount }}/{{ row.recipientCount }}
            </template>
            <template #actions="{ row }">
                <AppIconButton :href="manageUrl(row.ownerToken)">…</AppIconButton>
            </template>
        </AppTable>

        <AppPagination v-model:page="page" :total="total" :per-page="perPage" />
    </div>
</template>
```

## ManageTransferApp

Page de gestion par le sender (porteur de ownerToken). Composants :
- Header avec reference + status + expires
- Bloc "Lien à partager" avec copy-to-clipboard
- Bloc QR code (si mode public)
- Tableau recipients avec status download + bouton "Relancer"
- Bloc files (read-only)
- Bouton "Supprimer le transfert" (modal confirm)

## PublicTransferApp

Page Vue minimale pour le recipient (anonyme). Pas d'auth.

```vue
<template>
    <main class="container mx-auto py-12 px-4 max-w-2xl">
        <header>
            <h1>{{ t('file_transfer.public.title') }}</h1>
            <p class="text-muted">{{ transfer.reference }}</p>
        </header>

        <div v-if="transfer.senderName" class="mt-4 p-4 bg-surface-2 rounded-lg">
            <p class="font-medium">{{ transfer.senderName }}</p>
            <p v-if="transfer.senderMessage" class="text-sm mt-2">{{ transfer.senderMessage }}</p>
        </div>

        <ul class="mt-6 space-y-2">
            <li v-for="file in transfer.files" :key="file.id" class="flex items-center gap-3 p-3 bg-surface rounded">
                <FileIcon :mime="file.mimeType" class="w-6 h-6" />
                <div class="flex-1 min-w-0">
                    <p class="truncate">{{ file.originalName }}</p>
                    <p class="text-xs text-muted">{{ file.sizeHuman }}</p>
                </div>
                <AppIconButton v-if="file.previewUrl" :href="file.previewUrl" target="_blank">
                    <EyeIcon />
                </AppIconButton>
                <AppButton size="sm" :href="file.downloadUrl">
                    {{ t('shared.common.download') }}
                </AppButton>
            </li>
        </ul>

        <AppButton class="w-full mt-6" :href="downloadAllUrl">
            {{ t('file_transfer.public.downloadAll') }}
        </AppButton>

        <footer class="mt-8 text-xs text-muted text-center">
            {{ t('file_transfer.public.expiresAt', { date: formatDate(transfer.expiresAt) }) }}
        </footer>
    </main>
</template>
```

## Trans keys frontend

À ajouter dans `translations/file_transfer.<locale>.yaml` :

```yaml
file_transfer:
  nav:
    section: "Transferts"
    new: "Nouveau"
    my_transfers: "Mes transferts"
    admin_transfers: "Tous les transferts"
    stats: "Statistiques"

  new_transfer:
    title: "Nouveau transfert"
    dropzone: "Glissez vos fichiers ici"
    add_file: "Ajouter un fichier"
    add_recipient: "Ajouter un destinataire"
    mode_email: "Envoyer par email"
    mode_public: "Lien public"
    submit: "Envoyer"
    submitting: "Envoi en cours…"
    success: "Transfert créé"

  my_transfers:
    title: "Mes transferts"
    new: "Nouveau transfert"
    empty: "Vous n'avez pas encore de transfert."

  manage:
    title: "Gestion du transfert"
    delete_confirm: "Supprimer ce transfert ? Les fichiers seront définitivement effacés."
    remind_recipient: "Relancer"

  public:
    title: "Vous avez reçu un transfert"
    download_all: "Tout télécharger"
    expires_at: "Disponible jusqu'au {date}"
    password_prompt: "Ce transfert est protégé par mot de passe"
    unlock_button: "Déverrouiller"
    unavailable:
      expired: "Ce transfert a expiré."
      deleted: "Ce transfert a été supprimé."
      pending: "Ce transfert n'est pas encore prêt."

  errors:
    too_many_files: "Trop de fichiers ({count} > {max})"
    file_too_large: "Fichier trop volumineux"
    file_type_not_allowed: "Type de fichier non autorisé ({type})"
    zip_content_not_allowed: "Contenu ZIP interdit ({path})"
    zip_bomb_detected: "Archive suspecte détectée"
    zip_corrupt: "Archive corrompue"
    upload_not_found: "Upload introuvable, recommencez"
    wrong_password: "Mot de passe incorrect"
    too_many_attempts: "Trop de tentatives, réessayez dans {minutes} min"
```

Reprendre les traductions Nimbus pour FR/EN/ES/DE → préfixer toutes les
clés par `file_transfer.`.

## Composants à VÉRIFIER avant de créer

Avant de porter un composant Nimbus, **vérifier qu'Aurora ne l'a pas
déjà** :

| Nimbus | Aurora probable | Action |
|---|---|---|
| `AppInput.vue` | `@shared/components/input/AppInput.vue` | Réutiliser |
| `AppButton.vue` | `@shared/components/action/AppButton.vue` | Réutiliser |
| `AppModal.vue` | `@shared/components/overlay/AppModal.vue` | Réutiliser |
| `AppHeader.vue` / `AppPageHeader.vue` | équivalents dans `@shared/components/` | Réutiliser |
| `AppLogo.vue` | `@shared/components/display/AppLogo.vue` | Réutiliser |
| `PasswordStrength.vue` | (pas sûr - vérifier) | Si absent, porter dans `@shared` |
| `AppQrCode.vue` | (pas sûr - vérifier) | Si absent, porter dans `@shared` |
| `AppPagination.vue` | `@shared/components/nav/AppPagination.vue` | Réutiliser |
| `AppTable` / row helpers | `@shared/components/table/*` | Réutiliser |

**Règle** : pas de duplication. Si un composant nimbus correspond à un
composant aurora, on utilise celui d'aurora et on adapte la prop API.

## Décisions ouvertes

- **Drop zone composant partagé** : à mutualiser dans `@shared` ou laissé spécifique au module ? Suggestion : partagé, car d'autres modules (Media uploader, PDF form upload) en auraient l'usage.
- **`tus-js-client`** : ajouter à `package.json` (déjà absent côté Aurora). ~12 ko gzip, mature.
- **Folder upload** : Nimbus supporte le drop d'un dossier entier (walk fichiers récursivement). On porte → `webkitdirectory` attribute sur l'input file + walk en JS.

## Tests obligatoires (Vitest)

- `useTusUpload.addFile` → upload démarre, `progress` augmente, `uploadKey` setté à la fin
- `useTusUpload.removeFile` mid-upload → tus.abort() appelé + cleanup serveur
- `NewTransferApp` : `canSubmit` false tant qu'un upload n'est pas done
- `NewTransferApp` : submit → POST /api/transfers avec uploadKeys + redirige vers /manage/...
- `PublicTransferApp` : password mode → submit password redirect vers /t/{token} unlocked
