<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchSearchEngine;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorFullText
 */
class ElasticsearchQueryOperatorFullTextTest extends AbstractElasticsearchQueryOperatorTest
{
    final protected function getOperatorInstance() : ElasticsearchQueryOperator
    {
        return new ElasticsearchQueryOperatorFullText();
    }

    final protected function getExpectedExpression(string $fieldName, string $fieldValue) : array
    {
        return [
            'bool' => [
                'should' => [
                    'match' => [
                        ElasticsearchSearchEngine::FULL_TEXT_SEARCH_FIELD_NAME => $fieldValue
                    ]
                ]
            ]
        ];
    }
}
