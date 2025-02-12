<?php

namespace Oro\Bundle\EntityMergeBundle\Tests\Unit\DataGrid\Extension\MassAction;

use Oro\Bundle\DataGridBundle\Extension\Action\ActionConfiguration;
use Oro\Bundle\EntityConfigBundle\Config\Config as EntityConfig;
use Oro\Bundle\EntityConfigBundle\Config\Id\ConfigIdInterface;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\EntityMergeBundle\DataGrid\Extension\MassAction\MergeMassAction;
use Symfony\Contracts\Translation\TranslatorInterface;

class MergeMassActionTest extends \PHPUnit\Framework\TestCase
{
    private const MAX_ENTITIES_COUNT = 1;

    /** @var MergeMassAction */
    private $target;

    protected function setUp(): void
    {
        $entityConfigProvider = $this->createMock(ConfigProvider::class);
        $entityConfig = new EntityConfig(
            $this->createMock(ConfigIdInterface::class),
            ['max_element_count' => self::MAX_ENTITIES_COUNT]
        );
        $entityConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with('SomeEntityClass')
            ->willReturn($entityConfig);

        $translator = $this->createMock(TranslatorInterface::class);

        $this->target = new MergeMassAction($entityConfigProvider, $translator);
    }

    /**
     * @dataProvider getOptionsDataProvider
     */
    public function testGetOptions(array $actualOptions, array $expectedOptions)
    {
        $this->target->setOptions(ActionConfiguration::create($actualOptions));
        $this->assertEquals($expectedOptions, $this->target->getOptions()->toArray());
    }

    public function getOptionsDataProvider(): array
    {
        return [
            'default_values'  => [
                'actual'   => [
                    'entity_name' => 'SomeEntityClass'
                ],
                'expected' => [
                    'entity_name'       => 'SomeEntityClass',
                    'frontend_handle'   => 'redirect',
                    'handler'           => 'oro_entity_merge.mass_action.data_handler',
                    'frontend_type'     => 'merge-mass',
                    'route'             => 'oro_entity_merge_massaction',
                    'data_identifier'   => 'id',
                    'max_element_count' => self::MAX_ENTITIES_COUNT,
                    'label'             => null,
                    'route_parameters'  => [],
                    'launcherOptions'   => ['iconClassName' => 'fa-random'],
                    'allowedRequestTypes' => ['GET'],
                    'requestType'       => 'GET'
                ]
            ],
            'override_values' => [
                'actual'   => [
                    'entity_name'       => 'SomeEntityClass',
                    'frontend_handle'   => 'custom_handler',
                    'handler'           => 'oro_entity_merge.mass_action.data_handler',
                    'frontend_type'     => 'custom-merge-mass',
                    'data_identifier'   => 'code',
                    'icon'              => 'custom',
                    'max_element_count' => self::MAX_ENTITIES_COUNT,
                    'route'             => 'oro_entity_merge_massaction',
                    'route_parameters'  => []
                ],
                'expected' => [
                    'entity_name'       => 'SomeEntityClass',
                    'frontend_handle'   => 'custom_handler',
                    'handler'           => 'oro_entity_merge.mass_action.data_handler',
                    'frontend_type'     => 'custom-merge-mass',
                    'data_identifier'   => 'code',
                    'launcherOptions'   => ['iconClassName' => 'fa-custom'],
                    'max_element_count' => self::MAX_ENTITIES_COUNT,
                    'route'             => 'oro_entity_merge_massaction',
                    'route_parameters'  => [],
                    'label'             => null,
                    'allowedRequestTypes' => ['GET'],
                    'requestType'       => 'GET'
                ]
            ]
        ];
    }

    public function testMergeMassActionSetOptionShouldThrowExceptionIfClassNameOptionIsEmpty()
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Trying to get name of unnamed object');

        $this->target->setOptions(ActionConfiguration::create([]));
    }
}
