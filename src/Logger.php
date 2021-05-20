<?php
namespace benderalex\jsonlog;

use yii\base\InvalidArgumentException;
use yii\helpers\ArrayHelper;
use yii\helpers\Json;
use yii\helpers\VarDumper;
use yii\log\LogRuntimeException;
use yii\log\Target as BaseTarget;
use yii\base\InvalidConfigException;


class Logger extends BaseTarget
{
    /**
     * @var string
     */
    public $target;

    /**
     * @var string|null
     */
    public $replaceNewline;

    /**
     * @var bool
     * [yii\log\Target::getTime()].
     */
    public $disableTimestamp = false;

    public $enableLocking = false;

    public $prefixString = '';

    protected $fp;

    protected $openedFp = false;

    public $includeContext = true;

    /**
     * @inheritdoc
     */
    public function __destruct()
    {
        if ($this->openedFp) {
            @fclose($this->fp);
        }
    }

    /**
     * @inheritdoc
     */
    public function init() {
        if (empty($this->fp) && empty($this->target)) {
            throw new InvalidConfigException("Either 'target' or 'fp' mus be set.");
        }
    }

    public function setFp($value)
    {
        if (!is_resource($value)) {
            throw new InvalidConfigException("Invalid resource.");
        }
        $metadata = stream_get_meta_data($value);
        if (!in_array($metadata['mode'], ['w', 'wb', 'a', 'ab'])) {
            throw new InvalidConfigException("Resource is not writeable.");
        }
        $this->fp = $value;
    }

    /**
     * @return resource
     */
    public function getFp()
    {
        if ($this->fp === null) {
            $this->fp = @fopen($this->target,'w');
            if ($this->fp === false) {
                throw new InvalidConfigException("Unable to open '{$this->target}' for writing.");
            }
            $this->openedFp = true;
        }
        return $this->fp;
    }


    public function closeFp()
    {
        if ($this->openedFp && $this->fp !== null) {
            @fclose($this->fp);
            $this->fp = null;
            $this->openedFp = false;
        }
    }


    public function export()
    {
        $text = implode("\n", array_map([$this, 'formatMessage'], $this->messages)) . "\n";
        $fp = $this->getFp();
        if ($this->enableLocking) {
            @flock($fp, LOCK_EX);
        }
        if (@fwrite($fp, $text) === false) {
            $error = error_get_last();
            throw new LogRuntimeException("Unable to export log!: {$error['message']}");
        }
        if ($this->enableLocking) {
            @flock($fp, LOCK_UN);
        }
        $this->closeFp();
    }


    /**
     * @param $log
     * @return array
     */
    protected static function formatTracesIfExists($log): array
    {
        $traces = ArrayHelper::getValue($log, 4, []);
        $formattedTraces = array_map(static function ($trace) {
            return "in {$trace['file']}:{$trace['line']}";
        }, $traces);

        $message = ArrayHelper::getValue($log, 0);
        if ($message instanceof \Exception) {
            $tracesFromException = explode("\n", $message->getTraceAsString());
            $formattedTraces = array_merge($formattedTraces, $tracesFromException);
        }

        return $formattedTraces;
    }


    public function formatMessage($log)
    {
        [$message, $level, $category, $timestamp] = $log;
        $traces = self::formatTracesIfExists($log);

        $text = $this->parseMessage($message);

        $formatted = [
            'timestamp' => $this->getTime($timestamp),
            'level' => \yii\log\Logger::getLevelName($level),
            'category' => $category,
            'traces' => $traces,
            'message' => $text
        ];

        return Json::encode($formatted);
    }

    protected function getTime($timestamp)
    {
        return $this->disableTimestamp ? '' : parent::getTime($timestamp);
    }

    /**
     * @param mixed $message
     * @return array|mixed|string
     */
    protected function parseMessage($message)
    {
        if (is_string($message)) {
            return ['data' => $message];
        }

        if (is_array($message) && array_keys($message) === 1) {
            return ['data' => current($message)];
        }

        if (is_array($message)) {
            return $message;
        }

        if ($message instanceof \Exception) {
            $message = (string)$message->getMessage();
        }

        if (!is_string($message)) {
            return VarDumper::export($message);
        }

        if (!$this->decodeMessage) {
            return $message;
        }

        try {
            return Json::decode($message, true);
        } catch (InvalidArgumentException $e) {
            return $message;
        }
    }
}