<?php

namespace Oro\Bundle\NavigationBundle\Tests\Unit\Entity;

use Oro\Component\Testing\Unit\EntityTestCaseTrait;

use Oro\Bundle\NavigationBundle\Entity\MenuUpdate;

class MenuUpdateTest extends \PHPUnit_Framework_TestCase
{
    use EntityTestCaseTrait;

    public function testProperties()
    {
        $properties = [
            ['title', 'test title'],
        ];

        $this->assertPropertyAccessors(new MenuUpdate(), $properties);
    }

    public function testGetExtras()
    {
        $title = 'test title';

        $update = new MenuUpdate();
        $update->setTitle($title);

        $this->assertEquals(['title' => $title], $update->getExtras());
    }
}
