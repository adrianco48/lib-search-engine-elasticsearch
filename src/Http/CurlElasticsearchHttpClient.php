<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\Exception\ElasticsearchConnectionException;

class CurlElasticsearchHttpClient implements ElasticsearchHttpClient
{
    /**
     * @var string
     */
    private $elasticsearchConnectionPath;

    public function __construct(string $elasticsearchConnectionPath)
    {
        $this->elasticsearchConnectionPath = $elasticsearchConnectionPath;
    }

    /**
     * @param string $id
     * @param mixed[] $parameters
     * @return mixed
     */
    public function update(string $id, array $parameters)
    {
        $url = sprintf('%s/%s', $this->constructUrl(ElasticsearchHttpClient::UPDATE_SERVLET), urlencode($id));

        $curlHandle = $this->createCurlHandle($url);
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($parameters));

        return $this->executeCurlRequest($curlHandle);
    }

    /**
     * @param mixed[] $parameters
     * @return mixed
     */
    public function select(array $parameters)
    {
        $url = $this->constructUrl(ElasticsearchHttpClient::SEARCH_SERVLET);

        $curlHandle = $this->createCurlHandle($url);
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($parameters));

        return $this->executeCurlRequest($curlHandle);
    }

    /**
     * @param mixed[] $parameters
     * @return mixed
     */
    public function clear(array $parameters)
    {
        $url = $this->constructUrl(ElasticsearchHttpClient::CLEAR_SERVERLET);

        $curlHandle = $this->createCurlHandle($url);
        curl_setopt($curlHandle, CURLOPT_POST, true);
        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($parameters));

        return $this->executeCurlRequest($curlHandle);
    }

    /**
     * @param string $servlet
     * @return string
     */
    private function constructUrl(string $servlet) : string
    {
        if ("" === $servlet) {
            return $this->elasticsearchConnectionPath;
        } else {
            return sprintf('%s/%s', $this->elasticsearchConnectionPath, $servlet);
        }
    }

    /**
     * @param string $url
     * @return resource
     */
    private function createCurlHandle(string $url)
    {
        $curlHandle = curl_init($url);

        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ['Content-type: application/json']);

        return $curlHandle;
    }

    /**
     * @param resource $curlHandle
     * @return mixed
     */
    private function executeCurlRequest($curlHandle)
    {
        $responseJson = curl_exec($curlHandle);

        if (curl_errno($curlHandle) !== 0) {
            throw new \RuntimeException(curl_strerror(curl_errno($curlHandle)));
        }

        $response = json_decode($responseJson, true);
        $this->validateResponseType($responseJson);

        return $response;
    }

    private function validateResponseType(string $rawResponse)
    {
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = preg_replace('/.*<title>|<\/title>.*/ism', '', $rawResponse);
            throw new ElasticsearchConnectionException($errorMessage);
        }
    }
}
