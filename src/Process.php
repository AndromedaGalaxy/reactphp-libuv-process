<?php
/**
 * Andromeda
 * Copyright 2020 Andromeda, All Rights Reserved
 *
 * Website: https://github.com/AndromedaGalaxy/reactphp-libuv-process
 * License: https://github.com/AndromedaGalaxy/reactphp-libuv-process/blob/master/LICENSE
 */

namespace Andromeda\LibuvProcess;

use Evenement\EventEmitterInterface;
use Evenement\EventEmitterTrait;
use React\EventLoop\ExtUvLoop;
use React\Stream\DuplexStreamInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * Child Process component based on libuv.
 *
 * This class implements the `EventEmitterInterface`
 * which allows you to react to certain events:
 *   - `exit` event:
 *     The `exit` event will be emitted whenever the process is no longer running.
 *     Event listeners will receive the exit code and termination signal as two
 *     arguments (both integers).
 *
 * The following file descriptor specifications are supported:
 *   - `array('pipe', mode)`
 *     Opens a pipe with the specified mode (`r`, `w`, `rw`, `r+`, `w+`).
 *
 *   - `array('file', mode)`
 *     Opens a file with the specified mode as stdio.
 *
 *   - `resource`
 *     Inherits the given resource to the child process.
 *
 *   - `UVStream`, `UVTcp`, `UVUdp` or `UVTty`
 *     Inherit the given libuv resource to the child process.
 *
 *   - `null`
 *
 * For example to only have stdout and stderr pipes, use:
 * ```php
 * $fdspecs = array(
 *     null,               // stdin
 *     array('pipe', 'w'), // stdout
 *     array('pipe', 'w')  // stderr
 * );
 * ```
 *
 * The file descriptor specification is always from the view of the child.
 */
class Process implements EventEmitterInterface {
    use EventEmitterTrait;
    
    /**
     * Default process flags for Windows.
     * @var int
     * @source
     */
    const DEFAULT_PROCESS_FLAGS_WIN = \UV::PROCESS_WINDOWS_HIDE;
    
    /**
     * The maximum timer interval that is allowed.
     * @var float
     * @internal
     */
    const UV_MAX_TIMER_INTERVAL = (\PHP_INT_MAX / 1000) - 2;
    
    /**
     * Pipe flags for read mode.
     * @var int
     * @internal
     */
    protected const PIPE_FLAGS_READ = (
        \UV::CREATE_PIPE |
        \UV::READABLE_PIPE |
        0x40 // UV_OVERLAPPED_PIPE
    );
    
    /**
     * Pipe flags for write mode.
     * @var int
     * @internal
     */
    protected const PIPE_FLAGS_WRITE = (
        \UV::CREATE_PIPE |
        \UV::WRITABLE_PIPE |
        0x40 // UV_OVERLAPPED_PIPE
    );
    
    /**
     * Pipe flags for duplex mode.
     * @var int
     * @internal
     */
    protected const PIPE_FLAGS_DUPLEX = (
        \UV::CREATE_PIPE |
        \UV::READABLE_PIPE |
        \UV::WRITABLE_PIPE |
        0x40 // UV_OVERLAPPED_PIPE
    );
    
    /**
     * Array with all process pipes (once started).
     *
     * Unless explicitly configured otherwise during construction, the following
     * standard I/O pipes will be assigned by default:
     * - 0: STDIN (`WritableStreamInterface`)
     * - 1: STDOUT (`ReadableStreamInterface`)
     * - 2: STDERR (`ReadableStreamInterface`)
     *
     * @var ReadableStreamInterface|WritableStreamInterface
     */
    public $pipes = array();
    
    /**
     * The stdin pipe, or null.
     * @var WritableStreamInterface|DuplexStreamInterface|null
     */
    public $stdin;
    
    /**
     * The stdout pipe, or null.
     * @var ReadableStreamInterface|DuplexStreamInterface|null
     */
    public $stdout;
    
    /**
     * The stderr pipe, or null.
     * @var ReadableStreamInterface|DuplexStreamInterface|null
     */
    public $stderr;
    
    /**
     * @var string
     */
    protected $cmd;
    
    /**
     * @var array
     */
    protected $args = array();
    
    /**
     * @var string
     */
    protected $cwd;
    
    /**
     * @var array|null
     */
    protected $env = array();
    
    /**
     * @var array
     */
    protected $fdspecs = array();
    
    /**
     * @var int
     */
    protected $flags;
    
    /**
     * @var array
     */
    protected $options;
    
