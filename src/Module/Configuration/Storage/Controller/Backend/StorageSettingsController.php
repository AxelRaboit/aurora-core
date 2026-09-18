<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Storage\Controller\Backend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Http\JsonRequestTrait;
use Aurora\Core\Http\JsonResponseTrait;
use Aurora\Core\Storage\Enum\StorageDeliveryModeEnum;
use Aurora\Core\Storage\Enum\StorageDiskEnum;
use Aurora\Core\Storage\Exception\StorageException;
use Aurora\Core\Storage\Probe\StorageProbe;
use Aurora\Core\Storage\Probe\StorageUsageProbe;
use Aurora\Core\Storage\R2\R2Configuration;
use Aurora\Core\Storage\StorageManager;
use Aurora\Module\Configuration\Storage\Setting\StorageSettings;
use Aurora\Module\Ged\Document\Repository\DocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reads and writes the Storage tab of the settings screen.
 *
 * The interesting rule is the one on switching disk: it is refused unless the
 * backend has answered a probe since its configuration last changed. Turning
 * on a bucket nobody has reached means every upload from that moment fails,
 * and the person who finds out is whoever next tries to add a document. The
 * cost of the rule is one button press.
 */
#[Route('/backend/configuration/storage', name: 'backend_configuration_storage')]
#[IsGranted('configuration.settings.manage')]
final class StorageSettingsController extends AbstractController
{
    use JsonRequestTrait;
    use JsonResponseTrait;

    public function __construct(
        private readonly StorageSettings $settings,
        private readonly StorageManager $storageManager,
        private readonly StorageProbe $probe,
        private readonly DocumentRepository $documentRepository,
        private readonly StorageUsageProbe $usage,
    ) {}

    #[Route('', name: '_show', methods: [HttpMethodEnum::Get->value])]
    public function show(): JsonResponse
    {
        // L'occupation est mesurée, pas réglée : elle est jointe ici plutôt que
        // portée par les réglages, qui n'ont pas à savoir compter des octets.
        return $this->jsonSuccess($this->settings->state() + ['usage' => $this->usage->byDisk()]);
    }

    #[Route('', name: '_save', methods: [HttpMethodEnum::Post->value])]
    public function save(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);

        $disk = StorageDiskEnum::tryFrom((string) ($payload['activeDisk'] ?? '')) ?? StorageDiskEnum::Local;
        $deliveryMode = StorageDeliveryModeEnum::tryFrom((string) ($payload['deliveryMode'] ?? ''))
            ?? StorageDeliveryModeEnum::Proxy;

        // Absent means "leave the stored credential alone"; present means
        // replace it, including with an empty string to forget it. The form
        // only sends a field somebody typed in.
        $accessKeyId = array_key_exists('accessKeyId', $payload) ? mb_trim((string) $payload['accessKeyId']) : null;
        $secretAccessKey = array_key_exists('secretAccessKey', $payload) ? mb_trim((string) $payload['secretAccessKey']) : null;

        // Checked before anything is written, against what was typed rather
        // than against what is stored: a credential of the wrong length or an
        // endpoint carrying the bucket cannot work, and R2's own refusal names
        // neither the field nor the screen. The endpoint is corrected on the
        // way in; the keys can only be reported, since nothing here can guess
        // what the right one was.
        $submitted = new R2Configuration(
            endpoint: mb_trim((string) ($payload['endpoint'] ?? '')),
            bucket: mb_trim((string) ($payload['bucket'] ?? '')),
            accessKeyId: (string) $accessKeyId,
            secretAccessKey: (string) $secretAccessKey,
        )->withNormalisedEndpoint();

        $problems = $submitted->shapeProblems();

        // Deliberately without a `state`: nothing has been written yet, so
        // there is no new state to send, and a screen handed one would
        // refresh itself - which on this form means blanking the two
        // write-only key fields. Someone who mistyped one key should not have
        // to paste both again. The refusal on an unverified switch below does
        // carry a state, because by then the credentials really were saved.
        if ([] !== $problems) {
            return $this->jsonFailure('backend.settings.storage.errors.'.$problems[0]);
        }

