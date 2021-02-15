<?php

namespace Marcz\Swiftype\Tests;

use SapphireTest;
use Marcz\Swiftype\SwiftypeClient;
use GuzzleHttp\Ring\Client\CurlHandler;
use GuzzleHttp\Ring\Client\MockHandler;
use GuzzleHttp\Stream\Stream;
use Director;
use SiteConfig;

class SwiftypeClientTest extends SapphireTest
{

    public function setUp()
    {
        parent::setUp();
        $config = SiteConfig::current_site_config();
        $config->setField('FAQEngineName', 'FAQEngineName');
        $config->setField('FAQAPIKey', 'FAQAPIKey');
    }

    protected function fetchMockedResponse($data = [], $status = 200)
    {
        return new MockHandler(
            [
                'status' => $status,
                'body'   => Stream::factory(new FakeStreamArray($data))
            ]
        );
    }

    public function testCreateClient()
    {
        $client = new SwiftypeClient;
        $curlClient = $client->createClient();

        $this->assertInstanceOf(CurlHandler::class, $client->createClient());
        $this->assertEquals($curlClient, $client->createClient());
    }

    public function testInitIndex()
    {
        $client = new SwiftypeClient;
        $rawQuery = $client->initIndex('index_name');
        $expected = [
            'http_method' => 'GET',
            'uri'         => '/api/v1/',
            'headers'     => [
                'host'         => ['api.swiftype.com'],
                'Content-Type' => ['application/json'],
            ],
            'client'      => [
                'curl' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false
                ]
            ]
        ];

        $this->assertEquals($expected, $rawQuery);
    }

    public function testCreateIndex()
    {
        $client = new SwiftypeClient;
        $clientAPI = $this->fetchMockedResponse([['name' => 'FAQ', 'class' => 'FAQ']]);
        $client->setClientAPI($clientAPI);

        $this->assertTrue($client->createIndex('FAQ'));
    }

    public function testGetEngine()
    {
        $client = new SwiftypeClient;
        $data = [
            'auth_token' => 'FAQAPIKey',
            'engine'     => [
                'name' => 'FAQ'
            ]
        ];
        $expected = [
            'http_method' => 'POST',
            'uri'         => '/api/v1/engines.json',
            'headers'     => [
                'host'         => ['api.swiftype.com'],
                'Content-Type' => ['application/json'],
            ],
            'client'      => [
                'curl' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false
                ]
            ],
            'body'        => json_encode($data, JSON_PRESERVE_ZERO_FRACTION)
        ];
        $expectedEngineResponse = [['name' => 'FAQ']];
        $client->setClientAPI($this->fetchMockedResponse([['name' => 'FAQ']]));
        $engine = $client->createEngine('FAQ');
        $this->assertEquals($expectedEngineResponse, $engine);
        $this->assertEquals($expected, $client->sql());
    }

    public function testGetDocumentTypes()
    {
        $client = new SwiftypeClient;
        $data = ['auth_token' => 'FAQAPIKey'];
        $expected = [
            'http_method' => 'GET',
            'uri'         => '/api/v1/engines/FAQ/document_types.json',
            'headers'     => [
                'host'         => ['api.swiftype.com'],
                'Content-Type' => ['application/json'],
            ],
            'client'      => [
                'curl' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false
                ]
            ],
            'body'        => json_encode($data, JSON_PRESERVE_ZERO_FRACTION)
        ];

        $client->setClientAPI($this->fetchMockedResponse([['name' => 'FAQ']]));

        $this->assertEquals(
            [['name' => 'FAQ']],
            $client->getDocumentTypes('FAQ')
        );
        $this->assertEquals($expected, $client->sql());
    }

    public function testCreateEngine()
    {
        $client = new SwiftypeClient;
        $data = [
            'auth_token' => 'FAQAPIKey',
            'engine'     => ['name' => 'FAQ']
        ];
        $expected = [
            'http_method' => 'POST',
            'uri'         => '/api/v1/engines.json',
            'headers'     => [
                'host'         => ['api.swiftype.com'],
                'Content-Type' => ['application/json'],
            ],
            'client'      => [
                'curl' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false
                ]
            ],
            'body'        => json_encode($data, JSON_PRESERVE_ZERO_FRACTION)
        ];
        $expectedEngineResponse = [['name' => 'FAQ']];
        $client->setClientAPI($this->fetchMockedResponse([['name' => 'FAQ']]));
        $engine = $client->createEngine('FAQ');
        $this->assertEquals($expectedEngineResponse, $engine);
        $this->assertEquals($expected, $client->sql());
    }

    public function testCreateDocumentType()
    {
        $client = new SwiftypeClient;
        $data = [
            'auth_token'    => 'FAQAPIKey',
            'document_type' => ['name' => 'faq']
        ];
        $expected = [
            'http_method' => 'POST',
            'uri'         => '/api/v1/engines/{engineId}/document_types.json',
            'headers'     => [
                'host'         => ['api.swiftype.com'],
                'Content-Type' => ['application/json'],
            ],
            'client'      => [
                'curl' => [
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false
                ]
            ],
            'body'        => json_encode($data, JSON_PRESERVE_ZERO_FRACTION)
        ];

        $client->setClientAPI($this->fetchMockedResponse([['name' => 'FAQ']]));

        $this->assertTrue($client->createDocumentType('FAQ', 'FAQ', '{engineId}'));
        $this->assertEquals($expected, $client->sql());
    }

    public function testGetIndexConfig()
    {
        $client = new SwiftypeClient;
        $expected = [
            'name'                  => 'FAQEngineName',
            'class'                 => 'FAQ',
            'has_one'               => true,
            'has_many'              => true,
            'many_many'             => true,
            'searchableAttributes'  => [
                'Question',
                'Answer',
                'Keywords',
                'Category'
            ],
            'attributesForFaceting' => [
                'Keywords',
                'Category'
            ],
        ];
        $indexConfig = $client->getIndexConfig('FAQ');
        $this->assertEquals($expected, $indexConfig);
    }

    public function getEnv($name)
    {
        if (Director::isDev() && Director::is_cli() && $name == 'SS_SWIFTYPE_AUTH_TOKEN') {
            return 'SS_SWIFTYPE_AUTH_TOKEN';
        }
        // return Environment::getEnv($name);
        return constant($name);
    }
}
