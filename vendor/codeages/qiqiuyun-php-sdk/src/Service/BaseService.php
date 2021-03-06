<?php

namespace QiQiuYun\SDK\Service;

use QiQiuYun\SDK\Auth;
use QiQiuYun\SDK\HttpClient\Client;
use Psr\Log\LoggerInterface;
use QiQiuYun\SDK\HttpClient\ClientInterface;
use QiQiuYun\SDK\Exception\SDKException;
use QiQiuYun\SDK\HttpClient\Response;
use QiQiuYun\SDK\Exception\ResponseException;
use QiQiuYun\SDK;

abstract class BaseService
{
    /**
     * QiQiuYun auth
     *
     * @var Auth
     */
    protected $auth;

    /**
     * Service options
     *
     * @var array
     */
    protected $options;

    /**
     * Http client
     *
     * @var Client
     */
    private $client;

    /**
     * API host
     *
     * @var string
     */
    protected $host = '';

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    protected $logger;

    private static $resolversByClass = array();

    public function __construct(Auth $auth, array $options = array(), LoggerInterface $logger = null, ClientInterface $client = null)
    {
        $this->auth = $auth;
        $this->logger = $logger;
        $this->client = $client;

        $this->options = $this->filterOptions($options);

        if (!empty($this->options['host'])) {
            $this->host = $options['host'];
        }
    }

    protected function createClient()
    {
        if ($this->client) {
            return $this->client;
        }

        $this->client = new Client(array(), $this->logger);

        return $this->client;
    }

    /**
     * 气球云 API V2 的统一请求方法
     *
     * @param string $method
     * @param string $uri
     * @param array  $data
     * @param array  $headers
     *
     * @return array
     */
    protected function request($method, $uri, array $data = array(), array $headers = array())
    {
        $options = array();

        if (!empty($data)) {
            if ('GET' === strtoupper($method) && !empty($data)) {
                $uri = $uri.(strpos($uri, '?') > 0 ? '&' : '?').http_build_query($data);
            } else {
                if (version_compare(phpversion(), '5.4.0', '>=')) {
                    $options['body'] = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } else {
                    $options['body'] = json_encode($data);
                }
            }
        }

        if (!isset($headers['Authorization'])) {
            $headers['Authorization'] = $this->auth->makeRequestAuthorization($uri, $options['body']);
        }
        $headers['Content-Type'] = 'application/json';
        $options['headers'] = $headers;

        $response = $this->createClient()->request($method, $this->getRequestUri($uri), $options);

        return $this->extractResultFromResponse($response);
    }

    /**
     * 从Response中抽取API返回结果
     *
     * @param Response $response
     */
    protected function extractResultFromResponse(Response $response)
    {
        try {
            $result = SDK\json_decode($response->getBody(), true);
        } catch (\Exception $e) {
            throw new SDKException($e->getMessage(). "(response: {$response->getBody()}");
        }
        
        $responseCode = $response->getHttpResponseCode();

        if ($responseCode < 200 || $responseCode > 299 || isset($result['error'])) {
            $this->logger && $this->logger->error((string) $response);
            throw new ResponseException($response);
        }

        return $result;
    }

    /**
     * 获得完整的请求地址
     *
     * @param string $uri
     *
     * @return string 请求地址
     */
    protected function getRequestUri($uri, $protocol = 'http')
    {
        if (!in_array($protocol, array('http', 'https', 'auto'))) {
            throw new SDKException("The protocol parameter must be in 'http', 'https', 'auto', your value is '{$protocol}'.");
        }

        if (is_array($this->host)) {
            shuffle($this->host);
            reset($this->host);
            $host = current($this->host);
        } else {
            $host = $this->host;
        }
        $host = (string) $host;

        if (!$host) {
            throw new SDKException('API host is not exist or invalid.');
        }

        $uri = ('/' !== substr($uri, 0, 1) ? '/' : '').$uri;

        return ('auto' == $protocol ? '//' : $protocol.'://').$host.$uri;
    }

    protected function filterOptions(array $options = array())
    {
        return array_replace(array(
            'host' => '',
        ), $options);
    }
}
