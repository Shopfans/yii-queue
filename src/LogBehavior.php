<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\queue;

use Yii;
use yii\base\Behavior;

/**
 * Log Behavior.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
class LogBehavior extends Behavior
{
    /**
     * @var Queue
     * @inheritdoc
     */
    public $owner;
    /**
     * @var bool
     */
    public $autoFlush = true;

    /**
     * @var bool
     */
    public $profile = false;

    /**
     * @inheritdoc
     */
    public function events()
    {
        return [
            Queue::EVENT_AFTER_PUSH => 'afterPush',
            Queue::EVENT_BEFORE_EXEC => 'beforeExec',
            Queue::EVENT_AFTER_EXEC => 'afterExec',
            Queue::EVENT_AFTER_ERROR => 'afterError',
            cli\Queue::EVENT_WORKER_START => 'workerStart',
            cli\Queue::EVENT_WORKER_STOP => 'workerStop',
        ];
    }

    /**
     * @param PushEvent $event
     */
    public function afterPush(PushEvent $event)
    {
        $title = $this->getJobTitle($event);
        Yii::log("$title is pushed.", \CLogger::LEVEL_INFO,Queue::class);
    }

    /**
     * @param ExecEvent $event
     */
    public function beforeExec(ExecEvent $event)
    {
        $title = $this->getExecTitle($event);
        Yii::log("$title is started.", \CLogger::LEVEL_INFO, Queue::class);
        $this->profile && Yii::beginProfile($title, Queue::class);
    }

    /**
     * @param ExecEvent $event
     */
    public function afterExec(ExecEvent $event)
    {
        $title = $this->getExecTitle($event);
        $this->profile && Yii::endProfile($title, Queue::class);
        Yii::log("$title is finished.", \CLogger::LEVEL_INFO,Queue::class);
        if ($this->autoFlush) {
            Yii::getLogger()->flush(true);
        }
    }

    /**
     * @param ExecEvent $event
     */
    public function afterError(ExecEvent $event)
    {
        $title = $this->getExecTitle($event);
        $this->profile && Yii::endProfile($title, Queue::class);
        Yii::log("$title is finished with error: $event->error.", \CLogger::LEVEL_ERROR, Queue::class);
        if ($this->autoFlush) {
            Yii::getLogger()->flush(true);
        }
    }

    /**
     * @param cli\WorkerEvent $event
     * @since 2.0.2
     */
    public function workerStart(cli\WorkerEvent $event)
    {
        $title = 'Worker ' . $event->sender->getWorkerPid();
        Yii::log("$title is started.", \CLogger::LEVEL_INFO, Queue::class);
        $this->profile && Yii::beginProfile($title, Queue::class);
        if ($this->autoFlush) {
            Yii::getLogger()->flush(true);
        }
    }

    /**
     * @param cli\WorkerEvent $event
     * @since 2.0.2
     */
    public function workerStop(cli\WorkerEvent $event)
    {
        $title = 'Worker ' . $event->sender->getWorkerPid();
        $this->profile && Yii::endProfile($title, Queue::class);
        Yii::log("$title is stopped.", \CLogger::LEVEL_INFO,Queue::class);
        if ($this->autoFlush) {
            Yii::getLogger()->flush(true);
        }
    }

    /**
     * @param JobEvent $event
     * @return string
     * @since 2.0.2
     */
    protected function getJobTitle(JobEvent $event)
    {
        $name = $event->job instanceof JobInterface ? get_class($event->job) : 'unknown job';
        return "[$event->id] $name";
    }

    /**
     * @param ExecEvent $event
     * @return string
     * @since 2.0.2
     */
    protected function getExecTitle(ExecEvent $event)
    {
        $title = $this->getJobTitle($event);
        $extra = "attempt: $event->attempt";
        if ($pid = $event->sender->getWorkerPid()) {
            $extra .= ", PID: $pid";
        }
        return "$title ($extra)";
    }
}
