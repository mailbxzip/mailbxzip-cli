<?php

namespace Mailbxzip\Cli\In;

use Exception;
use RuntimeException;
use Mailbxzip\Cli\Eml;

/**
 * Class Imap
 *
 * This class handles the import of emails from IMAP/POP3/NNTP accounts.
 */
class Imap {
    private $config;
    private $imap;
    
    public const HELP = 'Import e-mails from imap/pop3/nnp account';

    public const MINIMAL_CONFIG_VAR = [
        'In' => 'Imap',
        'server' => 'server php string',
        'username' => '',
        'password' => ''
    ];

    /**
     * Imap constructor.
     *
     * @param array $config Configuration array containing server, username, and password.
     * @throws RuntimeException If the IMAP connection cannot be opened.
     */
    public function __construct($config) {
        // Load the configuration
        $this->config = $config;

        // Initialize the IMAP connection
        $this->imap = imap_open(
            $this->config['server'],
            $this->config['username'],
            $this->config['password']
        );

        if (!$this->imap) {
            throw new RuntimeException('Unable to open IMAP connection');
        }
    }

    /**
     * Get the list of folders and the number of emails in each folder.
     *
     * @return array An array containing the folder structure and the total number of emails.
     */
    public function getFolders() {
        // Get the list of folders
        $folders = imap_list($this->imap, $this->config['server'], '*');
        // Initialize an array to store the folder structure
        $folderStructure = [];
        $totalEmails = 0;

        // Iterate through each folder
        foreach ($folders as $folder) {
            // Decode the folder name from UTF-7
            $decodedFolder = imap_utf7_decode($folder);

            // Detect the encoding of the folder name
            $detectedEncoding = mb_detect_encoding($decodedFolder, ['UTF-7', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15', 'CP1252'], true);

            // Convert the folder name to UTF-8
            $utf8Folder = mb_convert_encoding($decodedFolder, 'UTF-8', $detectedEncoding);

            // Select the folder
            imap_reopen($this->imap, $folder);

            // Get the number of emails in the folder
            $numEmails = imap_num_msg($this->imap);

            // Remove the server name from the folder name
            $cleanFolderName = str_replace($this->config['server'], '', $utf8Folder);

            // Replace dots with slashes in subfolder names
            $cleanFolderName = str_replace('.', '/', $cleanFolderName);
            $cleanFolderName = str_replace("\0", "", $cleanFolderName);
            // Add the folder and the number of emails to the structure
            $folderStructure[$cleanFolderName] = $numEmails;

            // Add the number of emails to the total
            $totalEmails += $numEmails;
        }

        // Return the folder structure and the total number of emails
        return [
            'folders' => $folderStructure,
            'total' => $totalEmails
        ];
    }

    /**
     * Get the list of email IDs in each folder.
     *
     * @return array An array containing the email IDs for each folder.
     */
    public function getEmails() {
        $folders = imap_list($this->imap, $this->config['server'], '*');
        $allEmails = [];
        // Iterate through each folder
        foreach ($folders as $folder) {
            // Select the folder (e.g., 'INBOX')
            imap_reopen($this->imap, $folder);
            //imap_reopen($this->imap, 'INBOX');
            
            // Get the IDs of all emails in the folder
            $emails = imap_search($this->imap, 'ALL', SE_UID);

            // Merge the found emails with the $allEmails array
            $allEmails[$folder] = ($emails !== false) ? $emails : [];
        }

        // Return the array of email IDs
        return $allEmails;
    }

    /**
     * Get an email by its ID and folder.
     *
     * @param int $id The ID of the email.
     * @param string $folder The folder containing the email.
     * @return Eml The email object.
     */
    public function getEmail($id, $folder) {
        // Select the folder (e.g., 'INBOX')
        imap_reopen($this->imap, $folder);

        // Get the email header
        $header = imap_fetchheader($this->imap, $id, FT_UID);

        // Get the email body
        $body = imap_body($this->imap, $id, FT_UID);

        // Concatenate the header and body to get the email in EML format
        $email = $header . $body;
        // Return the email in EML format
        return new Eml($email, $this->sanitizeFolderName($folder), $id, $this->config['address']);
    }

    /**
     * Sanitize the folder name by removing the server name and replacing dots with slashes.
     *
     * @param string $folder The folder name to sanitize.
     * @return string The sanitized folder name.
     */
    private function sanitizeFolderName($folder) {
        $cleanFolderName = str_replace($this->config['server'], '', $folder);

        // Replace dots with slashes in subfolder names
        $cleanFolderName = str_replace('.', '/', $cleanFolderName);
        return $cleanFolderName;
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