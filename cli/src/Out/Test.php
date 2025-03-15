<?php

namespace Mailbxzip\Cli\Out;

use Exception;
use RuntimeException;

class Test {
    private $config;

    public function __construct($config) {
        // Charger la configuration
        $this->config = $config;
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