<?php

namespace Marcz\Swiftype;

use Injector;
use QueuedJobService;
use Marcz\Swiftype\Jobs\JsonBulkExport;
use Marcz\Swiftype\Jobs\CrawlBulkExport;
use Marcz\Swiftype\Jobs\JsonExport;
use Marcz\Swiftype\Jobs\CrawlExport;
use Marcz\Swiftype\Jobs\DeleteRecord;
use Marcz\Swiftype\Jobs\CrawlDeleteRecord;
use DataList;
use ArrayList;
use Marcz\Search\Config as SearchConfig;
use Marcz\Search\Client\SearchClientAdaptor;
use GuzzleHttp\Ring\Client\CurlHandler;
use GuzzleHttp\Stream\Stream;
use Marcz\Search\Client\DataWriter;
use Marcz\Search\Client\DataSearcher;
use Object;
use Versioned;
use SiteConfig;

/**
 * Class SwiftypeClient
 *
 * @package Marcz\Swiftype
 */
class SwiftypeClient extends Object implements SearchClientAdaptor, DataWriter, DataSearcher
{

    protected $authToken;
    protected $clientIndexName;
    protected $clientAPI;
    protected $response;
    protected $rawQuery;

    private static $batch_length = 100;

    private $swiftypeEndPoint = 'http://api.swiftype.com/api/v1/';

    /**
     * Create and/or return a CurlHandler client
     *
     * @return mixed
     */
    public function createClient()
    {
        if ($this->clientAPI) {
            return $this->clientAPI;
        }

        return $this->setClientAPI(new CurlHandler());
    }

    /**
     * Sets the client API handler
     *
     * @param $handler
     *
     * @return mixed
     */
    public function setClientAPI(CurlHandler $handler)
    {
        $this->clientAPI = $handler;

        return $this->clientAPI;
    }

    /**
     * Get the config details of the requested index
     *
     * Gets the default details for the index name as set in config.yml
     * If FAQEngineName has been set in SiteConfig, this will override the name field in $indexConfig
     *
     * @param string $indexName name of the index that the configuration details have been requested for
     *
     * @return mixed
     */
    public function getIndexConfig($indexName)
    {
        $indexConfig = ArrayList::create(
            SearchConfig::config()->get('indices')
        )->find('name', $indexName);
        $config = SiteConfig::current_site_config();
        $engine = null;
        if ($config->hasField('FAQEngineName')) {
            $engine = $config->getField('FAQEngineName');
        }
        if ($engine) {
            $indexConfig['name'] = $engine;
        }
        return $indexConfig;
    }

    /**
     * Initialise index details that will be used in API requests
     *
     * @param string $indexName name of the index to be initialized
     *
     * @return array
     */
    public function initIndex($indexName)
    {
        $this->createClient();

        $this->clientIndexName = $indexName;


        $this->rawQuery = [
            'scheme'      => 'https',
            'http_method' => 'GET',
            'uri'         => parse_url($this->swiftypeEndPoint, PHP_URL_PATH),
            'headers'     => [
                'host'         => [parse_url($this->swiftypeEndPoint, PHP_URL_HOST)],
                'Content-Type' => ['application/json'],
            ],
            'client'      => [
                'curl' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false
                ]
            ]
        ];