        $this->settings->save(
            activeDisk: StorageDiskEnum::Local,
            deliveryMode: $deliveryMode,
            endpoint: $submitted->endpoint,
            bucket: $submitted->bucket,
            accessKeyId: $accessKeyId,
            secretAccessKey: $secretAccessKey,
            publicBaseUrl: mb_trim((string) ($payload['publicBaseUrl'] ?? '')),
        );

        // Written in two passes on purpose. The credentials have to be stored
        // before the disk can be judged: a first save that carries both a new
        // bucket and the switch would otherwise be checked against the old
        // configuration.
        if (StorageDiskEnum::Local !== $disk) {
            if (null === $this->settings->verifiedAt()) {
                return $this->jsonFailure(
                    'backend.settings.storage.errors.verification_required',
                    extra: ['state' => $this->settings->state()],
                );
            }

            $this->settings->save(
                activeDisk: $disk,
                deliveryMode: $deliveryMode,
                endpoint: mb_trim((string) ($payload['endpoint'] ?? '')),
                bucket: mb_trim((string) ($payload['bucket'] ?? '')),
                accessKeyId: null,
                secretAccessKey: null,
                publicBaseUrl: mb_trim((string) ($payload['publicBaseUrl'] ?? '')),
            );
        }

        return $this->jsonSuccess($this->settings->state());
    }

    /**
     * Runs the probe against a backend and, when it passes, records that it
     * did so the toggle unlocks.
     */
    /**
     * Forgets the remote backend: both keys, the address, the bucket, the
     * verification, and new files go back to the server's disk.
     *
     * Refused while documents still live there, and that is the whole point of
     * the route existing rather than a field being blankable. The stored
     * credentials are the only way back to those bytes: erase them with rows
     * still pointing at the bucket and every one of those documents becomes a
     * broken link that nothing on the screen can explain. Bring them home
     * first, one by one or in bulk, then disconnect.
     */
    #[Route('/disconnect', name: '_disconnect', methods: [HttpMethodEnum::Post->value])]
    public function disconnect(): JsonResponse
    {
        // Nothing here can unset a server's environment variable, and the
        // environment wins over the settings table field by field. Clearing
        // the rows would leave the screen looking untouched, which reads as a
        // button that does nothing rather than as a configuration held
        // somewhere else.
        if ($this->settings->isConfiguredByEnvironment()) {
            return $this->jsonFailure('backend.settings.storage.errors.configured_by_environment');
        }

        $remaining = $this->documentRepository->countOnDisk(StorageDiskEnum::R2);

        if ($remaining > 0) {
            return $this->jsonFailure(
                'backend.settings.storage.errors.documents_still_remote',
                extra: ['remaining' => $remaining],
            );
        }

        $this->settings->disconnectR2();

        return $this->jsonSuccess($this->settings->state());
    }

    #[Route('/test', name: '_test', methods: [HttpMethodEnum::Post->value])]
    public function test(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        $disk = StorageDiskEnum::tryFrom((string) ($payload['disk'] ?? '')) ?? StorageDiskEnum::R2;

        // The save screen refuses a malformed credential, but the environment
        // is the other way in and nothing validates a deployment's variables.
        // Saying which field is wrong beats letting the probe come back with
        // Cloudflare's `InvalidArgument`, which names neither.
        $problems = StorageDiskEnum::Local === $disk
            ? []
            : $this->settings->effectiveR2Configuration()->shapeProblems();

        if ([] !== $problems) {
            return $this->jsonSuccess([
                'ok' => false,
                'steps' => [],
                'error' => null,
                'hint' => 'backend.settings.storage.errors.'.$problems[0],
                'state' => $this->settings->state(),
            ]);
        }

        try {
            $adapter = $this->storageManager->forDisk($disk);
        } catch (StorageException $storageException) {
            return $this->jsonSuccess([
                'ok' => false,
                'steps' => [],
                'error' => $storageException->getMessage(),
                'hint' => 'backend.settings.storage.hints.incomplete',
                'state' => $this->settings->state(),
            ]);
        }

        $result = $this->probe->run($adapter);

        if ($result->ok && StorageDiskEnum::Local !== $disk) {
            $this->settings->markVerified();
        }

        return $this->jsonSuccess($result->toArray() + ['state' => $this->settings->state()]);
    }
}
