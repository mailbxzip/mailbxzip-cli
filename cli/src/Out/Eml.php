<?php

namespace Mailbxzip\Cli\Out;

use Exception;
use RuntimeException;

/**
 * Class Pdf
 *
 * This class handles the export of emails to PDF format.
 * It includes methods for setting folders, saving emails.
 */
class Eml {
    public const HELP = 'Export e-mails to eml';

    public const MINIMAL_CONFIG_VAR = [
        'out' => 'Eml'
    ];

    public const CAN_DELETE = true;

    private $config;
    private $mailbox;

    /**
     * Pdf constructor.
     *
     * @param array $config Configuration array.
     * @param \Mailbxzip\Cli\Mailbox|null $mailbox Mailbox instance.
     */
    public function __construct($config, \Mailbxzip\Cli\Mailbox $mailbox = null) {
        // Load the configuration
        $this->config = $config;
        $this->mailbox = $mailbox;
    }

    /**
     * Get the configuration.
     *
     * @return array The configuration array.
     */
    public function getConfig() {
        return (!is_null($this->mailbox)) ? $this->mailbox->getConfig() : $this->config;
    }

    /**
     * Set the folders for email archiving.
     *
     * @param array $folders Array of folders to set.
     * @throws RuntimeException If unable to create a folder.
     */
    public function setFolders($folders) {
        // Check if the 'emailArchivePath' key exists in the configuration
        if (!isset($this->getConfig()['emailArchivePath'])) {
            // Create the 'emailArchivePath' key with a default path
            $this->getConfig()['emailArchivePath'] = '/default/path/to/archives';
        }

        $basePath = $this->getConfig()['emailArchivePath'];

        // Iterate over each key in the $folders array
        foreach ($folders as $folder => $nb) {
            // Build the full path of the folder
            $folderPath = $basePath . DIRECTORY_SEPARATOR . $folder;

            // Create the folder if it does not already exist
            if (!is_dir($folderPath)) {
                if (!mkdir($folderPath, 0777, true)) {
                    throw new RuntimeException("Unable to create folder: $folderPath");
                }
            }
        }
    }

    /**
     * Save emails to PDF format.
     *
     * @param object $eml Email object.
     */
    public function saveEmails($eml) {

        try {
            file_put_contents($this->savePath($eml), $eml->getContent());
        } catch (Exception $e) {
            // If html2pdf fails, save the source
            $this->mailbox->saveSource($eml, true);
            // Optionally, you can log the error
            $this->mailbox->log('Unable to save Eml file', 'ERROR');
            $this->mailbox->log($e->getMessage(), 'ERROR');
        }
    }

    /**
     * Get the PDF save path for an email.
     *
     * @param object $eml Email object.
     * @return string The PDF save path.
     */
    private function savePath($eml) {
        return $this->getConfig()['emailArchivePath'].'/'.$eml->getFolder().'/'.$eml->filename().'.eml';
    }

    /**
     * Pre-function hook.
     */
    public function preFunc() {
        //echo 'start';
    }

    /**
     * Post-function hook.
     */
    public function postFunc() {
        //echo 'end';
    }
}