        return $this->rawQuery;
    }

    /**
     * Initialise engine and retrieve ID of FAQ document type
     *
     * @param string $indexName
     *
     * @return string ID of FAQ document type
     */
    public function createIndex($indexName)
    {
        $indexConfig = $this->getIndexConfig($indexName);
        $engine = $this->getEngine($indexConfig['name']);
        if (!$engine) {
            $engine = $this->createEngine($indexConfig['name']);
        }


        $documentTypes = $this->getDocumentTypes($engine['id']);
        $documentTypeId = null;

        if ($documentTypes) {
            echo 'Searching documentTypes for ' . strtolower($indexConfig['class']) . '<br>';
            foreach ($documentTypes as $type) {
                echo 'Document Type: ' . $type['name'];
                if ($type['name'] === strtolower($indexConfig['class'])) {
                    echo ' - Expected Document Type found <br>';
                    $documentTypeId = $type['id'];
                    break;
                }
                echo '<br>';
            }
        }
        if($documentTypeId) {
            echo    'Existing document type "' . $indexConfig['class'] . '" already exists in "' . $indexConfig['name'] . '" engine.<br>' .
                'Deleting existing document type and all existing records from Swiftype.<br>';
            $this->deleteDocumentType($indexConfig, $engine['id'], $documentTypeId);
            /*
             * Why is there a sleep() here?
             * deleteDocumentType() makes a CURL request to Swiftype to delete the FAQ documentType.
             * Our next step is to create a new Document Type with the same name.
             * Without this wait the createDocumentType() CURL request may fire before Swiftype has completed the deletion process.
             * Because of this, the Create request will return an error stating that a Document Type of the requested name already exists.
             */
            sleep(4);
        }
        echo 'Creating new document type "' . $indexConfig['class'] . '"<br>';
        return $this->createDocumentType($indexConfig['class'], $indexConfig['name'], $engine['id']);
    }

    /**
     * Execute API call to retrieve details of the Swiftype Engine that will be used
     *
     * @param string $indexName name of engine to be returned
     *
     * @return mixed
     */
    public function getEngine($indexName)
    {
        $url = sprintf(
            '%sengines.json',
            parse_url($this->swiftypeEndPoint, PHP_URL_PATH)
        );

        $data = ['auth_token' => $this->getSwiftypeAPIKey()];

        $rawQuery = $this->initIndex($indexName);
        $rawQuery['uri'] = $url;
        $rawQuery['body'] = json_encode($data);

        $this->rawQuery = $rawQuery;

        $handler = $this->clientAPI;
        $response = $handler($rawQuery);
        $stream = Stream::factory($response['body']);
        sleep(2);
        $response['body'] = $stream->getContents();
        $body = new ArrayList(json_decode($response['body'], true));

        return $body->find('name', $indexName);
    }

    /**
     * Execute API call to retrieve the document types associated with the provided engine
     *
     * @param string $engineId ID of swiftype engine
     *
     * @return mixed
     */
    public function getDocumentTypes($engineId)
    {
        $url = sprintf(
            '%1$sengines/%2$s/document_types.json',
            parse_url($this->swiftypeEndPoint, PHP_URL_PATH),
            $engineId
        );

        $data = ['auth_token' => $this->getSwiftypeAPIKey()];

        $rawQuery = $this->initIndex($engineId);
        $rawQuery['uri'] = $url;
        $rawQuery['body'] = json_encode($data);

        $this->rawQuery = $rawQuery;
        $handler = $this->clientAPI;
        $response = $handler($rawQuery);
        $stream = Stream::factory($response['body']);
        sleep(2);
        $response['body'] = $stream->getContents();

        return json_decode($response['body'], true);
    }

    /**
     * Execute API call to create a new Swiftype Engine
     *
     * @param string $engineName name of new engine that will be created
     *
     * @return array|null
     */
    public function createEngine($engineName)
    {
        $rawQuery = $this->initIndex($engineName);
        $url = sprintf(
            '%sengines.json',
            parse_url($this->swiftypeEndPoint, PHP_URL_PATH)
        );
        $data = [
            'auth_token' => $this->getSwiftypeAPIKey(),
            'engine'     => ['name' => $engineName],
        ];

        $rawQuery['http_method'] = 'POST';
        $rawQuery['uri'] = $url;
        $rawQuery['body'] = json_encode($data);

        $this->rawQuery = $rawQuery;

        $handler = $this->clientAPI;
        $response = $handler($rawQuery);

        if (isset($response['status']) && 200 === $response['status']) {
            $stream = Stream::factory($response['body']);
            $response['body'] = $stream->getContents();
            return json_decode($response['body'], true);
        }

        return null;
    }

    /**
     * Execute API call to generate a new document type within the provided engine
     *
     * @param $typeName name of the document type that will be created
     * @param $indexName name of the index that will be used generating API call
     * @param $engineId ID of engine where new document type will be created
     *
     * @return bool
     */
    public function createDocumentType($typeName, $indexName, $engineId)
    {
        $rawQuery = $this->initIndex($indexName);
        $url = sprintf(
            '%1$sengines/%2$s/document_types.json',
            parse_url($this->swiftypeEndPoint, PHP_URL_PATH),
            $engineId
        );
        $data = [
            'auth_token'    => $this->getSwiftypeAPIKey(),
            'document_type' => ['name' => strtolower($typeName)],
        ];

        $rawQuery['http_method'] = 'POST';
        $rawQuery['uri'] = $url;
        $rawQuery['body'] = json_encode($data);

        $this->rawQuery = $rawQuery;

        $handler = $this->clientAPI;
        $response = $handler($rawQuery);
        $stream = Stream::factory($response['body']);
        $response['body'] = $stream->getContents();

        return isset($response['status']) && 200 === $response['status'];
    }

    /**
     * Execute API call to the existing FAQ document type within the provided engine
     *
     * @param $indexConfig Configuration details of FAQ Index
     * @param $engineId ID of engine where new document type will be created
     * @param $documentTypeId ID of the document type to be deleted
     *
     * @return bool
     */
    public function deleteDocumentType($indexConfig, $engineId, $documentTypeId)
    {
        $rawQuery = $this->initIndex($indexConfig['name']);
        $url = sprintf(
            '%1$sengines/%2$s/document_types/%3$s.json',
            parse_url($this->swiftypeEndPoint, PHP_URL_PATH),
            $engineId,
            $documentTypeId
        );
        $data = [
            'auth_token'    => $this->getSwiftypeAPIKey(),
        ];

        $rawQuery['http_method'] = 'DELETE';
        $rawQuery['uri'] = $url;
        $rawQuery['body'] = json_encode($data);

        $this->rawQuery = $rawQuery;

        $handler = $this->clientAPI;
        $response = $handler($rawQuery);
        $stream = Stream::factory($response['body']);
        $response['body'] = $stream->getContents();
        return isset($response['status']) && in_array($response['status'],[200,204]);
    }

    /**
     * Execute API call to create/update a single record in the index
     *
     * @param array $data record details that will be sent to API
     * @return bool
     */
    public function update($data)
    {
        /*
         * Swiftype wont partial search on enum fields.
         * Changing the type to text here before submitting resolves this issue.
         */
        foreach($data['fields'] as $fieldKey => $field) {
            if (in_array($field['type'], ['enum', 'text'])) {
                $data['fields'][$fieldKey]['type'] = 'string';
            }
        }
        $indexConfig = $this->getIndexConfig($this->clientIndexName);
        $engine = $this->getEngine($indexConfig['name']);
        $documentTypes = $this->getDocumentTypes($engine['id']);
        $documentTypeId = null;
        foreach ($documentTypes as $type) {
            if ($type['name'] === strtolower($indexConfig['class'])) {
                $documentTypeId = $type['id'];

            }
        }

        $rawQuery = $this->initIndex($indexConfig['name']);

        $url = sprintf(
            '%1$sengines/%2$s/document_types/%3$s/documents/create_or_update.json',
            parse_url($this->swiftypeEndPoint, PHP_URL_PATH),
            $engine['id'],
            $documentTypeId
        );
        $data = [
            'auth_token' => $this->getSwiftypeAPIKey(),
            'document'   => $data,
        ];

        $rawQuery['http_method'] = 'POST';
        $rawQuery['uri'] = $url;
        $rawQuery['body'] = json_encode($data);

        $this->rawQuery = $rawQuery;

        $handler = $this->clientAPI;
        $response = $handler($rawQuery);
        $stream = Stream::factory($response['body']);
        sleep(2);
        $response['body'] = $stream->getContents();

        return isset($response['status']) && 200 === $response['status'];
    }

    /**
     * Execute API call to update / create all records
     *
     * @param array $list array containing arrays of all records that will be updated
     * @return bool
     */
    public function bulkUpdate($list)
    {
        /*
         * Swiftype doesn't partial search on enum fields.
         * Some functionality including filtering does not work well with text fields.
         * Changing the type to string here before submitting resolves these issues.
         */
        foreach ($list as $listKey => $record) {
            foreach ($record['fields'] as $fieldKey => $field) {
                if (in_array($field['type'], ['enum', 'text'])) {
                    $list[$listKey]['fields'][$fieldKey]['type'] = 'string';
                }
            }
        }
        $indexConfig = $this->getIndexConfig($this->clientIndexName);

        $engine = $this->getEngine($indexConfig['name']);
        $documentTypes = $this->getDocumentTypes($engine['id']);
        $documentTypeId = null;
        foreach ($documentTypes as $type) {
            if ($type['name'] === strtolower($indexConfig['class'])) {
                $documentTypeId = $type['id'];
            }
        }

        $rawQuery = $this->initIndex($indexConfig['name']);

        $url = sprintf(
            '%1$sengines/%2$s/document_types/%3$s/documents/bulk_create_or_update_verbose',
            parse_url($this->swiftypeEndPoint, PHP_URL_PATH),
            $engine['id'],
            $documentTypeId
        );
        $data = [
            'auth_token' => $this->getSwiftypeAPIKey(),
            'documents'  => $list,
        ];

        $rawQuery['http_method'] = 'POST';
        $rawQuery['uri'] = $url;
        $rawQuery['body'] = json_encode($data);

        $this->rawQuery = $rawQuery;

        $handler = $this->clientAPI;
        $response = $handler($rawQuery);
        $stream = Stream::factory($response['body']);
        $response['body'] = $stream->getContents();

        return isset($response['status']) && 200 === $response['status'];
    }

    /**
     * Execute API call to delete the requested record from the search index
     *
     * @param int $recordID ID of record to be deleted
     * @return bool
     */
    public function deleteRecord($recordID)
    {
        $indexConfig = $this->getIndexConfig($this->clientIndexName);
        $engine = $this->getEngine($indexConfig['name']);
        $rawQuery = $this->initIndex($indexConfig['name']);
        $documentTypes = $this->getDocumentTypes($engine['id']);
        $documentTypeId = null;
        foreach ($documentTypes as $type) {
            if ($type['name'] === strtolower($indexConfig['class'])) {
                $documentTypeId = $type['id'];
            }
        }

        $url = sprintf(
            '%1$sengines/%2$s/document_types/%3$s/documents/%4$s.json',
            parse_url($this->swiftypeEndPoint, PHP_URL_PATH),
            $engine['id'],
            $documentTypeId,
            $recordID
        );
        $data = ['auth_token' => $this->getSwiftypeAPIKey()];

        $rawQuery['http_method'] = 'DELETE';
        $rawQuery['uri'] = $url;
        $rawQuery['body'] = json_encode($data);

        $this->rawQuery = $rawQuery;

        $handler = $this->clientAPI;
        $response = $handler($rawQuery);
        $stream = Stream::factory($response['body']);
        sleep(2);
        $response['body'] = $stream->getContents();

        return isset($response['status']) && in_array($response['status'], [204, 404]);
    }

    /**
     * Create a queued job that will execute a bulk export API call
     *
     * @param string $indexName name of the swiftype index that the records will be exported to
     * @param string $className name of the Class for which all records will be exported
     */
    public function createBulkExportJob($indexName, $className)
    {
        $indexConfig = $this->getIndexConfig($indexName);
        $exportClass = (empty($indexConfig['crawlBased'])) ? JsonBulkExport::class : CrawlBulkExport::class;

        $list = new DataList($className);
        $total = $list->count();
        $batchLength = self::config()->get('batch_length') ?: SearchConfig::config()->get('batch_length');
        $totalPages = ceil($total / $batchLength);

        $this->initIndex($indexConfig['name']);

        for ($offset = 0; $offset < $totalPages; $offset++) {
            $job = Injector::inst()->createWithArgs(
                $exportClass,
                [$indexName, $className, $offset * $batchLength]
            );

            singleton(QueuedJobService::class)->queueJob($job);
        }
    }

    /**
     * Create a queued job to execute a single record update
     *
     * @param string $indexName name of the swiftype index that the record will be exported to
     * @param string $className name of the Class for which the record will be exported from
     * @param int $recordId ID of record to be updated
     * @return null|void
     */
    public function createExportJob($indexName, $className, $recordId)
    {
        $indexConfig = $this->getIndexConfig($indexName);
        $exportClass = (empty($indexConfig['crawlBased'])) ? JsonExport::class : CrawlExport::class;

        $list = new DataList($className);
        $record = $list->byID($recordId);

        if (!$record) {
            return;
        }

        if ($record->hasExtension(Versioned::class)) {
            $record = Versioned::get_by_stage(
                $className,
                'Live'
            )->byID($recordId);
            if (!$record) {
                return null;
            }
        }

        $job = Injector::inst()->createWithArgs(
            $exportClass,
            [$indexName, $className, $recordId]
        );

        singleton(QueuedJobService::class)->queueJob($job);
    }

    /**
     * Create a queued job to remove a record from the swiftype search index
     * @param string $indexName name of the swiftype index that the record will be removed from
     * @param string $className name of the deleted records Class
     * @param int $recordId ID of record to be updated
     */
    public function createDeleteJob($indexName, $className, $recordId)
    {
        $indexConfig = $this->getIndexConfig($this->indexName);
        $exportClass = (empty($indexConfig['crawlBased'])) ? DeleteRecord::class : CrawlDeleteRecord::class;

        $job = Injector::inst()->createWithArgs(
            $exportClass,
            [$indexName, $className, $recordId]
        );

        singleton(QueuedJobService::class)->queueJob($job);
    }

    /**
     * Return the response variable
     *
     * @return mixed
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Get the API key from SiteConfig
     *
     * @return string|null
     */
    public function getSwiftypeAPIKey()
    {
        $config = SiteConfig::current_site_config();
        $ApiKey = null;
        if ($config->hasField('FAQAPIKey')) {
            $ApiKey = $config->getField('FAQAPIKey');
        }

        return $ApiKey;
    }

    /**
     * Return the rawQuery variable
     *
     * @return mixed
     */
    public function sql()
    {
        return $this->rawQuery;
    }

    public function callIndexMethod($methodName, $parameters = [])
    {
        return call_user_func_array([$this->clientIndexName, $methodName], $parameters);
    }
    public function callClientMethod($methodName, $parameters = [])
    {
        return call_user_func_array([$this->clientAPI, $methodName], $parameters);
    }

    public function search($term = '', $filters = [], $pageNumber = 0, $pageLength = 20) {}
}
