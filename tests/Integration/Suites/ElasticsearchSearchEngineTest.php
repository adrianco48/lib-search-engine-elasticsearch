<?php

declare(strict_types=1);

namespace LizardsAndPumpkins;

use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\Context\SelfContainedContextBuilder;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchSearchEngine;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\ElasticsearchException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\CurlElasticsearchHttpClient;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\Exception\ElasticsearchConnectionException;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\DataPool\SearchEngine\IntegrationTest\AbstractSearchEngineTest;
use LizardsAndPumpkins\DataPool\SearchEngine\Query\SortBy;
use LizardsAndPumpkins\DataPool\SearchEngine\Query\SortDirection;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriterionEqual;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchEngine;
use LizardsAndPumpkins\Import\Product\AttributeCode;
use LizardsAndPumpkins\ProductSearch\QueryOptions;
use LizardsAndPumpkins\Util\Config\EnvironmentConfigReader;
use LizardsAndPumpkins\Util\Storage\Clearable;

class ElasticsearchSearchEngineTest extends AbstractSearchEngineTest
{
    private function createTestContext() : Context
    {
        return SelfContainedContextBuilder::rehydrateContext([
            'website' => 'website',
            'version' => '-1'
        ]);
    }

    private function createTestSortOrderConfig(string $sortByFieldCode, string $sortDirection) : SortBy
    {
        return new SortBy(AttributeCode::fromString($sortByFieldCode), SortDirection::create($sortDirection));
    }

    private function createTestQueryOptions(SortBy $sortOrderConfig) : QueryOptions
    {
        $filterSelection = [];
        $facetFiltersToIncludeInResult = new FacetFiltersToIncludeInResult();
        $rowsPerPage = 100;
        $pageNumber = 0;

        return QueryOptions::create(
            $filterSelection,
            $this->createTestContext(),
            $facetFiltersToIncludeInResult,
            $rowsPerPage,
            $pageNumber,
            $sortOrderConfig
        );
    }

    protected function tearDown()
    {
        $facetFieldTransformationRegistry = new FacetFieldTransformationRegistry();
        $this->createSearchEngineInstance($facetFieldTransformationRegistry)->clear();
    }

    /**
     * @param FacetFieldTransformationRegistry $facetFieldTransformationRegistry
     * @return SearchEngine|Clearable
     */
    final protected function createSearchEngineInstance(
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry
    ) : SearchEngine {
        $config = EnvironmentConfigReader::fromGlobalState();
        $testConnectionPath = $config->get('elasticsearch_integration_test_connection_path');

        $client = new IntegrationTestHttpClient($testConnectionPath);

        return new ElasticsearchSearchEngine($client, $facetFieldTransformationRegistry);
    }

    public function testExceptionIsThrownIfQueryIsInvalid()
    {
        $nonExistingFieldCode = 'foooooooo';
        $fieldValue = 'whatever';

        $facetFieldTransformationRegistry = new FacetFieldTransformationRegistry();
        $searchEngine = $this->createSearchEngineInstance($facetFieldTransformationRegistry);

        $searchCriteria = new SearchCriterionEqual($nonExistingFieldCode, $fieldValue);
        $sortOrderConfig = $this->createTestSortOrderConfig($nonExistingFieldCode, SortDirection::ASC);

        $this->expectException(ElasticsearchException::class);
        $this->expectExceptionMessage(sprintf('No mapping found for [%s] in order to sort on', $nonExistingFieldCode));

        $searchEngine->query($searchCriteria, $this->createTestQueryOptions($sortOrderConfig));
    }

    public function testExceptionIsThrownIfElasticsearchIsNotAccessible()
    {
        $fieldCode = 'foo';
        $fieldValue = 'bar';

        $testConnectionPath = 'http://localhost:80/';
        $client = new CurlElasticsearchHttpClient($testConnectionPath);

        $facetFieldTransformationRegistry = new FacetFieldTransformationRegistry();

        $searchEngine = new ElasticsearchSearchEngine($client, $facetFieldTransformationRegistry);

        $this->expectException(ElasticsearchConnectionException::class);

        $searchCriteria = new SearchCriterionEqual($fieldCode, $fieldValue);
        $sortOrderConfig = $this->createTestSortOrderConfig($fieldCode, SortDirection::ASC);

        $searchEngine->query($searchCriteria, $this->createTestQueryOptions($sortOrderConfig));
    }
}
