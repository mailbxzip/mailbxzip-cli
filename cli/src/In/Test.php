<?php

namespace Mailbxzip\Cli\In;

use Exception;
use RuntimeException;

class Test {
    private $config;

    public function __construct($config) {
        // Charger la configuration
        $this->config = $config;
    }

    public function getFolders() {
        

        // Exemple de structure de dossiers (à implémenter selon la source des emails)
        $folders = [
            'Inbox' => [
                'subfolder1' => 120,
                'subfolder2' => 230
            ],
            'Sent' => 70
        ];

        // Retourner la structure des emails avec les dossiers et sous-dossiers
        $structure = [
            'folders' => $folders,
            'total' => 420
        ];

        return $structure;
    }

    public function getEmails() {
        return [0, 1, 2, 3, 4];
    }

    public function getEmail($id) {
        return ['Inbox', 'From: yann@mailbxzip.com
To: test@example.com
Subject: test
Date: Sun, 8 Jan 2024 22:41:44 +0100

Hello world!'];
    }

    public function preFunc() {
        echo 'start';
    }

    public function postFunc() {
        echo 'end';
    }
}