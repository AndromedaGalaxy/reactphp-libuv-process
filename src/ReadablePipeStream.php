<?php
/**
 * Andromeda
 * Copyright 2020 Andromeda, All Rights Reserved
 *
 * Website: https://github.com/AndromedaGalaxy/reactphp-libuv-process
 * License: https://github.com/AndromedaGalaxy/reactphp-libuv-process/blob/master/LICENSE
 */

namespace Andromeda\LibuvProcess;

use Evenement\EventEmitterTrait;
use React\EventLoop\ExtUvLoop;
use React\EventLoop\TimerInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

class ReadablePipeStream implements ReadableStreamInterface {
    use EventEmitterTrait;
    
    /**
     * @var ExtUvLoop
     */
    protected $loop;
    
    /**
     * @var \UVPipe
     */
    protected $pipe;
    
    /**
     * @var bool
     */
    protected $closed = false;
    
    /**
     * @var bool
     */
    protected $listening = false;
    
    /**
     * @var TimerInterface
     */
    protected $timer;
    
    /**
     * Constructor.
     * @param ExtUvLoop  $loop
     * @param \UVPipe    $pipe
     */
    function __construct(ExtUvLoop $loop, \UVPipe $pipe) {
        $this->loop = $loop;
        $this->pipe = $pipe;
        
        $this->resume();
    }
    
    /**
     * Destructor.
     * @codeCoverageIgnore
     */
    function __destruct() {
        $this->close();
    }
    
    /**
     * Checks whether this stream is in a readable state (not closed already).
     * @return bool
     */
    function isReadable() {
        return (!$this->closed);
    }
    
    /**
     * Pauses reading incoming data events.
     * @return void
     */
    function pause() {
        if(!$this->listening || $this->closed) {
            return;
        }
        
        $this->loop->cancelTimer($this->timer);
        $this->timer = null;
        
        @\uv_read_stop($this->pipe);
        $this->listening = false;
    }
    
    /**
     * Resumes reading incoming data events.
     * @return void
     */
    function resume() {
        if($this->listening || $this->closed) {
            return;
        }
        
        // Prevents the event loop from exiting since
        // we're using the php-uv API directly
        $this->timer = $this->loop->addTimer(Process::UV_MAX_TIMER_INTERVAL, static function () {});
        
        \uv_read_start($this->pipe, function (\UVPipe $pipe, $nread) {
            if(\is_string($nread)) {
                $this->emit('data', array($nread));
            } elseif($nread === \UV::EOF) {
                $this->emit('end');
                $this->close();
            } elseif($nread < 0) {
                $e = new \RuntimeException('Unable to read from pipe: '.\uv_strerror($nread));
                $this->emit('error', array($e));
                
                $this->close();
            }
        });
        
        $this->listening = true;
    }
    
    /**
     * Pipes all the data from this readable source into the given writable destination.
     * @param WritableStreamInterface  $dest
     * @param array                    $options
     * @return WritableStreamInterface  `$dest` as is.
     */
    function pipe(WritableStreamInterface $dest, array $options = array()) {
        return Util::pipe($this, $dest, $options);
    }
    
    /**
     * Closes the stream (forcefully).
     * @return void
     */
    function close() {
        if($this->closed) {
            return;
        }
        
        $this->pause();
        $this->closed = true;
        
        if(!@\uv_is_readable($this->pipe)) {
            $this->emit('close');
            $this->removeAllListeners();
            return;
        }
        
        \uv_close($this->pipe, function () {
            $this->emit('close');
            $this->removeAllListeners();
        });
    }
}