    /**
     * @var ReadableStreamInterface[]|WritableStreamInterface[]|resource[]
     */
    protected $stdios = array();
    
    /**
     * @var \UVProcess|null
     */
    protected $process;
    
    /**
     * @var int|null
     */
    protected $pid;
    
    /**
     * @var int|null
     */
    protected $exitCode;
    
    /**
     * @var int|null
     */
    protected $termSignal;
    
    /**
     * Constructor.
     * @param string       $cmd
     * @param array        $args
     * @param string|null  $cwd      Defaults to the current working directory.
     * @param array|null   $env
     * @param array|null   $fdspecs  File descriptor specifications.
     * @param int|null     $flags    Any UV process flags. Null = default flags (on non-Windows = 0).
     * @param array        $options  Any UV process options (only `uid` and `gid` are supported by php-uv, unsupported on Windows).
     */
    function __construct(
        string $cmd,
        array $args = array(),
        ?string $cwd = null,
        ?array $env = null,
        ?array $fdspecs = null,
        ?int $flags = null,
        array $options = array()
    ) {
        $this->cmd = $cmd;
        $this->args = $args;
        $this->cwd = $cwd ?? \getcwd();
        $this->flags = $flags ?? (\PHP_OS_FAMILY === 'Windows' ? static::DEFAULT_PROCESS_FLAGS_WIN : 0);
        $this->options = $options;
        
        if($env !== null) {
            foreach($env as $key => $value) {
                $this->env[((string) $key)] = (string) $value;
            }
        }
        
        if($fdspecs === null) {
            $fdspecs = array(
                array('pipe', 'r'),
                array('pipe', 'w'),
                array('pipe', 'w')
            );
        }
        
        $this->fdspecs = $fdspecs;
    }
    
    /**
     * Destructor.
     * @codeCoverageIgnore
     */
    function __destruct() {
        foreach($this->stdios as $fd) {
            if(
                $fd instanceof ReadableStreamInterface ||
                $fd instanceof WritableStreamInterface
            ) {
                $fd->close();
            } elseif($fd instanceof \UVPipe) {
                \uv_close($fd, static function () {});
            } elseif(\is_resource($fd)) {
                \fclose($fd);
            }
        }
        
        $this->stdios = array();
        $this->pipes = array();
    }
    
    /**
     * Starts the process.
     * @param ExtUvLoop  $loop
     * @return void
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    function start(ExtUvLoop $loop): void {
        if($this->isRunning()) {
            throw new \RuntimeException('Process is already running');
        }
        
        // Prevents the event loop from exiting since
        // we're using the php-uv API directly
        $timer = $loop->addTimer(static::UV_MAX_TIMER_INTERVAL, static function () {});
        
        $fdspecs = array();
        foreach($this->fdspecs as $pos => $fd) {
            $type = (\is_array($fd) ? ($fd[0] ?? null) : null);
            
            if(\is_resource($fd)) {
                $fdspecs[] = \uv_stdio_new($fd, \UV::INHERIT_FD);
            } elseif(
                $fd instanceof \UVStream ||
                $fd instanceof \UVTcp ||
                $fd instanceof \UVUdp ||
                $fd instanceof \UVTty
            ) {
                $fdspecs[] = \uv_stdio_new($fd, \UV::INHERIT_STREAM);
            } elseif($type === 'pipe') {
                $fdspecs[] = $this->createPipe($loop, $pos, ($fd[1] ?? ''));
            } elseif($type === 'file') {
                $fd = \fopen(($fd[1] ?? ''), ($fd[2] ?? ''));
                $fdspecs[] = \uv_stdio_new($fd, \UV::INHERIT_FD);
                $this->stdios[] = $fd;
            } elseif($fd === null) {
                $null = (\PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null');
                $fd = \fopen($null, 'r+b');
                
                $fdspecs[] = \uv_stdio_new($fd, \UV::INHERIT_FD);
                $this->stdios[] = $fd;
            } else {
                throw new \InvalidArgumentException(
                    'Invalid pipe type '.(\is_scalar($fd) ? '"'.$fd.'"' : 'of type '.\gettype($fd))
                );
            }
        }
        
        $exitCb = function (\UVProcess $process, int $exitCode, int $termSig) use ($loop, $timer) {
            $loop->cancelTimer($timer);
            \uv_close($process, static function () {});
            
            $this->process = null;
            $this->pid = null;
            
            $this->exitCode = $exitCode;
            $this->termSignal = $termSig;
            
            foreach($this->stdios as $fd) {
                if(
                    $fd instanceof ReadableStreamInterface ||
                    $fd instanceof WritableStreamInterface
                ) {
                    $fd->close();
                } elseif(\is_resource($fd)) {
                    \fclose($fd);
                }
            }
            
            $this->stdios = array();
            $this->emit('exit', array($exitCode, $termSig));
            
            $this->removeAllListeners();
        };
        
        $this->exitCode = null;
        $this->termSignal = null;
        
        /** @noinspection PhpInternalEntityUsedInspection */
        $process = \uv_spawn(
            $loop->getUvLoop(),
            $this->cmd,
            $this->args,
            $fdspecs,
            $this->cwd,
            $this->env,
            $exitCb,
            $this->flags,
            $this->options
        );
        
