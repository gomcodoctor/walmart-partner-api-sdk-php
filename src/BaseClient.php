<?php
namespace Walmart;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use phpseclib\Crypt\Random;
use Psr\Http\Message\RequestInterface;
use Walmart\middleware\AuthSubscriber;
use Walmart\middleware\MockSubscriber;
use Walmart\middleware\XmlNamespaceSubscriber;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Command\Guzzle\GuzzleClient;
use GuzzleHttp\Command\Guzzle\Description;
use Walmart\Auth\Signature;
use Walmart\Order;
use GuzzleHttp\Client;
use Walmart\Utils;
use GuzzleHttp\Command\Result;

/**
 * Partial Walmart API client implemented with Guzzle.
 * BaseClient class to implement common features
 */
class BaseClient extends GuzzleClient
{
    const ENV_PROD = 'prod';
    const ENV_STAGE = 'stage';
    const ENV_MOCK = 'mock';

    const BASE_URL_PROD = 'https://marketplace.walmartapis.com';
    const BASE_URL_STAGE = 'https://marketplace.stg.walmartapis.com/gmp-gateway-service-app';

    public $env;

    protected $consumerId;

    protected $privateKey;

    /**
     * @param array $config
     * @param string $env
     * @throws \Exception
     */
    public function __construct($consumerId, $privateKey, array $config = [], $env = self::ENV_PROD)
    {

        $this->consumerId = $consumerId;
        $this->privateKey = $privateKey;
        /*
         * Make sure ENV is valid
         */
        if ( ! in_array($env, [self::ENV_PROD, self::ENV_STAGE, self::ENV_MOCK])) {
            throw new \Exception('Invalid environment', 1462566788);
        }

        /*
         * Check that consumerId and privateKey are set
         */
        if ( ! $this->consumerId || ! $this->privateKey) {
            throw new \Exception('Configuration missing consumerId or privateKey', 1466965269);
        }

        // Set ENV
        $this->env = $env;

        // Apply some defaults.
        $config = array_merge_recursive($config, [
            'max_retries' => 3,
        ]);

        // If an override base url is not provided, determine proper baseurl from env
        if ( ! isset($config['description_override']['baseUrl'])) {
            $config = array_merge_recursive($config , [
                'description_override' => [
                    'baseUrl' => $this->getEnvBaseUrl($env),
                ],
            ]);
        }

        // Create the client.
        parent::__construct(
            $this->getHttpClientFromConfig($config),
            $this->getDescriptionFromConfig($config),
            null, null, null, ['process' => false]
        );

        // Ensure that ApiVersion is set.
        $this->setConfig(
            'defaults/ApiVersion',
            $this->getDescription()->getApiVersion()
        );
    }

    /**
     * Get baseUrl for given environment
     * @param string $env
     * @return null|string
     */
    public function getEnvBaseUrl($env)
    {
        switch ($env) {
            case self::ENV_PROD:
                return self::BASE_URL_PROD;
            case self::ENV_STAGE:
                return self::BASE_URL_STAGE;
            case self::ENV_MOCK:
                return null;
        }
    }

    private function getHttpClientFromConfig(array $config)
    {
        // If a client was provided, return it.
        if (isset($config['http_client'])) {
            return $config['http_client'];
        }

        // Create a Guzzle HttpClient.
        $clientOptions = isset($config['http_client_options'])
            ? $config['http_client_options']
            : [];

        $handler = HandlerStack::create();
        $handler->push(Middleware::mapRequest(function (RequestInterface $request) {
            $requestUrl = $request->getUri();
            $requestMethod = $request->getMethod();
            $timestamp = Utils::getMilliseconds();
            $signature = Signature::calculateSignature($this->consumerId,
                $this->privateKey, $requestUrl, $requestMethod, $timestamp);

            /*
             * Add required headers to request
             */
            $request = $request->withHeader('WM_SVC.NAME', 'Walmart Marketplace');
            $request = $request->withHeader('WM_QOS.CORRELATION_ID',  base64_encode(Random::string(16)));
            $request = $request->withHeader('WM_SEC.TIMESTAMP',  $timestamp);
            $request = $request->withHeader('WM_SEC.AUTH_SIGNATURE',  $signature);
            $request = $request->withHeader('WM_CONSUMER.CHANNEL.TYPE',  '0f3e4dd4-0514-4346-b39d-af0e00ea066d');
            $request = $request->withHeader('Accept',  'application/xml');
            return $request->withHeader('WM_CONSUMER.ID',  $this->consumerId);
        }));

        $client = new Client(['handler' => $handler, 'http_errors' => true]);
        return $client;
    }

    private function getDescriptionFromConfig(array $config)
    {
        // If a description was provided, return it.
        if (isset($config['description'])) {
            return $config['description'];
        }

        // Load service description data.
        $data = is_readable($config['description_path'])
            ? include $config['description_path']
            : [];

        if ( ! is_array($data)) {
            throw new \Exception('Service description file must return an array', 1470529124);
        }

        // Override description from local config if set
        if(isset($config['description_override'])){
            $data = array_merge($data, $config['description_override']);
        }
        return new Description($data);
    }

}