<?php
/*
 * This file is part of leadingfellows/multiserver_guzzle
 */
namespace leadingfellows\multiserver_guzzle;

/**
 * Describe a MultiServerClient instance
 *
 */
interface MultiServerClientInterface
{
    /**
     * Constructor
     */
    public function __construct();
    /**
     * Sets the default concurrency parameter
     *
     * @param integer $num
     *
     * @return self
     */
    public function setConcurrency(int $num);
    /**
     * Gets the default config
     * see http://docs.guzzlephp.org/en/stable/request-options.html
     *
     * @return array<string,mixed>
     */
    public function getDefaultConfiguration();
    /**
     * Set global configuration parameters
     *
     * @param array<string,mixed> $conf
     *
     * @return self
     */
    public function setConfiguration(array $conf);
    /**
     * Returns global configuration
     *
     * @return array<string,mixed>
     */
    public function getConfiguration();
    /**
     * Adds a server (or replace a server config)
     *
     * @param string              $key
     * @param string              $serverUri
     * @param array<string,mixed> $options
     *
     * @return self
     */
    public function addServer(string $key, string $serverUri, array $options = []);
    /**
     * get all servers
     *
     * @return array<string,mixed>
     */
    public function getServers();
    /**
     * get a server from the pool
     *
     * @param string $key
     *
     * @return array<string,mixed>
     *
     * @throws \Exception
     */
    public function getServer($key);
    /**
     * Removes a server from the pool
     *
     * @param string $key
     *
     * @return self|false
     */
    public function removeServer($key);
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
    public function sendToServer($serverKey, $method, $path, $requestOptions = []);
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
    );
}
