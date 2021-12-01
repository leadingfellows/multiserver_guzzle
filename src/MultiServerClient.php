<?php

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
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;

/**
 * Class MultiServerClient
 *
 * @package leadingfellows\multiserver_guzzle
 */
class MultiServerClient
{
    /**
     * Servers
     *
     * @var array
     */
    protected $servers;


    /**
     * configuration
     *
     * @var array
     */
    protected $configuration;


    protected $defaultConcurrency;

    public function __construct()
    {
        $this->servers = [];
        $this->configuration = $this->getDefaultConfiguration();
        $this->defaultConcurrency = 4;
    }

    public function setConcurrency(int $num)
    {
        $this->defaultConcurrency = $num;
        return $this;
    }

    public function getDefaultConfiguration()
    {
        // http://docs.guzzlephp.org/en/stable/request-options.html
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
          'User-Agent' => 'MultiServerClient/1.0'
        ]
        ];
    }

    public function setConfiguration(array $conf)
    {
        $this->configuration = array_replace_recursive($this->configuration, $conf);
        return $this;
    }

    public function getConfiguration()
    {
        return $this->configuration;
    }


    /**
     * @param string $key
     * @param string $server_uri
     * @param array  $options
     */
    public function addServer(string $key, string $server_uri, array $options = [])
    {
        $this->servers[$key] = array_replace_recursive(
            [
            "uri" => $server_uri
            ], $options
        );
        return $this;
    }

    /**
     * get all servers
     *
     * @return array
     */
    public function getServers()
    {
        return $this->servers;
    }

    /**
     * get a server from the pool
     *
     * @param  $key
     * @return mixed
     * @throws \Exception
     */
    public function getServer($key)
    {
        if (!array_key_exists($key, $this->servers)) {
            throw new \Exception("Server does not exists: ".$key);
        }
        return $this->servers[$key];
    }

    /**
     * Removes a server from the pool
     *
     * @param  $key
     * @return bool
     */
    public function removeServer($key)
    {
        if (!array_key_exists($key, $this->servers)) {
            return false;
        }
        unset($this->servers[$key]);
        return $this;
    }

    public function sendToServer($serverKey, $method, $path, $requestOptions = [])
    {
        $result = $this->send($method, $path, $requestOptions, null, [$serverKey]);
        $ret = ["result" => null, "error" => null];
        if ($result["results"] && is_array($result["results"]) 
            && array_key_exists($serverKey, $result["results"])
        ) {
            $ret["result"] = $result["results"][$serverKey];
        }
        if ($result["errors"] && is_array($result["errors"]) && (count($result["errors"]) > 0)) {
            $ret["error"]  = reset($result["errors"]);
        }
        return $ret;
    }


    // http://docs.guzzlephp.org/en/stable/psr7.html

    /**
     * @param  $method         HTTP method
     * @param  $path           path to construct the URI
     * @param  array      $requestOptions (https://github.com/guzzle/psr7/blob/master/src/Request.php)
     *                                    array                                $headers Request
     *                                    headers string|null|resource|StreamInterface $body   
     *                                    Request body string                               $version
     *                                    Protocol version array                               
     *                                    $config  Config for Client
     * @param  int|null   $concurrency
     * @param  array|null $serverKeys
     * @return array
     *    results => result by server
     *    errors => error by server
     */
    public function send($method, $path, $requestOptions = [], $concurrency = null, $serverKeys = null, $byserverOptions = [])
    {
        if (!$concurrency) {
            $concurrency = $this->defaultConcurrency;
        }
        $request_configuration = array_key_exists("config", $requestOptions)? $requestOptions["config"] : [];
        $request_configuration2 = array_key_exists("configuration", $requestOptions)? $requestOptions["configuration"] : [];
        $client_config= array_replace_recursive($this->configuration, $request_configuration, $request_configuration2);
        //\Drupal::logger("guzzle config")->info("<pre>".print_r($client_config, TRUE)."</pre>");
        $client = new Client($client_config);
        $servers = $this->getServers();

        if ($serverKeys !== null) {
            foreach ($serverKeys as $serverKey) {
                if (!array_key_exists($serverKey, $servers)) {
                    throw new \Exception("server unavailable: " . $serverKey);
                }
            }
        }


        $promises = (function () use ($client, $servers, $method, $path,  $requestOptions, $serverKeys, $byserverOptions) {
            if (!$requestOptions || ! is_array($requestOptions)) {
                $requestOptions = [];
            }
            if (!$byserverOptions || ! is_array($byserverOptions)) {
                $byserverOptions = [];
            }
            $request_headers = array_key_exists("headers", $requestOptions)? $requestOptions["headers"] : [];
            $request_data = array_key_exists("body", $requestOptions)? $requestOptions["body"] : null;
            $request_query_string = array_key_exists("query", $requestOptions)? \GuzzleHttp\Psr7\build_query($requestOptions["query"]) : null;
            $request_version = array_key_exists("version", $requestOptions)? $requestOptions["version"] : "1.1";

            $return_body =  array_key_exists("return_body", $requestOptions)? $requestOptions["return_body"] : true;
            $return_json =  array_key_exists("return_json", $requestOptions)? $requestOptions["return_json"] : true;
            $return_response =  array_key_exists("return_response", $requestOptions)? $requestOptions["return_response"] : false;
            $return_stats = array_key_exists("return_stats", $requestOptions)? $requestOptions["return_stats"] : false;


            foreach ($servers as $serverKey => $serverData) {
                if ($serverKeys !== null and !in_array($serverKey, $serverKeys)) {
                    continue;
                }
                $url = $serverData["uri"].$path;
                /**
                 * @var \use Psr\Http\Message\UriInterface $uri
                */
                $uri = new Uri($url);

                if ($request_query_string !== null) {
                    // https://stackoverflow.com/questions/42538403/guzzle-request-query-params
                    $uri = $uri->withQuery($request_query_string);
                }

                /*if (false) {
                  $result = "toto";
                  yield new FulfilledPromise($result);
                  continue;
                }*/

                if ($request_data && is_array($request_data)) {
                    $request_data = json_encode($request_data, JSON_INVALID_UTF8_IGNORE);
                    if (!array_key_exists("Content-Type", $request_headers)) {
                        $request_headers["Content-Type"] = "application/json";
                    }
                }
                $serverSpecificConf = array_key_exists("configuration", $serverData)? $serverData["configuration"] : [];
                $server_result = [];
                if ($return_stats) {
                    $serverSpecificConf[\GuzzleHttp\RequestOptions::ON_STATS] = function (TransferStats $stats) use (&$server_result) {
                        $server_result["stats"] = $stats;
                        /* echo "STATS: ".$stats->getEffectiveUri() . "\n";
                        echo "STATS: ".$stats->getTransferTime() . "\n";

                        var_dump($stats->getHandlerStats());

                        // You must check if a response was received before using the
                        // response object.
                        if ($stats->hasResponse()) {
                            echo $stats->getResponse()->getStatusCode();
                        } else {
                            // Error data is handler specific. You will need to know what
                            // type of error data your handler uses before using this
                            // value.
                            var_dump($stats->getHandlerErrorData());
                        }*/
                    };
                }
                if(array_key_exists($serverKey, $byserverOptions)) {
                    $serverSpecificConf = array_replace_recursive($serverSpecificConf, $byserverOptions[$serverKey]);
                }
                $request = new Request($method, $uri, $request_headers, $request_data, $request_version);
                // don't forget using generator
                yield $client->sendAsync($request, $serverSpecificConf)
                    ->then(
                        function (Response $response) use ($serverKey, $serverData,  &$server_result, $return_body, $return_json, $return_response) {
                            $body = ($return_body || $return_json)? $response->getBody()->getContents() : null;
                            $result = null;
                            $error = null;
                            try {
                                $result = ($return_json) ? json_decode($body, true, 512, JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) : null;
                            } catch (\Exception $e) {
                                $error = $e;
                            }

                            // save to cache expiry to 300s
                            //$cache->put('cache_user_' . $user, $profile, $expiry = 300);
                            $server_result["response"] = ($return_response)? $response : null;
                            $server_result["json"] = $return_json? $result : null;
                            $server_result["body"] = $return_body? $body : null;
                            $server_result["server"] = $serverKey;
                            $server_result["error"] = $error;

                            return $server_result;
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

        if (0) {
            $results = \GuzzleHttp\Promise\settle($promises)->wait();
        }
        if (1) {
            $eachPromise = new EachPromise(
                $promises, [
                // how many concurrency we are use
                'concurrency' => $concurrency,
                'fulfilled' => function ($returned_value) use (&$results, &$errors) {
                    // process object profile of user here
                    $serverKey = $returned_value["server"];
                    if ($returned_value["error"] !== null) {
                        $errors[$serverKey] = $returned_value["error"];
                    } else {
                        $results[$serverKey] = $returned_value;
                    }
                }
                ,
                'rejected' => function ($reason) use (&$results, &$errors) {
                    // echo "REJECTED:"."\n";
                    // handle promise rejected here
                    $errors[] = $reason;
                }

                ]
            );
            $test = $eachPromise->promise()->wait();
        }
        return ["results" => $results, "errors" => $errors];
    }
}
