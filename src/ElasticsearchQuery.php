<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolMust;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorNotDefined;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRange;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolFilter;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolShould;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperator;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorEqual;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorAnything;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorLessOrEqualThan;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorGreaterOrEqualThan;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaConditionException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaOperationException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\InvalidSearchCriteriaOperationFormatException;

class ElasticsearchQuery
{
    /**
     * @var SearchCriteria
     */
    private $criteria;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var mixed[]
     */
    private $filters;

    /**
     * @var mixed[]
     */
    private $memoizedElasticsearchQueryArrayRepresentation;
    /**
     * @var FacetFieldTransformationRegistry
     */
    private $facetFieldTransformationRegistry;

    public function __construct(
        SearchCriteria $criteria,
        Context $context,
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry,
        array $filters
    ) {
        $this->criteria = $criteria;
        $this->context = $context;
        $this->facetFieldTransformationRegistry = $facetFieldTransformationRegistry;
        $this->filters = $filters;
    }

    /**
     * @return array[]
     */
    public function toArray() : array
    {
        if (null === $this->memoizedElasticsearchQueryArrayRepresentation) {
            $this->memoizedElasticsearchQueryArrayRepresentation = $this->getElasticsearchQueryArrayRepresentation();
        }
        
        return $this->memoizedElasticsearchQueryArrayRepresentation;
    }

    /**
     * @return array[]
     */
    private function getElasticsearchQueryArrayRepresentation() : array
    {
        $criteriaBool = $this->convertCriteriaIntoElasticsearchBool($this->criteria);
        $contextBool = $this->convertContextIntoElasticsearchBool($this->context);
        $filtersBool = $this->convertFiltersIntoElasticsearchBools($this->filters);

        return $this->getBoolFilterArrayRepresentation([$criteriaBool, $contextBool, $filtersBool]);
    }

    private function convertCriteriaIntoElasticsearchBool(SearchCriteria $criteria) : array
    {
        $criteriaJson = json_encode($criteria);
        $criteriaArray = json_decode($criteriaJson, true);
        
        if (0 === count($criteriaArray)) {
            return (new ElasticsearchQueryOperatorAnything())->getFormattedArray();
        }

        return $this->getBoolFilterArrayRepresentation(
            $this->createElasticsearchQueryBoolArrayFromCriteriaArray($criteriaArray)
        );
    }

    /**
     * @param mixed[] $criteria
     * @return array[]
     */
    private function createElasticsearchQueryBoolArrayFromCriteriaArray(array $criteria) : array
    {
        if (isset($criteria['condition'])) {
            if (!isset($criteria['criteria'])
                || !is_array($criteria['criteria'])
                || 0 === count($criteria['criteria'])
            ) {
                return (new ElasticsearchQueryOperatorAnything())->getFormattedArray();
            }

            $subBools = array_map(
                [$this, 'createElasticsearchQueryBoolArrayFromCriteriaArray'],
                $criteria['criteria']
            );

            return $this->packSubCriteriaIntoElasticsearchSubBools($criteria['condition'], $subBools);
        }

        return $this->createPrimitiveOperator($criteria);
    }

    /**
     * @param string $condition
     * @param mixed[] $subBools
     * @return mixed[]
     */
    private function packSubCriteriaIntoElasticsearchSubBools(string $condition, array $subBools)
    {
        if ('and' === $condition) {
            return $this->getBoolMustArrayRepresentation($subBools);
        }

        if ('or' === $condition) {
            return $this->getBoolShouldArrayRepresentation($subBools);
        }

        throw new UnsupportedSearchCriteriaConditionException(
            sprintf('Unsupported criteria condition "%s".', $condition)
        );
    }

    /**
     * @param string[] $criteria
     * @return array[]
     */
    private function createPrimitiveOperator(array $criteria) : array
    {
        if (!isset($criteria['fieldName'], $criteria['fieldValue'], $criteria['operation'])) {
            throw new InvalidSearchCriteriaOperationFormatException(
                sprintf('Invalid search criteria operation format')
            );
        }
        
        $operator = $this->getElasticsearchOperator($criteria['operation']);
        return $operator->getFormattedArray((string)$criteria['fieldName'], (string)$criteria['fieldValue']);
    }

    private function getElasticsearchOperator(string $operation) : ElasticsearchQueryOperator
    {
        $className = __NAMESPACE__ . '\\Operator\\ElasticsearchQueryOperator' . $operation;

        if (!class_exists($className)) {
            throw new UnsupportedSearchCriteriaOperationException(
                sprintf('Unsupported criterion operation "%s".', $operation)
            );
        }

        return new $className;
    }

