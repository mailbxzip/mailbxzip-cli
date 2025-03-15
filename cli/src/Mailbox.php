<?php

namespace Mailbxzip\Cli;

use Exception;
use RuntimeException;

class Mailbox {
    private $emails = [];
    private $config;
    private $inputHandler;
    private $outputHandler;
    private $folders;
    private $workingDir;
    private $configFile;

    public function __construct($configFile, $workingDir = null) {
        // Stocker le chemin du fichier de configuration
        $this->workingDir = (!is_null($workingDir)) ? $workingDir : '.';
        $this->configFile = $this->workingDir.'/config/'.$configFile;

        // Vérifier si le fichier de configuration existe
        if (!file_exists($this->configFile)) {
            throw new RuntimeException("Le fichier de configuration '".$this->configFile."' n'existe pas.");
        }

        // Charger la configuration depuis le fichier INI
        $this->config = parse_ini_file($this->configFile);
        $this->config['working_dir'] = $this->workingDir;
        // Récupérer les noms des classes depuis la configuration
        $inputClass = $this->config['in'];
        $outputClass = $this->config['out'];

        // Initialiser les objets inputHandler et outputHandler en utilisant les noms de classes fournis
        $inputNamespace = "Mailbxzip\\Cli\\In\\" . $inputClass;
        $outputNamespace = "Mailbxzip\\Cli\\Out\\" . $outputClass;

        $this->inputHandler = new $inputNamespace($this->config);
        $this->outputHandler = new $outputNamespace($this->config);

        // Stocker le dossier de travail
        $this->workingDir = $workingDir;

        // Créer les répertoires nécessaires
        $this->createDirectories();
    }

    public function start() {
        if (!isset($this->config['state'])) {
            $this->updateState('start');
            $this->preFunc();
        }
        $this->getFolders();
        $this->process();
        $this->postFunc();
    }

    private function getFolders() {
        // Récupérer la structure du compte e-mail
        $this->folders = $this->inputHandler->getFolders();
        
        $this->config['emailArchivePath'] = $this->config['archives_dir'].'/'.$this->config['address'];
        if (!is_dir($this->config['emailArchivePath'])) {
            mkdir($this->config['emailArchivePath'], 0777, true);
        }
        
        $this->outputHandler->setFolders($this->folders['folders']);
    }

    private function process() {
        $emails = $this->inputHandler->getEmails();
        foreach ($emails as $folder=> $emailIds) {
            foreach($emailIds as $emailId) {
                $this->updateState('get email '.$emailId);
                $eml = $this->inputHandler->getEmail($emailId, $folder);
                $this->outputHandler->saveEmails($eml);
            }
            
        }
    }

    private function createDirectories() {
        $defaultDirectories = ['archives', 'tmp'];
        $directories = [];

        foreach ($defaultDirectories as $dir) {
            $directories[$dir] = $this->config[$dir.'_dir'] ?? ($this->workingDir ? $this->workingDir . '/' . $dir : $dir);
            $this->config[$dir.'_dir'] = $directories[$dir];
        }

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }

    public function getArchivesPath() {
        return $this->config['archives'];
    }

    public function getTmpPath() {
        return $this->config['tmp'];
    }

    /**
     * Enregistre la configuration actuelle dans le fichier de configuration INI.
     *
     * @throws Exception Si le fichier de configuration ne peut pas être écrit.
     */
    public function updateConfig() {
        // Récupérer le chemin du fichier de configuration
        $configFile = $this->configFile;

        // Vérifier si le fichier de configuration existe
        if (!file_exists($configFile)) {
            throw new RuntimeException("Le fichier de configuration '$configFile' n'existe pas.");
        }

        // Convertir le tableau de configuration en format INI
        $iniString = '';
        foreach ($this->config as $key => $value) {
            $iniString .= "$key = $value\n";
        }

        // Écrire les nouvelles valeurs dans le fichier de configuration
        if (file_put_contents($configFile, $iniString) === false) {
            throw new Exception("Impossible d'écrire dans le fichier de configuration '$configFile'.");
        }
    }

    /**
     * Met à jour la clé 'state' de la configuration et enregistre le fichier.
     *
     * @param string $newState La nouvelle valeur de l'état.
     * @throws Exception Si le fichier de configuration ne peut pas être écrit.
     */
    public function updateState($newState) {
        // Mettre à jour la clé 'state' dans la configuration
        $this->config['state'] = $newState;

        // Appeler la méthode updateConfig pour enregistrer les modifications
        $this->updateConfig();
    }

    private function preFunc() {
        // Vérifier et exécuter preFunc dans inputHandler si elle existe
        if (method_exists($this->inputHandler, 'preFunc')) {
            $this->inputHandler->preFunc();
        }

        // Vérifier et exécuter preFunc dans outputHandler si elle existe
        if (method_exists($this->outputHandler, 'preFunc')) {
            $this->outputHandler->preFunc();
        }
    }

    private function postFunc() {
        // Vérifier et exécuter postFunc dans inputHandler si elle existe
        if (method_exists($this->inputHandler, 'postFunc')) {
            $this->inputHandler->postFunc();
        }

        // Vérifier et exécuter postFunc dans outputHandler si elle existe
        if (method_exists($this->outputHandler, 'postFunc')) {
            $this->outputHandler->postFunc();
        }
    }
}

