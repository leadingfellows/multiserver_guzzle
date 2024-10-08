<?php
declare(strict_types=1);

/*
 * This file is part of leading
 */

namespace leadingfellows\multiserver_guzzle_tests;

use leadingfellows\multiserver_guzzle\MultiServerClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertMatchesRegularExpression;

/**
 * Tests for MultiServerClient.
 */
#[CoversClass("\leadingfellows\multiserver_guzzle\MultiServerClient")]
class MultiServerClientTest extends TestCase
{
    const SRV_ADDRESS  = '127.0.0.1:1349';
    const TESTS_DATA   = '/tests/phpunit/testsData';

    const TEST_TEXT    = 'this is a test';
    const TEST_JSON    = '{"name": "test"}';


    /** @var Process $process */
    private static $process;

    /**
     * launch php builtin web server
     */
    public static function setUpBeforeClass(): void
    {
        self::$process = new Process(['php', '-S', self::SRV_ADDRESS, '-t', getcwd().self::TESTS_DATA]);
        self::$process->start();
        $testPage = '<?php 
        print json_encode( ["request"=>$_REQUEST, "server"=>$_SERVER, "data"=>file_get_contents("php://input")]);';
        $testPath   = getcwd().self::TESTS_DATA;
        file_put_contents("$testPath/test.php", $testPage);
        file_put_contents("$testPath/test.txt", self::TEST_TEXT);
        file_put_contents("$testPath/test.json", self::TEST_JSON);
        error_reporting(E_ALL);
        usleep(100000); //wait for server to get going
    }

