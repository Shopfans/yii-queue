<?php
/**
 * @link https://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license https://www.yiiframework.com/license/
 */

namespace yii\queue\sync;

use Yii;
use yii\base\InvalidArgumentException;
use yii\queue\Queue as BaseQueue;

/**
 * Sync Queue.
 *
 * @author Roman Zhuravlev <zhuravljov@gmail.com>
 */
class Queue extends BaseQueue
{
    /**
     * @var bool
     */
    public $handle = false;

    /**
     * @var array of payloads
     */
    private $payloads = [];
    /**
     * @var int last pushed ID
     */
    private $pushedId = 0;
    /**
     * @var int started ID
     */
    private $startedId = 0;
    /**
     * @var int last finished ID
     */
    private $finishedId = 0;


    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        if ($this->handle) {
            $handlersList = Yii::app()->getEventHandlers('onEndRequest');
            Yii::app()->attachEventHandler('onEndRequest', function () {
                ob_start();
                $this->run();
                ob_end_clean();
            });
            $handler = $handlersList->removeAt($handlersList->count() - 1);
            $handlersList->insertAt(0, $handler);
        }
    }

    /**
     * Runs all jobs from queue.
     */
    public function run()
    {
        while (($payload = array_shift($this->payloads)) !== null) {
            list($ttr, $message) = $payload;
            $this->startedId = $this->finishedId + 1;
            $this->handleMessage($this->startedId, $message, $ttr, 1);
            $this->finishedId = $this->startedId;
            $this->startedId = 0;
        }
    }

    /**
     * @inheritdoc
     */
    protected function pushMessage($message, $ttr, $delay, $priority)
    {
        array_push($this->payloads, [$ttr, $message]);
        return ++$this->pushedId;
    }

    /**
     * @inheritdoc
     */
    public function status($id)
    {
        if (!is_int($id) || $id <= 0 || $id > $this->pushedId) {
            throw new InvalidArgumentException("Unknown messages ID: $id.");
        }

        if ($id <= $this->finishedId) {
            return self::STATUS_DONE;
        }

        if ($id === $this->startedId) {
            return self::STATUS_RESERVED;
        }

        return self::STATUS_WAITING;
    }
}
