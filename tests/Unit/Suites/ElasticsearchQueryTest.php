<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaConditionException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorNotDefined;
use PHPUnit\Framework\TestCase;
use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRange;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\CompositeSearchCriterion;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolFilter;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolShould;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformation;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorEqual;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorAnything;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorLessOrEqualThan;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorGreaterOrEqualThan;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaOperationException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\InvalidSearchCriteriaOperationFormatException;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchQuery
 * @uses   \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorEqual
 * @uses   \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorGreaterOrEqualThan
 */
class ElasticsearchQueryTest extends TestCase
{
    /**
     * @var SearchCriteria|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubCriteria;

    /**
     * @var Context|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubContext;

    /**
     * @var FacetFieldTransformationRegistry|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubFacetFieldTransformationRegistry;

    protected function setUp()
    {
        $this->stubCriteria = $this->createMock(CompositeSearchCriterion::class);
        $this->stubContext = $this->createMock(Context::class);
        $this->stubFacetFieldTransformationRegistry = $this->createMock(FacetFieldTransformationRegistry::class);
    }

    public function testExceptionIsThrownIfElasticsearchOperationIsUnknown()
    {
        $this->expectException(UnsupportedSearchCriteriaOperationException::class);

        $this->stubCriteria->method('jsonSerialize')->willReturn([
            'fieldName' => 'foo',
            'fieldValue' => 'bar',
            'operation' => 'non-existing-operation'
        ]);

        $filters = [];

        (new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        ))->toArray();
    }
    
    public function testExceptionIsThrownIfElasticsearchConditionIsUnknown()
    {
        $this->expectException(UnsupportedSearchCriteriaConditionException::class);

        $this->stubCriteria->method('jsonSerialize')->willReturn([
            'condition' => 'non-existing-condition',
            'criteria' => [
                [
                    'operation' => 'Equal',
                    'fieldName' => 'foo',
                    'fieldValue' => 'bar',
                ]
            ]
        ]);

        $filters = [];

        (new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        ))->toArray();
    }
    
    public function testExceptionIsThrownIfSearchCriteriaOperationFormatInvalid()
    {
        $this->expectException(InvalidSearchCriteriaOperationFormatException::class);

        $this->stubCriteria->method('jsonSerialize')->willReturn([
            'invalid-fieldName-key' => '',
            'invalid-fieldValue-key' => '',
            'invalid-operation-key' => ''
        ]);

        $filters = [];

        (new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        ))->toArray();
    }

    public function testArrayRepresentationOfQueryContainsJoinedSourceMatchingBools()
    {
        $this->stubCriteria->method('jsonSerialize')->willReturn([
            'condition' => CompositeSearchCriterion::OR_CONDITION,
            'criteria' => [
                [
                    'operation' => 'Equal',
                    'fieldName' => 'foo',
                    'fieldValue' => 'bar',
                ],
                [
                    'operation' => 'GreaterOrEqualThan',
                    'fieldName' => 'baz',
                    'fieldValue' => 1,
                ],
            ]
        ]);

        $this->stubContext->method('getSupportedCodes')->willReturn(['qux']);
        $this->stubContext->method('getValue')->willReturnMap([['qux', 2]]);

        $rangedAttributeOption1 = '-100';
        $rangedAttributeOption2 = '200-300';
        $rangedAttributeOption3 = '400-';
        $rangedAttributeOption4 = '-';

        $filters = [
            'non_ranged_attribute' => ['option1', 'option2'],
            'ranged_attribute' => [
                $rangedAttributeOption1,
                $rangedAttributeOption2,
                $rangedAttributeOption3,
                $rangedAttributeOption4
            ]
        ];

        $stubFacetFilterRange1 = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange1->method('from')->willReturn(null);
        $stubFacetFilterRange1->method('to')->willReturn('123');

        $stubFacetFilterRange2 = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange2->method('from')->willReturn('246');
        $stubFacetFilterRange2->method('to')->willReturn('369');

        $stubFacetFilterRange3 = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange3->method('from')->willReturn('492');
        $stubFacetFilterRange3->method('to')->willReturn(null);
        
        $stubFacetFilterRange4 = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange4->method('from')->willReturn(null);
        $stubFacetFilterRange4->method('to')->willReturn(null);
        
        $stubFacetFieldTransformation = $this->createMock(FacetFieldTransformation::class);
        $stubFacetFieldTransformation->method('decode')->willReturnMap([
            [$rangedAttributeOption1, $stubFacetFilterRange1],
            [$rangedAttributeOption2, $stubFacetFilterRange2],
            [$rangedAttributeOption3, $stubFacetFilterRange3],
            [$rangedAttributeOption4, $stubFacetFilterRange4]
        ]);

        $this->stubFacetFieldTransformationRegistry->method('hasTransformationForCode')->willReturnMap([
            ['non_ranged_attribute', false],
            ['ranged_attribute', true]
        ]);

        $this->stubFacetFieldTransformationRegistry->method('getTransformationByCode')
            ->with('ranged_attribute')
            ->willReturn($stubFacetFieldTransformation);

        $elasticsearchQuery = new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        );

        $result = $elasticsearchQuery->toArray();
        $expectedQueryArray = (new ElasticsearchQueryBoolFilter())->getFormattedArray([
            $expectedCriteriaBool = (new ElasticsearchQueryBoolFilter())->getFormattedArray(
                (new ElasticsearchQueryBoolShould())->getFormattedArray([
                    (new ElasticsearchQueryOperatorEqual())->getFormattedArray('foo', 'bar'),
                    (new ElasticsearchQueryOperatorGreaterOrEqualThan())->getFormattedArray('baz', '1')
                ])
            ),
            $expectedContextBool = (new ElasticsearchQueryBoolFilter())->getFormattedArray([
                (new ElasticsearchQueryBoolShould())->getFormattedArray([
                    (new ElasticsearchQueryOperatorEqual())->getFormattedArray('qux', '2'),
                    (new ElasticsearchQueryOperatorNotDefined())->getFormattedArray('qux', 'whatever'),
                ])
            ]),
            $expectedFiltersBool = (new ElasticsearchQueryBoolFilter())->getFormattedArray([
                $expectedNonRangedAttributeFilterBool = (new ElasticsearchQueryBoolShould())->getFormattedArray([
                    (new ElasticsearchQueryOperatorEqual())->getFormattedArray('non_ranged_attribute', 'option1'),
                    (new ElasticsearchQueryOperatorEqual())->getFormattedArray('non_ranged_attribute', 'option2')
                ]),
                $expectedRangedAttributeFilterBool = (new ElasticsearchQueryBoolShould())->getFormattedArray([
                    (new ElasticsearchQueryOperatorLessOrEqualThan())->getFormattedArray('ranged_attribute', '123'),
                    (new ElasticsearchQueryBoolFilter())->getFormattedArray([
                        (new ElasticsearchQueryOperatorGreaterOrEqualThan())->getFormattedArray('ranged_attribute', '246'),
                        (new ElasticsearchQueryOperatorLessOrEqualThan())->getFormattedArray('ranged_attribute', '369'),
                    ]),
                    (new ElasticsearchQueryOperatorGreaterOrEqualThan())->getFormattedArray('ranged_attribute', '492'),
                    (new ElasticsearchQueryOperatorAnything())->getFormattedArray()
                ])
            ])
        ]);

        $this->assertArrayHasKey('bool', $result);
        $this->assertSame(json_encode($expectedQueryArray), json_encode($result));
    }

    public function testArrayRepresentationOfElasticsearchQueryIsMemoized()
    {
        $this->stubCriteria->expects($this->once())->method('jsonSerialize')->willReturn([
            'fieldName' => 'foo',
            'fieldValue' => 'bar',
            'operation' => 'Equal'
        ]);

        $filters = [];

        $elasticsearchQuery = new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        );

        $resultA = $elasticsearchQuery->toArray();
        $resultB = $elasticsearchQuery->toArray();

        $this->assertSame($resultA, $resultB);
    }

    public function testEmptySourcesTranslateToAnythingOperators()
    {
        $this->stubCriteria->expects($this->once())->method('jsonSerialize')->willReturn([]);
        $this->stubContext->method('getSupportedCodes')->willReturn([]);
        $filters = [];
        
        $elasticsearchQuery = new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        );

        $result = $elasticsearchQuery->toArray();
        $expectedQueryJson = json_encode(
            (new ElasticsearchQueryBoolFilter())->getFormattedArray([
                (new ElasticsearchQueryOperatorAnything())->getFormattedArray(),
                (new ElasticsearchQueryOperatorAnything())->getFormattedArray(),
                (new ElasticsearchQueryOperatorAnything())->getFormattedArray()
            ])
        );
        
        $this->assertSame($expectedQueryJson, json_encode($result));
    }
    
    public function testInvalidSubCriteriaTranslatesToAnythingOperator()
    {
        $this->stubCriteria->expects($this->once())->method('jsonSerialize')->willReturn([
            'condition' => CompositeSearchCriterion::OR_CONDITION,
            'criteria' => 'sub-criteria-key-exists-but-is-not-an-expected-non-empty-array'
        ]);

        $this->stubContext->method('getSupportedCodes')->willReturn([]);
        $filters = [];

        $elasticsearchQuery = new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        );

        $result = $elasticsearchQuery->toArray();
        $expectedBoolJson = json_encode(
            (new ElasticsearchQueryBoolFilter())->getFormattedArray(
                (new ElasticsearchQueryOperatorAnything())->getFormattedArray()
            )
        );
        
        $this->assertArrayHasKey('bool', $result);
        $this->assertArrayHasKey('filter', $result['bool']);
        $this->assertArrayHasKey(0, $result['bool']['filter']);
        
        $this->assertSame($expectedBoolJson, json_encode($result['bool']['filter'][0]));
    }

    public function testFiltersSourceContainsOneAttributeWithNoSelectedValuesTranslatesToAnythingOperator()
    {
        $this->stubCriteria->expects($this->once())->method('jsonSerialize')->willReturn([]);
        $this->stubContext->method('getSupportedCodes')->willReturn([]);
        $filters = [
            'attribute' => []
        ];

        $elasticsearchQuery = new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        );

        $result = $elasticsearchQuery->toArray();
        $expectedBoolJson = json_encode(
            (new ElasticsearchQueryOperatorAnything())->getFormattedArray()
        );

        $this->assertArrayHasKey('bool', $result);
        $this->assertArrayHasKey('filter', $result['bool']);
        $this->assertArrayHasKey(2, $result['bool']['filter']);

        $this->assertSame($expectedBoolJson, json_encode($result['bool']['filter'][2]));
    }
}
