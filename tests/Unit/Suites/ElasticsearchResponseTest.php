<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use PHPUnit\Framework\TestCase;
use LizardsAndPumpkins\Import\Product\ProductId;
use LizardsAndPumpkins\Import\Product\AttributeCode;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldValue;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\ElasticsearchException;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchResponse
 */
class ElasticsearchResponseTest extends TestCase
{
    /**
     * @var FacetFieldTransformationRegistry|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubFacetFieldTransformationRegistry;

    protected function setUp()
    {
        $this->stubFacetFieldTransformationRegistry = $this->createMock(FacetFieldTransformationRegistry::class);
    }

    public function testExceptionIsThrownIfElasticsearchResponseContainsErrorMessage()
    {
        $testErrorMessage = 'Test error message.';
        $responseArray = ['error' => ['msg' => $testErrorMessage]];

        $this->expectException(ElasticsearchException::class);
        $this->expectExceptionMessage($testErrorMessage);

        ElasticsearchResponse::fromElasticsearchResponseArray($responseArray, $this->stubFacetFieldTransformationRegistry);
    }

    public function testZeroIsReturnedIfElasticsearchResponseDoesNotContainTotalNumberOfResultsElement()
    {
        $responseArray = [];
        $response = ElasticsearchResponse::fromElasticsearchResponseArray($responseArray, $this->stubFacetFieldTransformationRegistry);

        $this->assertSame(0, $response->getTotalNumberOfResults());
    }

    public function testTotalNumberOfResultsIsReturnedForElasticsearchLowerThanSeven()
    {
        $responseArray = ['hits' => ['total' => 5]];
        $response = ElasticsearchResponse::fromElasticsearchResponseArray($responseArray, $this->stubFacetFieldTransformationRegistry);

        $this->assertSame(5, $response->getTotalNumberOfResults());
    }

    public function testTotalNumberOfResultsIsReturnedForElasticsearchSeven()
    {
        $responseArray = ['hits' => ['total' => ['value' => 20, 'relation' => 'eq']]];
        $response = ElasticsearchResponse::fromElasticsearchResponseArray($responseArray, $this->stubFacetFieldTransformationRegistry);

        $this->assertSame(20, $response->getTotalNumberOfResults());
    }

    public function testEmptyArrayIsReturnedIfElasticsearchResponseDoesNotContainDocumentsElement()
    {
        $responseArray = [];
        $response = ElasticsearchResponse::fromElasticsearchResponseArray($responseArray, $this->stubFacetFieldTransformationRegistry);

        $this->assertSame([], $response->getMatchingProductIds());
    }

    public function testMatchingProductIdsAreReturned()
    {
        $responseArray = [
            'hits' => [
                'hits' => [
                    ['_source' => [ElasticsearchSearchEngine::PRODUCT_ID_FIELD_NAME => 'foo']],
                    ['_source' => [ElasticsearchSearchEngine::PRODUCT_ID_FIELD_NAME => 'bar']],
                ]
            ]
        ];
        $response = ElasticsearchResponse::fromElasticsearchResponseArray($responseArray, $this->stubFacetFieldTransformationRegistry);
        $expectedArray = [new ProductId('foo'), new ProductId('bar')];

        $this->assertEquals($expectedArray, $response->getMatchingProductIds());
    }

    public function testEmptyArrayIsReturnedIfNeitherFacetFieldsNorFacetQueriesArePresentInResponseArray()
    {
        $responseArray = [];
        $selectedFilterAttributeCodes = [];

        $response = ElasticsearchResponse::fromElasticsearchResponseArray($responseArray, $this->stubFacetFieldTransformationRegistry);

        $this->assertSame([], $response->getNonSelectedFacetFields($selectedFilterAttributeCodes));
    }

    public function testAggregationForNonRangedFacetFieldIsReturned()
    {
        $attributeCodeString = 'foo';
        $attributeValue = 'bar';
        $attributeValueCount = 2;

        $responseArray = [
            'aggregations' => [
                $attributeCodeString => [
                    'buckets' => [
                        [
                            'key' => $attributeValue,
                            'doc_count' => $attributeValueCount
                        ]
                    ]
                ]
            ]
        ];
        $selectedFilterAttributeCodes = [];

        $response = ElasticsearchResponse::fromElasticsearchResponseArray($responseArray, $this->stubFacetFieldTransformationRegistry);

        $expectedFacetField = new FacetField(
            AttributeCode::fromString($attributeCodeString),
            new FacetFieldValue($attributeValue, $attributeValueCount)
        );

        $this->assertEquals([$expectedFacetField], $response->getNonSelectedFacetFields($selectedFilterAttributeCodes));
    }
}
