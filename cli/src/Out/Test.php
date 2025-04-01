<?php

namespace Mailbxzip\Cli\Out;

use Exception;
use RuntimeException;

class Test {
    private $config;
    private $mailbox;
    public function __construct($config, Mailbox $mailbox = null) {
        $this->config = $config;
        $this->mailbox = $mailbox;
        // ... existing code ...
    }

    public function getConfig() {
        return (!is_null($this->mailbox)) ? $this->mailbox->getConfig() : $this->config;
    }

    public function setFolders($folders) {
        var_dump($folders);
    }

    public function saveEmails($eml) {
        var_dump([$eml->getFolder()]);
    }

    public function preFunc() {
        echo 'start';
    }

    public function postFunc() {
        echo 'end';
    }
}