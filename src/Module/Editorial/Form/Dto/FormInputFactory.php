<?php

declare(strict_types=1);

namespace Aurora\Module\Editorial\Form\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(FormInputFactoryInterface::class)]
class FormInputFactory implements FormInputFactoryInterface
{
    /** @param array<string, mixed> $data */
    public function fromArray(array $data): FormInputInterface
    {
        return new FormInput(
            translations: $this->translations($data['translations'] ?? null),
            notifyEmail: Str::trimOrNull((string) ($data['notifyEmail'] ?? '')),
            webhookUrl: Str::trimOrNull((string) ($data['webhookUrl'] ?? '')),
            crmSync: (bool) ($data['crmSync'] ?? false),
            active: (bool) ($data['active'] ?? true),
            steps: $this->steps($data['steps'] ?? null),
        );
    }

    /**
     * The slug stays null when left blank - the Manager derives it from the
     * title, which is where the slugger lives.
     *
     * @return array<string, array{title: string, slug: ?string, description: ?string}>
     */
    private function translations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $translations = [];
        foreach ($raw as $locale => $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $title = Str::trimOrNull((string) ($payload['title'] ?? ''));
            if (null === $title) {
                continue;
            }

            $translations[(string) $locale] = [
                'title' => $title,
                'slug' => Str::trimOrNull((string) ($payload['slug'] ?? '')),
                'description' => Str::trimOrNull((string) ($payload['description'] ?? '')),
            ];
        }

        return $translations;
    }

    /**
     * Null and `[]` mean different things: no steps at all is a single-page
     * form, an empty list is a multi-step form nobody has named the steps of.
     * Collapsing them loses the builder's state.
     *
     * @return list<array{title: string}>|null
     */
    private function steps(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $steps = [];
        foreach ($raw as $step) {
            $title = is_array($step) ? (string) ($step['title'] ?? '') : (string) $step;
            $steps[] = ['title' => mb_trim($title)];
        }

        return $steps;
    }
}
