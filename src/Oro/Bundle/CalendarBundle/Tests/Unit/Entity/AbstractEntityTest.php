<?php

namespace Oro\Bundle\CalendarBundle\Tests\Unit\Entity;

use Symfony\Component\PropertyAccess\PropertyAccess;

abstract class AbstractEntityTest extends \PHPUnit_Framework_TestCase
{
    /** @var Object */
    protected $entity;

    /**
     * @return string
     */
    abstract public function getEntityFQCN();

    /**
     * @return array
     */
    abstract public function getSetDataProvider();

    protected function setUp()
    {
        $name         = $this->getEntityFQCN();
        $this->entity = new $name();
    }

    public function tearDown()
    {
        unset($this->entity);
    }

    /**
     * @dataProvider  getSetDataProvider
     *
     * @param string $property
     * @param mixed  $value
     * @param mixed  $expected
     */
    public function testSetGet($property, $value = null, $expected = null)
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        if ($value !== null) {
            $propertyAccessor->setValue($this->entity, $property, $value);
        }
        $this->assertEquals($expected, $propertyAccessor->getValue($this->entity, $property));
    }
}
