<?php

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool;

class ElasticsearchQueryBoolMust implements ElasticsearchQueryBool
{
    public function getFormattedArray(array $contents) : array
    {
        return [
            'bool' => [
                'must' => $contents
            ]
        ];
    }
}
