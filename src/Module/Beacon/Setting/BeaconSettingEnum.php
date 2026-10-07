<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\Setting;

enum BeaconSettingEnum: string
{
    /** This deployment's identity, drawn once and kept for good. */
    case InstanceId = 'beacon.instance_id';

    /** The allowlist edited on the beacon screen, as a JSON list. */
    case KnownDomains = 'beacon.known_domains';
}
