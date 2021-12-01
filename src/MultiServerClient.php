<?php
/*
 * This file is part of leadingfellows/multiserver_guzzle
 */
namespace leadingfellows\multiserver_guzzle;

/**
 * Implementation of class MultiServerClient.
 *
 * PHP version 7
 *
 * Contains declaration and implementation of class MultiServerClient
 * which is a PSR-7 multi-server guzzle client.
 */

use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\RequestOptions;

/**
 * Class MultiServerClient
 *
 */
class MultiServerClient
{
    /** @var array<string,mixed> $servers  */
    protected $servers;

    /** @var array<string,mixed> $configuration */
    protected $configuration;

    /** @var int $defaultConcurrency */
    protected $defaultConcurrency;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->servers = [];
        $this->configuration = $this->getDefaultConfiguration();
        $this->defaultConcurrency = 4;
    }
    /**
     * Sets the default concurrency parameter
     *
     * @param integer $num
     *
     * @return self
     */
    public function setConcurrency(int $num)
    {
        $this->defaultConcurrency = $num;

        return $this;
    }
    /**
     * Gets the default config
     * see http://docs.guzzlephp.org/en/stable/request-options.html
     *
     * @return array<string,mixed>
     */
    public function getDefaultConfiguration()
    {
        return [
            'http_errors'     => true, // handle explicitly below
            'connect_timeout' => 10,
            'timeout'         => 30,
            'read_timeout'    => 30,
            'debug' => false,
            'allow_redirects' => true,
            'headers' => [
                'Accept-Encoding' => 'gzip',
                'Accept'     => 'application/json',
                'User-Agent' => 'MultiServerClient/1.0',
            ],
        ];
    }
    /**
     * Set global configuration parameters
     *
     * @param array<string,mixed> $conf
     *
     * @return self
     */
    public function setConfiguration(array $conf)
    {
        $this->configuration = array_replace_recursive($this->configuration, $conf);

        return $this;
    }

    /**
     * Returns global configuration
     *
     * @return array<string,mixed>
     */
    public function getConfiguration()
    {
        return $this->configuration;
    }


    /**
     * Adds a server (or replace a server config)
     *
     * @param string              $key
     * @param string              $serverUri
     * @param array<string,mixed> $options
     *
     * @return self
     */
    public function addServer(string $key, string $serverUri, array $options = [])
    {
        $this->servers[$key] = array_replace_recursive(["uri" => $serverUri], $options);

        return $this;
    }

    /**
     * get all servers
     *
     * @return array<string,mixed>
     */
    public function getServers()
    {
        return $this->servers;
    }

    /**
     * get a server from the pool
     *
     * @param string $key
     *
     * @return array<string,mixed>
     *
     * @throws \Exception
     */
    public function getServer($key)
    {
        if (!array_key_exists($key, $this->servers)) {
            throw new \Exception(sprintf("Server does not exists: %s", $key));
        }

        return $this->servers[$key];
    }

    /**
     * Removes a server from the pool
     *
     * @param string $key
     *
     * @return self|false
     */
    public function removeServer($key)
    {
        if (!array_key_exists($key, $this->servers)) {
            return false;
        }
        unset($this->servers[$key]);

        return $this;
    }
    /**
     * send request to one server
     *
     * @param string              $serverKey
     * @param string              $method
     * @param string              $path
     * @param array<string,mixed> $requestOptions
     *
     * @return array<string,mixed>
     */
    public function sendToServer($serverKey, $method, $path, $requestOptions = [])
    {
        $result = $this->send($method, $path, $requestOptions, null, [$serverKey]);
        $ret = ["result" => null, "error" => null];
        if ($result["results"] && is_array($result["results"])
            && array_key_exists($serverKey, $result["results"])
        ) {
            $ret["result"] = $result["results"][$serverKey];
        }
        if ($result["errors"] && is_array($result["errors"])) {
            $ret["error"]  = reset($result["errors"]);
        }

        return $ret;
    }
    /**
     * send same query to multiple servers at once
     * see also http://docs.guzzlephp.org/en/stable/psr7.html
     *
     * @param string              $method          HTTP method
     * @param string              $path            Path to construct the URI
     * @param array<string,mixed> $requestOptions  Associative array of request options
     *                                             - 'headers' (array)  : Request headers
     *                                             See https://github.com/guzzle/psr7/blob/master/src/Request.php
     *                                             - 'body' (mixed)     :    Request body
     *                                             See https://github.com/guzzle/psr7/blob/master/src/Request.php
     *                                             - 'version' (string) :   Protocol version
     *                                             See https://github.com/guzzle/psr7/blob/master/src/Request.php
     *                                             - 'query' (array)   :    associative array of request variables
     *                                             see https://stackoverflow.com/questions/42538403/guzzle-request-query-params
     *
     *                                             - 'return_body' (bool)     : include body in results
     *                                             - 'return_json' (bool)     : include decode_json array in results
     *                                             - 'return_response' (bool) :
     *                                             include GuzzleHttp\Psr7\Response object in results
     *                                             - 'return_stats' (bool)    :
     *                                             include GuzzleHttp\TransferStats object in results
     * @param int|null            $concurrency     How many concurrency to use
     * @param array<string>|null  $serverKeys      List of server to use
     * @param array<string,mixed> $byserverOptions associative array of server specific options
     *
     * @return array<string,mixed>   results => result by server
     *                               errors => error by server
     */
    public function send(
        $method,
        $path,
        $requestOptions = [],
        $concurrency = null,
        $serverKeys = null,
        $byserverOptions = []
    ) {
        if (!$concurrency) {
            $concurrency = $this->defaultConcurrency;
        }
        $requestConfig  = array_key_exists("config", $requestOptions)? $requestOptions["config"] : [];
        $requestConfig2 = array_key_exists("configuration", $requestOptions)? $requestOptions["configuration"] : [];
        $clientConfig   = array_replace_recursive($this->configuration, $requestConfig, $requestConfig2);
        $client         = new Client($clientConfig);
        $servers        = $this->getServers();
        if (null !== $serverKeys) {
            foreach ($serverKeys as $serverKey) {
                if (!array_key_exists($serverKey, $servers)) {
                    throw new \Exception(sprintf("server unavailable: %s", $serverKey));
                }
            }
        }

        $promises = (function () use (
            $client,
            $servers,
            $method,
            $path,
            $requestOptions,
            $serverKeys,
            $byserverOptions
        ) {
            if (!$requestOptions || !is_array($requestOptions)) {
                $requestOptions = [];
            }
            if (!$byserverOptions || !is_array($byserverOptions)) {
                $byserverOptions = [];
            }
            $requestHeaders     = [];
            $requestData        = null;
            $requestVersion     = "1.1";
            $requestQueryString = null;
            $returnBody         = true;
            $returnJson         = true;
            $returnResponse     = false;
            $returnStats        = false;
            if (array_key_exists("headers", $requestOptions)) {
                $requestHeaders = $requestOptions["headers"];
            }
            if (array_key_exists("body", $requestOptions)) {
                $requestData = $requestOptions["body"];
            }
            if (array_key_exists("version", $requestOptions)) {
                $requestVersion = $requestOptions["version"];
            }
            if (array_key_exists("query", $requestOptions)) {
                $requestQueryString = Query::build($requestOptions["query"]);
            }
            if (array_key_exists("return_body", $requestOptions)) {
                $returnBody = $requestOptions["return_body"];
            }
            if (array_key_exists("return_json", $requestOptions)) {
                $returnJson = $requestOptions["return_json"];
            }
            if (array_key_exists("return_response", $requestOptions)) {
                $returnResponse = $requestOptions["return_response"];
            }
            if (array_key_exists("return_stats", $requestOptions)) {
                $returnStats = $requestOptions["return_stats"];
            }

            foreach ($servers as $serverKey => $serverData) {
                if (null !== $serverKeys and !in_array($serverKey, $serverKeys)) {
                    continue;
                }
                $url = $serverData["uri"].$path;
                $uri = new Uri($url)    ;

                if (null !== $requestQueryString) {
                        // https://stackoverflow.com/questions/42538403/guzzle-request-query-param       s
                    $uri = $uri->withQuery($requestQueryString);
                }

                if ($requestData && is_array($requestData)) {
                    $requestData = json_encode($requestData, JSON_INVALID_UTF8_IGNORE);
                    if (!array_key_exists("Content-Type", $requestHeaders)) {
                        $requestHeaders["Content-Type"] = "application/json";
                    }
                }
                $serverSpecificConf = array_key_exists("configuration", $serverData)? $serverData["configuration"] : [];
                $serverResult = [];
                if ($returnStats) {
                    $statsOpt = RequestOptions::ON_STATS;
                    $serverSpecificConf[$statsOpt] = function (TransferStats $stats) use (&$serverResult) {
                        $serverResult["stats"] = $stats;
                    };
                }
                if (array_key_exists($serverKey, $byserverOptions)) {
                    $serverSpecificConf = array_replace_recursive($serverSpecificConf, $byserverOptions[$serverKey]);
                }
                $request = new Request($method, $uri, $requestHeaders, $requestData, $requestVersion);
                // don't forget using generator
                yield $client->sendAsync($request, $serverSpecificConf)
                    ->then(
                        function (Response $response) use (
                            $serverKey,
                            &$serverResult,
                            $returnBody,
                            $returnJson,
                            $returnResponse
                        ) {
                            $body = ($returnBody || $returnJson)? $response->getBody()->getContents() : null;
                            $result = null;
                            $error = null;
                            try {
                                $decodeOpts = JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;
                                $result = ($returnJson) ? json_decode("$body", true, 512, $decodeOpts) : null;
                            } catch (\Exception $e) {
                                $error = $e;
                            }

                            // save to cache expiry to 300s
                            //$cache->put('cacheUser_' . $user, $profile, $expiry = 300);
                            if (!array_key_exists('stats', $serverResult)) {
                                $serverResult["stats"] = null;
                            }
                            $serverResult["response"] = ($returnResponse)? $response : null;
                            $serverResult["json"] = $returnJson? $result : null;
                            $serverResult["body"] = $returnBody? $body : null;
                            $serverResult["server"] = $serverKey;
                            $serverResult["error"] = $error;

                            return $serverResult;
                        }
                        /*, function (\Exception $e) use ($serverKey, $serverData) {
                        return [
                        "server" => $serverKey,
                        "error" => $e,
                        ];

                        }*/
                    );
            }
        })();

        $results = [];
        $errors = [];

        $eachPromise = new EachPromise(
            $promises,
            [
            // how many concurrency we are use
            'concurrency' => $concurrency,
            'fulfilled' => function ($returnedValue) use (&$results, &$errors) {
                // process object profile of user here
                $serverKey = $returnedValue["server"];
                if ($returnedValue["error"] !== null) {
                    $errors[$serverKey] = $returnedValue["error"];
                } else {
                    $results[$serverKey] = $returnedValue;
                }
            }
            ,
            'rejected' => function ($reason) use (&$errors) {
                // echo "REJECTED:"."\n";
                // handle promise rejected here
                $errors[] = $reason;
            },

            ]
        );
        $test = $eachPromise->promise()->wait();

        return ["results" => $results, "errors" => $errors];
    }
}