    /**
     * kills php builtin web server
     */
    public static function tearDownAfterClass(): void
    {
        self::$process->stop();
        $testPath   = getcwd().self::TESTS_DATA;
        //unlink("$testPath/test.php");
        unlink("$testPath/test.txt");
        unlink("$testPath/test.json");
    }
    /** setup a VfsStream filesystem with /conf/satis_dgfip.yaml
     *
     * {@inheritDoc}
     *
     * @see \PHPUnit\Framework\TestCase::setUp()
     */
    public function testConstructor(): void
    {
        $msc = new MultiServerClient();
        $this->assertEquals($msc->getDefaultConfiguration(), $msc->getConfiguration());
    }
    /**
     * Tests getConfiguration and setConfiguration methods
     */
    public function testConfiguration(): void
    {
        $msc = new MultiServerClient();
        $this->assertFalse($msc->getConfiguration()['debug']);
        $this->assertEquals(30, $msc->getConfiguration()['timeout']);
        $msc->setConfiguration(['debug' => true]);
        $this->assertTrue($msc->getConfiguration()['debug']);
        $this->assertEquals(30, $msc->getConfiguration()['timeout']);
        $msc->setConfiguration(['timeout'         => 60]);
        $this->assertTrue($msc->getConfiguration()['debug']);
        $this->assertEquals(60, $msc->getConfiguration()['timeout']);
        $msc->setConcurrency(3);
    }
    /**
     * tests addServer, removeServer, getServer and getServers methods
     */
    public function testAddAndRemoveServer(): void
    {
        $msc = new MultiServerClient();
        $msc->addServer('test', 'http://foo.bar.net');
        $srv = $msc->getServer('test');
        $this->assertEquals(['uri' => 'http://foo.bar.net'], $srv);
        $servers = $msc->getServers();
        $this->assertEquals([ 'test' => ['uri' => 'http://foo.bar.net']], $servers);
        $msc->removeServer('test');
        $msg = '';
        try {
            $srv = $msc->getServer('test');
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals("Server does not exists: test", $msg);
        $this->assertFalse($msc->removeServer('test'));
    }
    /**
     * Data provider for testSend
     *
     * @return array<string,array<mixed>>
     */
    public static function dataSend(): array
    {
        // options to test :
        // "headers", "body", "query", "version", "return_body", "return_json", "return_response", "return_stats"
        $optsTxt  = [ 'return_json'     => false];
        $optsRsp  = [ 'return_response' => true];
        $optsStat = [ 'return_stats'    => true];
        $optsJson = [ 'return_body'     => false];

        $json = json_decode(self::TEST_JSON, true);

        $data = [];
        //    test name                                   error             body          json   response   stats
        $data['plainText'] = ['/test.txt' , $optsTxt  , null            , self::TEST_TEXT, null   , null    , false ];
        $data['json']      = ['/test.json', $optsJson , null            , null           , $json  , null    , false ];
        $data['get-resp']  = ['/test.json', $optsRsp  , null            , self::TEST_JSON, $json  , '200:OK', false ];
        $data['json-err']  = ['/test.txt' , $optsRsp  , 'Syntax error'  , null           , null   , null    , false ];
        $data['get-stats'] = ['/test.json', $optsStat , null            , self::TEST_JSON, $json  , null    , true  ];

        return $data;
    }
    /**
     * Test send to one basic functionalities
     * @param string                   $path
     * @param array<string,mixed>      $opts
     * @param string|null              $error
     * @param string|null              $body
     * @param array<string,mixed>|null $json
     * @param string|null              $resp
     * @param bool                     $stats
     *
     * @return void
     *
     */
    #[DataProvider("dataSend")]
    public function testSendToOne($path, $opts, $error, $body, $json, $resp, $stats): void
    {
        $msc = new MultiServerClient();
        $msc->addServer('test', 'http://'.self::SRV_ADDRESS);
        $res1  = $msc->send('post', $path, $opts);
        $res2 = $msc->sendToServer('test', 'post', $path, $opts);
        foreach ([$res1, $res2] as $res) {
            if (null !== $error) {
                if (array_key_exists('errors', $res)) {
                    $errors = $res['errors'];
                    $this->assertEquals(1, count($errors));
                    /** @var \Exception $err */
                    $err = reset($errors);
                    $this->assertEmpty($res['results']);
                } else {
                    $err       = $res['error'];
                    $this->assertEmpty($res['result']);
                }
                $this->assertMatchesRegularExpression("#$error#", $err->getMessage());
            } else {
                if (array_key_exists('results', $res)) {
                    $results = $res['results']['test'];
                    $this->assertEmpty($res['errors']);
                } else {
                    $results = $res['result'];
                    $this->assertEmpty($res['error']);
                }
                $this->assertEquals($body, $results['body']);
                $this->assertEquals($json, $results['json']);
                $this->assertEquals($error, $results['error']);
                /** @var Response|null $resp */
                if (null !== $resp) {
                    $respString = $results['response']->getStatusCode().":".$results['response']->getReasonPhrase();
                    $this->assertEquals($resp, $respString);
                } else {
                    $this->assertnull($results['response']);
                }
                $this->assertEquals('test', $results['server']);
                if ($stats) {
                    //$this->assertInstanceOf('GuzzleHttp\TransferStats', $results['stats']);
                } else {
                    $this->assertNull($results['stats']);
                }
            }
        }
    }
    /**
     *
     */
    public function testSendWithQuery(): void
    {
        $msc    = new MultiServerClient();
        $msc->addServer('test', 'http://'.self::SRV_ADDRESS);
        $query  = ['key1' => 'value1', 'key2' => 'value2'];
        $res    = $msc->send('post', '/test.php', [ 'query' => $query ]);
        $data = $res['results']['test']['json']['request'];
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $data);
    }
    /**
     *
     */
    public function testSendWithBody(): void
    {
        $msc    = new MultiServerClient();
        $msc->addServer('test', 'http://'.self::SRV_ADDRESS);

        // test with array value (should be covnerted to json string)
        $bodyData  = ['key1' => 'value1', 'key2' => 'value2'];
        $res       = $msc->send('post', '/test.php', [ 'body' => $bodyData ]);
        $this->assertNotEmpty($res['results']);
        $data = $res['results']['test']['json']['data'];
        $this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], (array) json_decode($data));

        // test with string value
        $res       = $msc->send('post', '/test.php', [ 'body' => 'test_string' ]);
        $data = $res['results']['test']['json']['data'];
        $this->assertEquals('test_string', $data);
    }
    /**
     * tests requestOption['version']
     *
     * @return void
     */
    public function testVersion(): void
    {
        $msc    = new MultiServerClient();
        $msc->addServer('test', 'http://'.self::SRV_ADDRESS);
        $res       = $msc->send('post', '/test.php', [ 'version' => '1.0' ]);
        $version = $res['results']['test']['json']['server']['SERVER_PROTOCOL'];
        $this->assertEquals('HTTP/1.0', $version);
        $res       = $msc->send('post', '/test.php');
        $version = $res['results']['test']['json']['server']['SERVER_PROTOCOL'];
        $this->assertEquals('HTTP/1.1', $version);
    }
    /**
     *   test send multiple and various ways to specify headers :
     *   - by server configuration
     *   - by serverSpecificConf parameter
     *   - by requestOptions parameter
     */
    public function testSendMultiple(): void
    {
        $headers1 = [ 'Accept'     => 'application/json', 'User-Agent' => 'TESTMSC01/1.0' ];
        $headers2 = [ 'Accept'     => 'application/json', 'User-Agent' => 'TESTMSC02/1.0' ];
        $headers3 = [ 'Accept'     => 'application/json', 'User-Agent' => 'TESTMSC03/1.0' ];



        $msc    = new MultiServerClient();
        $msc->addServer('test1', 'http://'.self::SRV_ADDRESS, [ 'configuration' => ['headers' => $headers1 ]]);
        $msc->addServer('test2', 'http://'.self::SRV_ADDRESS);
        $msc->addServer('test3', 'http://'.self::SRV_ADDRESS);
        $serverSpecificConf = ['test3' => ['headers' => $headers3]];

        /* Default header is headers2 - but can be overwriten by server conf or by serverSpecificConf */
        $res    = $msc->send('get', '/test.php', ['headers' => $headers2], 1, null, $serverSpecificConf);
        $this->assertEquals(3, count($res['results']));
        $ua1 = $res['results']['test1']['json']['server']['HTTP_USER_AGENT'];
        $this->assertEquals('TESTMSC01/1.0', $ua1);
        $ua2 = $res['results']['test2']['json']['server']['HTTP_USER_AGENT'];
        $this->assertEquals('TESTMSC02/1.0', $ua2);
        $ua3 = $res['results']['test3']['json']['server']['HTTP_USER_AGENT'];
        $this->assertEquals('TESTMSC03/1.0', $ua3);

        $res    = $msc->send('post', '/test.php', [], 1, ['test1', 'test3'], $serverSpecificConf);
        $this->assertEquals(2, count($res['results']));
        $ua1 = $res['results']['test1']['json']['server']['HTTP_USER_AGENT'];
        $this->assertEquals('TESTMSC01/1.0', $ua1);
        $ua3 = $res['results']['test3']['json']['server']['HTTP_USER_AGENT'];
        $this->assertEquals('TESTMSC03/1.0', $ua3);
    }
    /**
     * Tests send method exceptions
     *
     */
    public function testSendExceptions(): void
    {
        $msc    = new MultiServerClient();
        $msc->addServer('test1', 'http://'.self::SRV_ADDRESS);
        $msc->addServer('testErr', 'http://127.0.0.1:9999');

        /* test on non existing server throws Exception */
        $msg = '';
        try {
            $msc->send('post', '/test.php', [], 1, ['test2']);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals('server unavailable: test2', $msg);

        /* test on error (connection refused) when only one server */
        $res = $msc->send('post', '/test.php', [], 1, ['testErr']);
        $err = $res['errors']['testErr'];
        $this->assertMatchesRegularExpression('/Connection refused|Couldn\'t connect to server/', $err->getMessage());
        /* test one error (connection refused) and one result */
        $res = $msc->send('post', '/test.php');
        // => server testErr has an error
        $err = $res['errors']['testErr'];
        $this->assertMatchesRegularExpression('/Connection refused|Couldn\'t connect to server/', $err->getMessage());
        // => and test1 has a result
        $this->assertArrayHasKey('json', $res['results']['test1']);
    }
    /**
     * For printing errors while debugging tests
     *
     * @param array<string,mixed> $res
     *
     */
    protected function showErrors($res): void
    {
        if (array_key_exists('errors', $res)) {
            foreach (array_keys($res['errors']) as $server) {
                /** @var \Exception $err */
                $err = $res['errors'][$server];
                print "ERROR : ".$err->getMessage()."\n".$err->getTraceAsString();
            }
        }
    }
}
