<?php
namespace Mailbxzip\Cli;

class Log {
    private $logFile;
    private $isConsole;

    public function __construct($logFile = 'app.log') {
        $this->logFile = $logFile;
        $this->isConsole = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }

    public function info($message) {
        $this->log($message, 'INFO');
    }

    public function error($message) {
        $this->log($message, 'ERROR');
    }

    public function log($message, string $level = 'INFO') {
        $logMessage = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);

        if ($this->isConsole) {
            echo $logMessage;
            file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        } else {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND);
        }
    }
}