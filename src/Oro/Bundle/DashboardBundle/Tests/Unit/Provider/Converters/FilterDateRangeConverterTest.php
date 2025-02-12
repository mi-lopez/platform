<?php

namespace Oro\Bundle\DashboardBundle\Tests\Unit\Provider\Converters;

use Oro\Bundle\DashboardBundle\Provider\Converters\FilterDateRangeConverter;
use Oro\Bundle\FilterBundle\Expression\Date\Compiler;
use Oro\Bundle\FilterBundle\Form\Type\Filter\AbstractDateFilterType;
use Oro\Bundle\LocaleBundle\Formatter\DateTimeFormatterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class FilterDateRangeConverterTest extends \PHPUnit\Framework\TestCase
{
    /** @var DateTimeFormatterInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $formatter;

    /** @var Compiler|\PHPUnit\Framework\MockObject\MockObject */
    private $dateCompiler;

    /** @var TranslatorInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $translator;

    /** @var FilterDateRangeConverter */
    private $converter;

    protected function setUp(): void
    {
        $this->formatter = $this->createMock(DateTimeFormatterInterface::class);
        $this->dateCompiler = $this->createMock(Compiler::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->converter = new FilterDateRangeConverter(
            $this->formatter,
            $this->dateCompiler,
            $this->translator
        );
    }

    public function testGetConvertedValueDefaultValuesWithoutValueTypes()
    {
        $result = $this->converter->getConvertedValue([]);

        $this->assertNull($result['start']);
        $this->assertNull($result['end']);
    }

    public function testGetConvertedValueDefaultValuesWithValueTypes()
    {
        $this->dateCompiler->expects($this->once())
            ->method('compile')
            ->with('{{4}}')
            ->willReturn(new \DateTime('01-01-2016 00:00:00'));
        $result = $this->converter->getConvertedValue([], null, ['options' => ['value_types' => true]]);

        $this->assertEquals('2016-01-01 00:00:00', $result['start']->format('Y-m-d H:i:s'));
        $this->assertEquals('2016-02-01 00:00:00', $result['end']->format('Y-m-d H:i:s'));
    }

    public function testGetConvertedValueBetween()
    {
        $start = new \DateTime('2014-01-01', new \DateTimeZone('UTC'));
        $end   = new \DateTime('2015-01-01', new \DateTimeZone('UTC'));

        $result = $this->converter->getConvertedValue(
            [],
            [
                'value' => [
                    'start' => $start,
                    'end'   => $end
                ],
                'type'  => AbstractDateFilterType::TYPE_BETWEEN
            ]
        );

        $this->assertSame($end, $result['end']);
        $this->assertEquals($start, $result['start']);
    }

    public function testGetConvertedValueMoreThan()
    {
        $value = new \DateTime('2014-01-01', new \DateTimeZone('UTC'));

        $result = $this->converter->getConvertedValue(
            [],
            [
                'value' => [
                    'start' => $value,
                    'end'   => null
                ],
                'type'  => AbstractDateFilterType::TYPE_MORE_THAN
            ]
        );

        $currentDate = new \DateTime('now', new \DateTimeZone('UTC'));
        // fix expected date for the last day of a month
        $currentDate->setTime(0, 0, 0)->modify('1 day');

        $this->assertEquals($currentDate->format('M'), $result['end']->format('M'));
        $this->assertEquals($value, $result['start']);
    }

    public function testGetConvertedValueLessThan()
    {
        $value = new \DateTime('2014-01-01', new \DateTimeZone('UTC'));

        $result = $this->converter->getConvertedValue(
            [],
            [
                'value' => [
                    'end'   => $value,
                    'start' => null
                ],
                'type'  => AbstractDateFilterType::TYPE_LESS_THAN
            ]
        );

        $this->assertEquals(FilterDateRangeConverter::MIN_DATE, $result['start']->format('Y-m-d'));
        $this->assertEquals($value, $result['end']);
    }

    public function testGetViewValue()
    {
        $this->formatter->expects($this->exactly(2))
            ->method('formatDate')
            ->willReturnCallback(function ($input) {
                return $input->format('Y-m-d');
            });
        $start = new \DateTime('2014-01-01', new \DateTimeZone('UTC'));
        $end = new \DateTime('2015-01-01', new \DateTimeZone('UTC'));

        $this->assertEquals(
            '2014-01-01 - 2015-01-01',
            $this->converter->getViewValue(
                [
                    'start' => $start,
                    'end'   => $end,
                    'type'  => AbstractDateFilterType::TYPE_BETWEEN,
                    'part'  => null
                ]
            )
        );
    }
}