    /**
     * @param Context $context
     * @return mixed[]
     */
    private function convertContextIntoElasticsearchBool(Context $context) : array
    {
        $supportedCodes = $context->getSupportedCodes();
        
        if (0 === count($supportedCodes)) {
            return (new ElasticsearchQueryOperatorAnything())->getFormattedArray();
        }

        $contents = array_map(function ($contextCode) use ($context) {
            $fieldName = (string) $contextCode;
            $fieldValue = (string) $context->getValue($contextCode);

            return (new ElasticsearchQueryBoolShould())->getFormattedArray([
                (new ElasticsearchQueryOperatorEqual())->getFormattedArray($fieldName, $fieldValue),
                (new ElasticsearchQueryOperatorNotDefined())->getFormattedArray($fieldName, $fieldValue),
            ]);
        }, $supportedCodes);

        return $this->getBoolFilterArrayRepresentation($contents);
    }

    /**
     * @param mixed[] $filters
     * @return mixed[]
     */
    private function convertFiltersIntoElasticsearchBools($filters): array
    {
        $innerBools = array_reduce(array_keys($filters), function (array $carry, $filterCode) use ($filters) {
            if (count($filters[$filterCode]) > 0) {
                $carry[] = $this->getBoolShouldArrayRepresentation(
                    $this->getElasticsearchBoolsFromFilterValues($filterCode, $filters[$filterCode])
                );
            }
            return $carry;
        }, []);

        if (0 === count($innerBools)) {
            return (new ElasticsearchQueryOperatorAnything())->getFormattedArray();
        }

        return $this->getBoolFilterArrayRepresentation($innerBools);
    }

    /**
     * @param string $filterCode
     * @param string[] $filterValues
     * @return mixed[]
     */
    private function getElasticsearchBoolsFromFilterValues(string $filterCode, array $filterValues) : array
    {
        if ($this->facetFieldTransformationRegistry->hasTransformationForCode($filterCode)) {
            $transformation = $this->facetFieldTransformationRegistry->getTransformationByCode($filterCode);

            return array_map(function (string $filterValue) use ($transformation, $filterCode) {
                $transformedValue = $transformation->decode($filterValue);

                if ($transformedValue instanceof FacetFilterRange) {
                    return $this->getElasticsearchQueryRangesFromFacetFilterRange(
                        $filterCode,
                        $transformedValue
                    );
                }

                return (new ElasticsearchQueryOperatorEqual())->getFormattedArray($filterCode, $transformedValue);
            }, $filterValues);
        }

        return array_map(function ($filterValue) use ($filterCode) {
            return (new ElasticsearchQueryOperatorEqual())->getFormattedArray($filterCode, $filterValue);
        }, $filterValues);
    }

    /**
     * @param string $rangeField
     * @param FacetFilterRange $facetFilterRange
     * @return mixed[]
     */
    private function getElasticsearchQueryRangesFromFacetFilterRange(
        string $rangeField,
        FacetFilterRange $facetFilterRange
    ) : array {
        $from = $facetFilterRange->from();
        $to = $facetFilterRange->to();

        if ($from !== null && null === $to) {
            return (new ElasticsearchQueryOperatorGreaterOrEqualThan())->getFormattedArray($rangeField, (string)$from);
        }

        if (null === $from && $to !== null) {
            return (new ElasticsearchQueryOperatorLessOrEqualThan())->getFormattedArray($rangeField, (string)$to);
        }

        if ($from !== null && $to !== null) {
            return $this->getBoolFilterArrayRepresentation([
                (new ElasticsearchQueryOperatorGreaterOrEqualThan())->getFormattedArray($rangeField, (string)$from),
                (new ElasticsearchQueryOperatorLessOrEqualThan())->getFormattedArray($rangeField, (string)$to)
            ]);
        }

        return (new ElasticsearchQueryOperatorAnything())->getFormattedArray();
    }

    /**
     * @param array[] $contents
     * @return array[]
     */
    private function getBoolFilterArrayRepresentation(array $contents) : array
    {
        return (new ElasticsearchQueryBoolFilter())->getFormattedArray($contents);
    }

    /**
     * @param array[] $contents
     * @return array[]
     */
    private function getBoolShouldArrayRepresentation(array $contents) : array
    {
        return (new ElasticsearchQueryBoolShould())->getFormattedArray($contents);
    }

    /**
     * @param array[] $contents
     * @return array[]
     */
    private function getBoolMustArrayRepresentation(array $contents) : array
    {
        return (new ElasticsearchQueryBoolMust())->getFormattedArray($contents);
    }
}
