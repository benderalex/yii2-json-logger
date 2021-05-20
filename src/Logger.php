<?php
namespace benderalex\jsonlog;

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

    public function formatMessage($message)
    {
        $text = $this->prefixString . trim(parent::formatMessage($message));
        return $this->replaceNewline === null ?
            $text :
            str_replace("\n", $this->replaceNewline, $text);
    }

    protected function getTime($timestamp)
    {
        return $this->disableTimestamp ? '' : parent::getTime($timestamp);
    }
}