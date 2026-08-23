<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\Service;

use Aurora\Core\Frontend\Contract\FrontendInterface;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use RuntimeException;

final readonly class Router
{
    public function __construct(
        private Registry $registry,
        private SettingRepository $settingRepository,
    ) {}

    public function getDefault(): FrontendInterface
    {
        // Unset by default: with no slug configured - or one naming a front
        // that is no longer installed - the highest-priority registered front
        // wins, so the public site keeps answering either way.
        $slug = $this->settingRepository->get(ApplicationParameterEnum::DefaultFront->value, '') ?? '';

        return $this->registry->find($slug) ?? $this->registry->highest() ?? throw new RuntimeException('No front registered.');
    }
}
