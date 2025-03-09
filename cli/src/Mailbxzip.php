<?php

namespace Ycdev\Mailbxzip;

use Exception;
use RuntimeException;

class Mailbox {
    private $emails = [];
    private $config;
    private $inputHandler;
    private $outputHandler;

    public function __construct($configFile, $inputHandler, $outputHandler) {
        // Charger la configuration depuis le fichier INI
        $this->config = parse_ini_file($configFile);
        $this->inputHandler = $inputHandler;
        $this->outputHandler = $outputHandler;
    }

    public function addEmail($email) {
        // Ajouter un email à la liste
        $this->emails[] = $this->inputHandler->process($email);
    }

    public function saveEmails() {
        // Sauvegarder les emails dans un fichier sous le format spécifié
        $filePath = $this->config['file_path'];
        $format = $this->config['format'];

        $content = '';

        foreach ($this->emails as $email) {
            $content .= $this->outputHandler->format($email, $format) . "\n";
        }

        file_put_contents($filePath, $content);
    }
}

