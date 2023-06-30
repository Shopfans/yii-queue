<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\queue\cli;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;
use yii\helpers\Console;
use yii\queue\ExecEvent;

/**
 * Base Command.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
abstract class Command extends \CConsoleCommand
{
    /**
     * The exit code of the exec action which is returned when job was done.
     */
    const EXEC_DONE = 0;
    /**
     * The exit code of the exec action which is returned when job wasn't done and wanted next attempt.
     */
    const EXEC_RETRY = 3;

    /**
     * @var bool|null whether to enable ANSI color in the output.
     * If not set, ANSI color will only be enabled for terminals that support it.
     */
    public $color = false;

    /**
     * @var Queue
     */
    public $queue;
    /**
     * @var bool verbose mode of a job execute. If enabled, execute result of each job
     * will be printed.
     */
    public $verbose = false;
    /**
     * @var array additional options to the verbose behavior.
     * @since 2.0.2
     */
    public $verboseConfig = [
        'class' => VerboseBehavior::class,
    ];
    /**
     * @var bool isolate mode. It executes a job in a child process.
     */
    public $isolate = true;
    /**
     * @var string path to php interpreter that uses to run child processes.
     * If it is undefined, PHP_BINARY will be used.
     * @since 2.0.3
     */
    public $phpBinary;

    protected $maxWorkerProcesses = 10;

    private $processPool = [];

    public function init()
    {
        $this->queue = \Yii::app()->{$this->name};
    }

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        $options = parent::options($actionID);
        if ($this->canVerbose($actionID)) {
            $options[] = 'verbose';
        }
        if ($this->canIsolate($actionID)) {
            $options[] = 'isolate';
            $options[] = 'phpBinary';
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'v' => 'verbose',
        ]);
    }

    /**
     * @param string $actionID
     * @return bool
     * @since 2.0.2
     */
    abstract protected function isWorkerAction($actionID);

    /**
     * @param string $actionID
     * @return bool
     */
    protected function canVerbose($actionID)
    {
        return $actionID === 'exec' || $this->isWorkerAction($actionID);
    }

    /**
     * @param string $actionID
     * @return bool
     */
    protected function canIsolate($actionID)
    {
        return $this->isWorkerAction($actionID);
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action, $params)
    {
        if ($this->canVerbose($action) && $this->verbose) {
            $this->queue->attachBehavior('verbose', ['command' => $this] + $this->verboseConfig);
        }

        if ($this->canIsolate($action) && $this->isolate) {
            if ($this->phpBinary === null) {
                $this->phpBinary = PHP_BINARY;
            }
            $this->queue->messageHandler = function ($id, $message, $ttr, $attempt, $finishProcessCallback = null) {
                return $this->handleMessage($id, $message, $ttr, $attempt, $finishProcessCallback);
            };
        }

        return parent::beforeAction($action, $params);
    }

    /**
     * Executes a job.
     * The command is internal, and used to isolate a job execution. Manual usage is not provided.
     *
     * @param string|null $id of a message
     * @param int $ttr time to reserve
     * @param int $attempt number
     * @param int $pid of a worker
     * @return int exit code
     * @internal It is used with isolate mode.
     */
    public function actionExec($id, $ttr, $attempt, $pid)
    {
        if ($this->queue->execute($id, file_get_contents('php://stdin'), $ttr, $attempt, $pid ?: null)) {
            return self::EXEC_DONE;
        }
        return self::EXEC_RETRY;
    }

    private function waitForPoolIsNotFull()
    {
        if (count($this->processPool) >= $this->maxWorkerProcesses) {
            while (true) {
                sleep(1);
                foreach ($this->processPool as $item) {
                    /** @var Process $process */
                    $process = $item['process'];
                    if (!$process->isRunning()) {
                        $this->handleProcessPool();
                        return;
                    }
                }
            }
        }
    }

    private function handleProcessPool()
    {
        foreach ($this->processPool as $key => $item) {
            /** @var Process $process */
            extract($item, EXTR_OVERWRITE);

            try {
                if ($process->isRunning()) {
                    continue;
                }

                $result = $process->wait(function ($type, $buffer) {
                    if ($type === Process::ERR) {
                        $this->stderr($buffer);
                    } else {
                        $this->stdout($buffer);
                    }
                });
                if (!in_array($result, [self::EXEC_DONE, self::EXEC_RETRY])) {
                    throw new ProcessFailedException($process);
                }

                is_callable($finishCallback) && $finishCallback($result === self::EXEC_DONE);
                unset($this->processPool[$key]);
            } catch (ProcessRuntimeException $error) {
                list($job) = $this->queue->unserializeMessage($message);
                return $this->queue->handleError(new ExecEvent([
                    'id' => $id,
                    'job' => $job,
                    'ttr' => $ttr,
                    'attempt' => $attempt,
                    'error' => $error,
                ]));
            }
        }
    }

    protected function waitForAllWorkerProcessesIsDone()
    {
        while (true) {
            $this->handleProcessPool();
            if (count($this->processPool) === 0) {
                return;
            }
            sleep(1);
        }
    }

    /**
     * Handles message using child process.
     *
     * @param string|null $id of a message
     * @param string $message
     * @param int $ttr time to reserve
     * @param int $attempt number
     * @return bool
     * @throws
     * @see actionExec()
     */
    protected function handleMessage($id, $message, $ttr, $attempt, $finishProcessCallback)
    {
        $this->handleProcessPool();
        $this->waitForPoolIsNotFull();

        // Child process command: php yii queue/exec "id" "ttr" "attempt" "pid"
        $cmd = [
            $this->phpBinary,
            // '-dxdebug.remote_enable=1',
            // '-dxdebug.remote_mode=req',
            // '-dxdebug.remote_port=9000',
            // '-dxdebug.remote_host=172.22.0.1',
            // '-dxdebug.remote_connect_back=0', // for debug
            $_SERVER['SCRIPT_FILENAME'],
            $this->getName(),
            'exec',
            "--id=$id",
            "--ttr=$ttr",
            "--attempt=$attempt",
            "--pid=" . $this->queue->getWorkerPid() ?: 0,
        ];

        $process = new Process($cmd, null, null, $message, $ttr);
        $this->processPool[] = [
            'process' => $process,
            'message' => $message,
            'id' => $id,
            'ttr' => $ttr,
            'attempt' => $attempt,
            'finishCallback' => $finishProcessCallback,
        ];
        try {
            $process->start();
        } catch (ProcessRuntimeException $error) {
            list($job) = $this->queue->unserializeMessage($message);
            return $this->queue->handleError(new ExecEvent([
                'id' => $id,
                'job' => $job,
                'ttr' => $ttr,
                'attempt' => $attempt,
                'error' => $error,
            ]));
        }
    }

    /**
     * Returns a value indicating whether ANSI color is enabled.
     *
     * ANSI color is enabled only if [[color]] is set true or is not set
     * and the terminal supports ANSI color.
     *
     * @param resource $stream the stream to check.
     * @return bool Whether to enable ANSI style in output.
     */
    protected function isColorEnabled($stream = \STDOUT)
    {
        return $this->color === null ? Console::streamSupportsAnsiColors($stream) : $this->color;
    }

    /**
     * Formats a string with ANSI codes.
     *
     * You may pass additional parameters using the constants defined in [[\yii\helpers\Console]].
     *
     * Example:
     *
     * ```
     * echo $this->ansiFormat('This will be red and underlined.', Console::FG_RED, Console::UNDERLINE);
     * ```
     *
     * @param string $string the string to be formatted
     * @return string
     */
    protected function ansiFormat($string)
    {
        if ($this->isColorEnabled()) {
            $args = func_get_args();
            array_shift($args);
            $string = Console::ansiFormat($string, $args);
        }

        return $string;
    }

    /**
     * Prints a string to STDOUT.
     *
     * You may optionally format the string with ANSI codes by
     * passing additional parameters using the constants defined in [[\yii\helpers\Console]].
     *
     * Example:
     *
     * ```
     * $this->stdout('This will be red and underlined.', Console::FG_RED, Console::UNDERLINE);
     * ```
     *
     * @param string $string the string to print
     * @param int ...$args additional parameters to decorate the output
     * @return int|bool Number of bytes printed or false on error
     */
    public function stdout($string)
    {
        if ($this->isColorEnabled()) {
            $args = func_get_args();
            array_shift($args);
            $string = Console::ansiFormat($string, $args);
        }

        return Console::stdout($string);
    }

    /**
     * Prints a string to STDERR.
     *
     * You may optionally format the string with ANSI codes by
     * passing additional parameters using the constants defined in [[\yii\helpers\Console]].
     *
     * Example:
     *
     * ```
     * $this->stderr('This will be red and underlined.', Console::FG_RED, Console::UNDERLINE);
     * ```
     *
     * @param string $string the string to print
     * @param int ...$args additional parameters to decorate the output
     * @return int|bool Number of bytes printed or false on error
     */
    public function stderr($string)
    {
        if ($this->isColorEnabled(\STDERR)) {
            $args = func_get_args();
            array_shift($args);
            $string = Console::ansiFormat($string, $args);
        }

        return fwrite(\STDERR, $string);
    }
}