        if(!($process instanceof \UVProcess)) {
            $loop->cancelTimer($timer);
            
            foreach($this->stdios as $fd) {
                if($fd instanceof \UVPipe) {
                    \uv_close($fd, static function () {});
                } elseif(\is_resource($fd)) {
                    \fclose($fd);
                }
            }
            
            $this->stdios = array();
            throw new \RuntimeException('Unable to start the process, return code: '.((int) $process));
        }
        
        $this->process = $process;
        $this->pid = \uv_process_get_pid($process);
        
        foreach($this->stdios as $key => $pipe) {
            $pipe = $this->prepareStdio($loop, $pipe, ($this->fdspecs[$key][1] ?? null));
            if($pipe !== null) {
                $this->stdios[$key] = $pipe;
                $this->pipes[$key] = $pipe;
                
                switch($key) {
                    case 0:
                        $this->stdin = $pipe;
                    break;
                    case 1:
                        $this->stdout = $pipe;
                    break;
                    case 2:
                        $this->stderr = $pipe;
                    break;
                }
            }
        }
    }
    
    /**
     * Terminate the process with an optional signal.
     * @param int  $signal  Optional signal (default: SIGTERM).
     * @return bool  Whether the signal was sent.
     */
    function terminate(int $signal = 15) {
        if(!$this->isRunning()) {
            return false;
        }
        
        \uv_process_kill($this->process, $signal);
        return true;
    }
    
    /**
     * Get the process ID. Returns `0` if using ext-uv < 0.2.5.
     * @return int|null
     */
    function getPid(): ?int {
        return $this->pid;
    }
    
    /**
     * Get the exit code returned by the process.
     * @return int|null
     */
    function getExitCode(): ?int {
        return $this->exitCode;
    }
    
    /**
     * Get the signal that caused the process to terminate its execution.
     * @return int|null
     */
    function getTermSignal(): ?int {
        return $this->termSignal;
    }
    
    /**
     * Return whether the process is still running.
     * @return bool
     */
    function isRunning(): bool {
        return ($this->process !== null);
    }
    
    /**
     * Creates a new uv pipe.
     * @param ExtUvLoop  $loop
     * @param int        $fd
     * @param string     $mode
     * @return \UVStdio
     */
    protected function createPipe(ExtUvLoop $loop, int $fd, string $mode) {
        if($mode === 'r') {
            $flags = static::PIPE_FLAGS_READ;
        } elseif($mode === 'w') {
            $flags = static::PIPE_FLAGS_WRITE;
        } elseif($mode === 'rw' || $mode === 'r+' || $mode === 'w+') {
            $flags = static::PIPE_FLAGS_DUPLEX;
        } else {
            throw new \InvalidArgumentException('Invalid pipe direction "'.$mode.'" for '.$fd);
        }
        
        /** @noinspection PhpInternalEntityUsedInspection */
        $pipe = \uv_pipe_init($loop->getUvLoop(), (\PHP_OS_FAMILY !== 'Windows'));
        $fds = \uv_stdio_new($pipe, $flags);
        
        $this->stdios[] = $pipe;
        return $fds;
    }
    
    /**
     * Prepares the input for the stdio properties, if possible.
     * @param ExtUvLoop      $loop
     * @param \UVPipe|mixed  $pipe
     * @param string|null    $mode
     * @return ReadableStreamInterface|WritableStreamInterface|DuplexStreamInterface|null
     */
    protected function prepareStdio(ExtUvLoop $loop, $pipe, ?string $mode) {
        if($pipe instanceof \UVPipe) {
            if($mode === 'r') {
                return (new WritablePipeStream($loop, $pipe));
            } elseif($mode === 'w') {
                return (new ReadablePipeStream($loop, $pipe));
            } elseif($mode === 'rw' || $mode === 'r+' || $mode === 'w+') {
                return (new DuplexPipeStream($loop, $pipe));
            }
        }
        
        return null;
    }
}
