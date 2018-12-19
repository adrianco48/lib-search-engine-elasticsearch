<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use stdClass;
use LizardsAndPumpkins\Util\Storage\Clearable;
use LizardsAndPumpkins\ProductSearch\QueryOptions;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetField;
use LizardsAndPumpkins\DataPool\SearchEngine\Query\SortBy;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchEngine;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchEngineResponse;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldCollection;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocument;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\ElasticsearchHttpClient;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;

class ElasticsearchSearchEngine implements SearchEngine, Clearable
{
    const FULL_TEXT_SEARCH_FIELD_NAME = 'full_text_search';
    const DOCUMENT_ID_FIELD_NAME = 'id';
    const PRODUCT_ID_FIELD_NAME = 'product_id';

    const SORTING_SUFFIX = '';

    /**
     * @var ElasticsearchHttpClient
     */
    private $client;

    /**
     * @var FacetFieldTransformationRegistry
     */
    private $facetFieldTransformationRegistry;

    public function __construct(
        ElasticsearchHttpClient $client,
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry
    ) {
        $this->client = $client;
        $this->facetFieldTransformationRegistry = $facetFieldTransformationRegistry;
    }

    public function addDocument(SearchDocument $document)
    {
        $elasticsearchDocument = ElasticsearchDocumentBuilder::fromSearchDocument($document);
        $this->client->update(
            $elasticsearchDocument[ElasticsearchSearchEngine::DOCUMENT_ID_FIELD_NAME],
            $elasticsearchDocument
        );
    }

    public function query(SearchCriteria $criteria, QueryOptions $queryOptions) : SearchEngineResponse
    {
        $filterSelection = $queryOptions->getFilterSelection();
        $query = new ElasticsearchQuery(
            $criteria,
            $queryOptions->getContext(),
            $this->facetFieldTransformationRegistry,
            $filterSelection
        );

        $facetFiltersToIncludeInResult = $queryOptions->getFacetFiltersToIncludeInResult();
        $aggregationsRequest = new ElasticsearchAggregationsRequest(
            $facetFiltersToIncludeInResult,
            $this->facetFieldTransformationRegistry
        );

        $response = $this->queryElasticsearch($query, $aggregationsRequest, $queryOptions);

        $totalNumberOfResults = $response->getTotalNumberOfResults();
        $matchingProductIds = $response->getMatchingProductIds();
        $facetFieldsCollection = $this->getFacetFieldCollectionFromElasticsearchResponse(
            $response,
            $criteria,
            $queryOptions,
            $filterSelection,
            $facetFiltersToIncludeInResult
        );

        return new SearchEngineResponse($facetFieldsCollection, $totalNumberOfResults, ...$matchingProductIds);
    }

    public function clear()
    {
        $request = ['query' => ['match_all' => new stdClass()]];
        $this->client->clear($request);
    }

    /**
     * @param ElasticsearchResponse $response
     * @param SearchCriteria $criteria
     * @param array[] $filterSelection
     * @param FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
     * @return FacetFieldCollection
     */
    private function getFacetFieldCollectionFromElasticsearchResponse(
        ElasticsearchResponse $response,
        SearchCriteria $criteria,
        QueryOptions $queryOptions,
        array $filterSelection,
        FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
    ) : FacetFieldCollection {
        $selectedFilterAttributeCodes = array_keys($filterSelection);
        $nonSelectedFacetFields = $response->getNonSelectedFacetFields($selectedFilterAttributeCodes);
        $selectedFacetFields = $this->getSelectedFacetFields(
            $filterSelection,
            $criteria,
            $queryOptions,
            $facetFiltersToIncludeInResult
        );

        return new FacetFieldCollection(...$nonSelectedFacetFields, ...$selectedFacetFields);
    }

    /**
     * @param array[] $filterSelection
     * @param SearchCriteria $criteria
     * @param FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
     * @return FacetField[]
     */
    private function getSelectedFacetFields(
        array $filterSelection,
        SearchCriteria $criteria,
        QueryOptions $queryOptions,
        FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
    ) : array {
        $selectedAttributeCodes = array_keys($filterSelection);
        $facetFields = [];

        foreach ($selectedAttributeCodes as $attributeCodeString) {
            $selectedFiltersExceptCurrentOne = array_diff_key($filterSelection, [$attributeCodeString => []]);
            $query = new ElasticsearchQuery(
                $criteria,
                $queryOptions->getContext(),
                $this->facetFieldTransformationRegistry,
                $selectedFiltersExceptCurrentOne
            );

            $aggregationsRequest = new ElasticsearchAggregationsRequest(
                $facetFiltersToIncludeInResult,
                $this->facetFieldTransformationRegistry
            );
            $response = $this->queryElasticsearch($query, $aggregationsRequest, $queryOptions);
            $facetFieldsSiblings = $response->getNonSelectedFacetFields(array_keys($selectedFiltersExceptCurrentOne));
            $facetFields = array_merge($facetFields, $facetFieldsSiblings);
        }

        return $facetFields;
    }

    private function queryElasticsearch(
        ElasticsearchQuery $query,
        ElasticsearchAggregationsRequest $aggregationsRequest,
        QueryOptions $queryOptions
    ) : ElasticsearchResponse {
        $request = [];
        
        $query = $query->toArray();
        if (count($query) !== 0) {
            $request['query'] = $query;
        }
        
        $aggregations = $aggregationsRequest->toArray();
        if (count($aggregations) !== 0) {
            $request['aggregations'] = $aggregations;
        }

        $rowsPerPage = $queryOptions->getRowsPerPage();
        $request['size'] = $rowsPerPage;
        
        $offset = $queryOptions->getPageNumber() * $rowsPerPage;
        $request['from'] = $offset;
        
        $sortOrderArray = $this->getSortOrderArray($queryOptions->getSortBy());
        $request['sort'] = $sortOrderArray;

        $response = $this->client->select($request);

        return ElasticsearchResponse::fromElasticsearchResponseArray(
            $response,
            $this->facetFieldTransformationRegistry
        );
    }

    private function getSortOrderArray(SortBy $sortOrderConfig) : array
    {
        return [
            [
                sprintf('%s%s', $sortOrderConfig->getAttributeCode(), self::SORTING_SUFFIX) => [
                    'order' => (string)$sortOrderConfig->getSelectedDirection()
                ]
            ]
        ];
    }

    public static function create(ElasticsearchHttpClient $elasticsearchCurlClient, $facetFieldTransformationRegistry)
    {
        return new self($elasticsearchCurlClient, $facetFieldTransformationRegistry);
    }
}
