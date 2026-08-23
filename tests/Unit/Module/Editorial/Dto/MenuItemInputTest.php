<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Dto;

use Aurora\Module\Editorial\Menu\Dto\MenuItemInput;
use Aurora\Module\Editorial\Menu\Enum\MenuItemTargetTypeEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * What a menu entry needs alongside its target depends on the target, and
 * the message has to land on the input the editor is looking at.
 *
 * These started as `Assert\IsTrue` on getters. That form names the violation
 * after the method - `hasTargetWhenRequired` → `targetWhenRequired` - so
 * PayloadValidator returned a key no form field carries, the Vue side found
 * nothing to attach it to, and the editor saw a form that refused to save
 * with no error anywhere on it.
 */
final class MenuItemInputTest extends TestCase
{
    public function testAPostEntryWithNoTargetFailsOnTheTargetField(): void
    {
        self::assertSame(
            ['targetId' => 'backend.menus.errors.target_required'],
            $this->errors(new MenuItemInput([], MenuItemTargetTypeEnum::Post)),
        );
    }

    public function testACustomUrlEntryWithNoUrlFailsOnTheUrlField(): void
    {
        self::assertSame(
            ['customUrl' => 'backend.menus.errors.custom_url_required'],
            $this->errors(new MenuItemInput([], MenuItemTargetTypeEnum::CustomUrl)),
        );
    }

    public function testAnUnreadableUrlFailsOnTheUrlField(): void
    {
        self::assertSame(
            ['customUrl' => 'backend.menus.errors.custom_url_invalid'],
            $this->errors(new MenuItemInput([], MenuItemTargetTypeEnum::CustomUrl, customUrl: 'pas-une-url')),
        );
    }

    /** Home and the account links point at a route; there is nothing to ask for. */
    public function testATargetlessTypeNeedsNeither(): void
    {
        self::assertSame([], $this->errors(new MenuItemInput([], MenuItemTargetTypeEnum::Home)));
        self::assertSame([], $this->errors(new MenuItemInput([], MenuItemTargetTypeEnum::FrontLogin)));
    }

    public function testAWellFormedEntryPasses(): void
    {
        self::assertSame([], $this->errors(new MenuItemInput([], MenuItemTargetTypeEnum::Post, targetId: 7)));
        self::assertSame(
            [],
            $this->errors(new MenuItemInput([], MenuItemTargetTypeEnum::CustomUrl, customUrl: 'https://exemple.fr/page')),
        );
    }

    /** @return array<string, string> field path → message, as PayloadValidator returns them */
    private function errors(MenuItemInput $input): array
    {
        $errors = [];
        foreach ($this->validator()->validate($input) as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $errors;
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }
}
