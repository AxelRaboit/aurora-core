<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Editorial\Entity;

use Aurora\Module\Editorial\Menu\Entity\AbstractMenuItem;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToMany;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A menu entry's children must survive its deletion.
 *
 * MenuManager::deleteItem() promotes them to their grandparent before
 * removing the entry — an editor deleting a heading means "drop this label",
 * not "drop the six links under it". Two mappings quietly undid that:
 * `cascade: ['remove']` on the children collection had Doctrine delete the
 * whole branch anyway, and `onDelete: 'CASCADE'` on the parent column had
 * the database do it a second time. The promotion ran, the branch went, and
 * nothing reported a problem.
 *
 * Asserted on the mapping rather than through a Manager call because that is
 * where the bug lived — the Manager code was already right.
 */
final class MenuItemCascadeTest extends TestCase
{
    public function testChildrenAreNotCascadeRemovedWithTheirParent(): void
    {
        $property = new ReflectionProperty(AbstractMenuItem::class, 'children');
        $attributes = $property->getAttributes(OneToMany::class);

        self::assertCount(1, $attributes, 'children must stay a mapped OneToMany');

        $cascade = $attributes[0]->newInstance()->cascade ?? [];

        self::assertNotContains(
            'remove',
            $cascade,
            'cascade-remove on children deletes the branch the Manager just promoted',
        );
    }

    public function testDeletingAParentRowLeavesItsChildrenBehind(): void
    {
        $property = new ReflectionProperty(AbstractMenuItem::class, 'parent');
        $attributes = $property->getAttributes(JoinColumn::class);

        self::assertCount(1, $attributes);

        self::assertSame(
            'SET NULL',
            $attributes[0]->newInstance()->onDelete,
            'the parent column must orphan its children, not take them with it',
        );
    }
}
