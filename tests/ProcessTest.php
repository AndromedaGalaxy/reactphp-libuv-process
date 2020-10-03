<?php
/**
 * Andromeda
 * Copyright 2020 Andromeda, All Rights Reserved
 *
 * Website: https://github.com/AndromedaGalaxy/reactphp-libuv-process
 * License: https://github.com/AndromedaGalaxy/reactphp-libuv-process/blob/master/LICENSE
 * @noinspection PhpUnhandledExceptionInspection
 * @noinspection PhpInternalEntityUsedInspection
 */

namespace Andromeda\LibuvProcess\Tests;

use Andromeda\LibuvProcess\Process;
use React\EventLoop\ExtUvLoop;
use React\Promise\Deferred;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use function Clue\React\Block\await;

class ProcessTest extends TestCase {
    /**
     * @runInSeparateProcess
     */
    function testStartStandard() {
        $loop = new ExtUvLoop();
        
        $process = new Process('echo', array('Hello World'));
        $process->start($loop);
        
        $this->assertInstanceOf(WritableStreamInterface::class, $process->stdin);
        $this->assertInstanceOf(ReadableStreamInterface::class, $process->stdout);
        $this->assertInstanceOf(ReadableStreamInterface::class, $process->stderr);
        
        $data = '';
        $process->stdout->on('data', static function ($slice) use (&$data) {
            $data .= $slice;
        });
        
        $deferred = new Deferred();
        $process->once('exit', array($deferred, 'resolve'));
        
        await($deferred->promise(), $loop, 10.0);
        
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('Hello World', \rtrim($data));
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartStandardWithEnv() {
        $loop = new ExtUvLoop();
        
        $env = array('HELLO' => 'Hello World');
        $process = new Process(\PHP_BINARY, array('-r', 'echo getenv("HELLO");'), null, $env);
        $process->start($loop);
        
        $this->assertInstanceOf(WritableStreamInterface::class, $process->stdin);
        $this->assertInstanceOf(ReadableStreamInterface::class, $process->stdout);
        $this->assertInstanceOf(ReadableStreamInterface::class, $process->stderr);
        
        $data = '';
        $process->stdout->on('data', static function ($slice) use (&$data) {
            $data .= $slice;
        });
        
        $deferred = new Deferred();
        $process->once('exit', array($deferred, 'resolve'));
        
        await($deferred->promise(), $loop, 10.0);
        
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('Hello Worl', \rtrim($data, "\r\n\td")); // TODO: php-uv bug
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartNoStderr() {
        $loop = new ExtUvLoop();
        
        $fds = array(
            array('pipe', 'r'),
            array('pipe', 'rw'),
            null
        );
        
        $process = new Process('echo', array('Hello World'), null, null, $fds);
        $process->start($loop);
        
        $this->assertInstanceOf(WritableStreamInterface::class, $process->stdin);
        $this->assertInstanceOf(ReadableStreamInterface::class, $process->stdout);
        $this->assertNull($process->stderr);
        
        $data = '';
        $process->stdout->on('data', static function ($slice) use (&$data) {
            $data .= $slice;
        });
        
        $deferred = new Deferred();
        $process->once('exit', array($deferred, 'resolve'));
        
        await($deferred->promise(), $loop, 10.0);
        
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('Hello World', \rtrim($data));
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartResource() {
        $loop = new ExtUvLoop();
        
        $fd = \tmpfile();
        $fds = array(
            null,
            $fd,
            null
        );
        
        $process = new Process('echo', array('Hello World'), null, null, $fds);
        $process->start($loop);
        
        $this->assertNull($process->stdin);
        $this->assertNull($process->stdout);
        $this->assertNull($process->stderr);
        
        $deferred = new Deferred();
        $process->once('exit', array($deferred, 'resolve'));
        
        await($deferred->promise(), $loop, 10.0);
        
        \rewind($fd);
        $data = \fread($fd, 1000);
        \fclose($fd);
        
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('Hello World', \rtrim($data));
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartLibuvResource() {
        if(\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Operation not supported on Windows');
        }
        
        $loop = new ExtUvLoop();
        
        $fd = \uv_tcp_init($loop->getUvLoop());
        \uv_tcp_bind($fd, \uv_ip4_addr('0.0.0.0', 0));
        
        $fds = array(
            null,
            null,
            null,
            null,
            $fd
        );
        
        $process = new Process('echo', array('Hello World'), null, null, $fds);
        $process->start($loop);
        
        $this->assertNull($process->stdin);
        $this->assertNull($process->stdout);
        $this->assertNull($process->stderr);
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartFile() {
        $loop = new ExtUvLoop();
        
        $filename = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('', true);
        
        $fds = array(
            null,
            array('file', $filename, 'w+b'),
            null
        );
        
        $process = new Process('echo', array('Hello World'), null, null, $fds);
        $process->start($loop);
        
        $this->assertNull($process->stdin);
        $this->assertNull($process->stdout);
        $this->assertNull($process->stderr);
        
        $deferred = new Deferred();
        $process->once('exit', array($deferred, 'resolve'));
        
        await($deferred->promise(), $loop, 10.0);
        
        $data = \file_get_contents($filename);
        @\unlink($filename);
        
        $this->assertSame(0, $process->getExitCode());
        $this->assertSame('Hello World', \rtrim($data));
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartInvalidType() {
        $loop = new ExtUvLoop();
        
        $fds = array(
            array('test')
        );
        
        $process = new Process('echo', array('Hello World'), null, null, $fds);
        
        $this->expectException(\InvalidArgumentException::class);
        $process->start($loop);
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartInvalidPipeDirection() {
        $loop = new ExtUvLoop();
        
        $fds = array(
            array('pipe', 'a')
        );
        
        $process = new Process('echo', array('Hello World'), null, null, $fds);
        
        $this->expectException(\InvalidArgumentException::class);
        $process->start($loop);
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartAlreadyRunning() {
        $loop = new ExtUvLoop();
        
        $process = new Process('echo', array('Hello World'));
        $process->start($loop);
        
        $this->expectException(\RuntimeException::class);
        $process->start($loop);
    }
    
    /**
     * @runInSeparateProcess
     */
    function testStartInvalidParams() {
        $loop = new ExtUvLoop();
        $process = new Process('');
        
        $this->expectException(\RuntimeException::class);
        $process->start($loop);
    }
    
    /**
     * @runInSeparateProcess
     */
    function testTerminate() {
        $loop = new ExtUvLoop();
        $process = new Process(\PHP_BINARY);
        
        $this->assertFalse($process->terminate(\UV::SIGINT));
        $process->start($loop);
        
        $this->assertNull($process->getExitCode());
        $this->assertNull($process->getTermSignal());
        
        $this->assertTrue($process->terminate(\UV::SIGINT));
        
        $deferred = new Deferred();
        $process->once('exit', array($deferred, 'resolve'));
        
        await($deferred->promise(), $loop, 10.0);
        
        $this->assertNotNull($process->getExitCode());
        $this->assertSame(\UV::SIGINT, $process->getTermSignal());
    }
    
    /**
     * @runInSeparateProcess
     */
    function testGetPid() {
        $loop = new ExtUvLoop();
        
        $process = new Process('echo', array('Hello World'), null, null, array());
        $this->assertNull($process->getPid());
        
        $process->start($loop);
        
        $this->assertNotNull($process->getPid());
        $this->assertGreaterThan(0, $process->getPid());
    }
    
    /**
     * @runInSeparateProcess
     */
    function testGetExitCode() {
        $loop = new ExtUvLoop();
        
        $process = new Process('echo', array('Hello World'), null, null, array());
        $this->assertNull($process->getExitCode());
        
        $deferred = new Deferred();
        $process->once('exit', array($deferred, 'resolve'));
        
        $process->start($loop);
        await($deferred->promise(), $loop, 10.0);
        
        $this->assertSame(0, $process->getExitCode());
    }
    
    /**
     * @runInSeparateProcess
     */
    function testGetTermSignal() {
        $loop = new ExtUvLoop();
        
        $process = new Process(\PHP_BINARY);
        $this->assertNull($process->getTermSignal());
        
        $deferred = new Deferred();
        $process->once('exit', array($deferred, 'resolve'));
        
        $process->start($loop);
        
        $process->terminate(\UV::SIGINT);
        await($deferred->promise(), $loop, 10.0);
        
        $this->assertSame(\UV::SIGINT, $process->getTermSignal());
    }
    
    /**
     * @runInSeparateProcess
     */
    function testIsRunning() {
        $loop = new ExtUvLoop();
        
        $process = new Process('echo', array('Hello World'), null, null, array());
        $this->assertFalse($process->isRunning());
        
        $process->start($loop);
        $this->assertTrue($process->isRunning());
    }
}
