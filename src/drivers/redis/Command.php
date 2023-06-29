<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\queue\redis;

use yii\console\Exception;
use yii\helpers\Console;
use yii\queue\cli\Command as CliCommand;

/**
 * Manages application redis-queue.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
class Command extends CliCommand
{
    /**
     * @var Queue
     */
    public $queue;
    /**
     * @var string
     */
    public $defaultAction = 'info';

    /**
     * @inheritdoc
     */
    protected function isWorkerAction($actionID)
    {
        return in_array($actionID, ['run', 'listen'], true);
    }

    /**
     * @param string $string
     * @return string
     */
    protected function format($string)
    {
        return call_user_func_array([$this, 'ansiFormat'], func_get_args());
    }


    /**
     * Info about queue status.
     */
    public function actionInfo()
    {
        $prefix = $this->queue->channel;
        $waiting = $this->queue->redis->llen("$prefix.waiting");
        $delayed = $this->queue->redis->zcount("$prefix.delayed", '-inf', '+inf');
        $reserved = $this->queue->redis->zcount("$prefix.reserved", '-inf', '+inf');
        $total = $this->queue->redis->get("$prefix.message_id");
        $done = $total - $waiting - $delayed - $reserved;

        Console::output($this->format('Jobs', Console::FG_GREEN));

        Console::stdout($this->format('- waiting: ', Console::FG_YELLOW));
        Console::output($waiting);

        Console::stdout($this->format('- delayed: ', Console::FG_YELLOW));
        Console::output($delayed);

        Console::stdout($this->format('- reserved: ', Console::FG_YELLOW));
        Console::output($reserved);

        Console::stdout($this->format('- done: ', Console::FG_YELLOW));
        Console::output($done);
    }

    /**
     * Runs all jobs from redis-queue.
     * It can be used as cron job.
     *
     * @return null|int exit code.
     */
    public function actionRun()
    {
        return $this->queue->run(false);
    }

    /**
     * Listens redis-queue and runs new jobs.
     * It can be used as daemon process.
     *
     * @param int $timeout number of seconds to wait a job.
     * @throws Exception when params are invalid.
     * @return null|int exit code.
     */
    public function actionListen($timeout = 3)
    {
        if (!is_numeric($timeout)) {
            throw new Exception('Timeout must be numeric.');
        }
        if ($timeout < 1) {
            throw new Exception('Timeout must be greater than zero.');
        }

        return $this->queue->run(true, $timeout);
    }

    /**
     * Clears the queue.
     *
     * @since 2.0.1
     */
    public function actionClear()
    {
        if ($this->confirm('Are you sure?')) {
            $this->queue->clear();
        }
    }

    /**
     * Removes a job by id.
     *
     * @param int $id
     * @throws Exception when the job is not found.
     * @since 2.0.1
     */
    public function actionRemove($id)
    {
        if (!$this->queue->remove($id)) {
            throw new Exception('The job is not found.');
        }
    }
}
