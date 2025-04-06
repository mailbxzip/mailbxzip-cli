<?php

namespace Mailbxzip\Cli;

use Exception;
use RuntimeException;

/**
 * Class Mailbox
 *
 * This class handles the mailbox operations, including configuration loading,
 * email processing, and logging. It interacts with input and output handlers
 * to manage email data.
 */
class Mailbox {
    private $emails = [];
    private $config;
    private $inputHandler;
    private $outputHandler;
    private $folders;
    private $workingDir;
    private $configFile;
    private $logger;

    public const CONFIG_VAR = [
        'wSource' => '(1|0) store eml source in separate "source" folder',
    ];

    /**
     * Mailbox constructor.
     *
     * @param string $configFile The configuration file path.
     * @param string|null $workingDir The working directory.
     * @throws RuntimeException If the configuration file does not exist.
     */
    public function __construct($configFile, $workingDir = null) {
        // Store the configuration file path
        $this->workingDir = (!is_null($workingDir)) ? $workingDir : '.';
        $this->configFile = $this->workingDir.'/config/'.$configFile;

        // Check if the configuration file exists
        if (!file_exists($this->configFile)) {
            throw new RuntimeException("The configuration file '".$this->configFile."' does not exist.");
        }

        // Load the configuration from the INI file
        $this->config = parse_ini_file($this->configFile);
        $this->config['working_dir'] = $this->workingDir;
        // Retrieve the class names from the configuration
        $inputClass = $this->config['in'];
        $outputClass = $this->config['out'];

        // Initialize the inputHandler and outputHandler objects using the provided class names
        $inputNamespace = "Mailbxzip\\Cli\\In\\" . $inputClass;
        $outputNamespace = "Mailbxzip\\Cli\\Out\\" . $outputClass;

        $this->inputHandler = new $inputNamespace($this->config, $this);
        $this->outputHandler = new $outputNamespace($this->config, $this);

        // Store the working directory
        $this->workingDir = $workingDir;

        // Create the necessary directories
        $this->createDirectories();
    }

    /**
     * Get the current configuration.
     *
     * @return array The configuration array.
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * Log a message with a specified level.
     *
     * @param string $message The message to log.
     * @param string $level The log level (default is 'INFO').
     */
    public function log($message, $level = 'INFO') {
        $this->updateState($message);
        $this->logger->log($message, $level);
    }

    /**
     * Start the mailbox processing.
     */
    public function start() {
        // Record the start timestamp
        $this->startTime();

        if (!isset($this->config['state'])) {
            $this->updateState('start');
            $this->preFunc();
        }
        $this->getFolders();
        $this->process();
        $this->postFunc();

        // Export the emailArchivePath folder to a ZIP archive
        $sourceDir = $this->config['emailArchivePath'];
        $destFile = $this->config['archives_dir'] . '/' . basename($this->configFile) . '.zip';
        $this->createZipArchive($sourceDir, $destFile);

        // Record the end timestamp
        $this->endTime();

        // Log the total export duration
        $this->logExportDuration();
    }

    /**
     * Get the folders from the input handler and set them in the output handler.
     */
    private function getFolders() {
        // Retrieve the email account structure
        $this->folders = $this->inputHandler->getFolders();
        $this->outputHandler->setFolders($this->folders['folders']);
    }

    /**
     * Process the emails.
     */
    private function process() {
        // Initialize the counter for the total number of emails
        $totalEmails = 0;

        // Traverse the email array to count the total number of emails
        $emails = $this->inputHandler->getEmails();
        foreach ($emails as $folder => $emailIds) {
            $totalEmails += count($emailIds);
        }

        // Log the total number of emails to save
        $this->log("Total emails to save: $totalEmails");

        // Initialize the counter for the total number of imported emails
        $importedEmails = 0;

        // JSON file path in the archives folder
        $jsonFilePath = $this->config['emailArchivePath'] . '/saved_emails.json';

        // Create or open the JSON file
        if (file_exists($jsonFilePath)) {
            $savedEmails = json_decode(file_get_contents($jsonFilePath), true);
        } else {
            $savedEmails = [];
        }

        // Traverse the emails to save them
        foreach ($emails as $folder => $emailIds) {
            $this->log("saving folder $folder : " . count($emailIds) . " email(s)");
            foreach ($emailIds as $key => $emailId) {
                // Check if the folder exists in the JSON file
                if (!isset($savedEmails[$folder])) {
                    $savedEmails[$folder] = [];
                }

                // Check if the email ID is already present in the corresponding folder
                if (!in_array($emailId, $savedEmails[$folder])) {
                    // Estimate the remaining time
                    $remainingTime = $this->estimateRemainingTime($importedEmails, $totalEmails);
                    $this->log("saving e-mail ($emailId) $key/" . count($emailIds) . " [" . $this->updateProgress($importedEmails, $totalEmails) . "%] - Estimated remaining time: $remainingTime");
                    $this->updateState('get email ' . $emailId);
                    $eml = $this->inputHandler->getEmail($emailId, $folder);
                    $this->outputHandler->saveEmails($eml);

                    // Add the email ID to the list of saved emails in the corresponding folder
                    $savedEmails[$folder][] = $emailId;

                    // Save the list of saved emails in the JSON file after each save
                    file_put_contents($jsonFilePath, json_encode($savedEmails, JSON_PRETTY_PRINT));
                    $this->saveSource($eml);
                } else {
                    $this->log("e-mail ($emailId) already saved in folder $folder, skipping");
                }
                // Increment the imported emails counter
                $importedEmails++;
            }
        }

        $this->updateProgress($importedEmails, $totalEmails);
        // Log the total number of imported emails
        $this->log("Total emails imported: $importedEmails");
    }

    /**
     * Create the necessary directories.
     */
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

        $this->config['emailArchivePath'] = $this->config['archives_dir'].'/'.$this->config['address'];
        if (!is_dir($this->config['emailArchivePath'])) {
            mkdir($this->config['emailArchivePath'], 0777, true);
        }

        $this->logger = new Log($this->config['emailArchivePath'].'/export.log');
    }

    /**
     * Get the archives path.
     *
     * @return string The archives path.
     */
    public function getArchivesPath() {
        return $this->config['archives'];
    }

    /**
     * Get the temporary path.
     *
     * @return string The temporary path.
     */
    public function getTmpPath() {
        return $this->config['tmp'];
    }

    /**
     * Save the current configuration to the INI configuration file.
     *
     * @throws Exception If the configuration file cannot be written.
     */
    public function updateConfig() {
        // Get the configuration file path
        $configFile = $this->configFile;

        // Check if the configuration file exists
        if (!file_exists($configFile)) {
            throw new RuntimeException("The configuration file '$configFile' does not exist.");
        }

        // Convert the configuration array to INI format
        $iniString = '';
        foreach ($this->config as $key => $value) {
            // Check if the value is a string and add quotes around the value
            if (is_string($value)) {
                $iniString .= "$key = \"$value\"\n";
            } else {
                $iniString .= "$key = $value\n";
            }
        }

        // Write the new values to the configuration file
        if (file_put_contents($configFile, $iniString) === false) {
            throw new Exception("Unable to write to the configuration file '$configFile'.");
        }
    }

    /**
     * Update the 'state' key in the configuration and save the file.
     *
     * @param string $newState The new state value.
     * @throws Exception If the configuration file cannot be written.
     */
    public function updateState($newState) {
        // Update the 'state' key in the configuration
        $this->config['state'] = $newState;

        // Call the updateConfig method to save the changes
        $this->updateConfig();
    }

    /**
     * Execute pre-processing functions in input and output handlers if they exist.
     */
    private function preFunc() {
        // Check and execute preFunc in inputHandler if it exists
        if (method_exists($this->inputHandler, 'preFunc')) {
            $this->inputHandler->preFunc();
        }

        // Check and execute preFunc in outputHandler if it exists
        if (method_exists($this->outputHandler, 'preFunc')) {
            $this->outputHandler->preFunc();
        }
    }

    /**
     * Execute post-processing functions in input and output handlers if they exist.
     */
    private function postFunc() {
        // Check and execute postFunc in inputHandler if it exists
        if (method_exists($this->inputHandler, 'postFunc')) {
            $this->inputHandler->postFunc();
        }

        // Check and execute postFunc in outputHandler if it exists
        if (method_exists($this->outputHandler, 'postFunc')) {
            $this->outputHandler->postFunc();
        }
    }

    /**
     * Update the progress percentage in the configuration.
     *
     * @param int $current The current progress.
     * @param int $total The total.
     * @return string The progress percentage.
     * @throws Exception If the configuration file cannot be written.
     */
    public function updateProgress($current, $total) {
        // Calculate the progress percentage
        if ($total > 0) {
            $progress = ($current / $total) * 100;
        } else {
            $progress = 0;
        }
        $progress = number_format($progress, 1);
        // Update the 'progress' key in the configuration
        $this->config['progress'] = $progress;
        // Call the updateConfig method to save the changes
        $this->updateConfig();
        return $progress;
    }

    /**
     * Log the total export duration to the log.
     */
    private function logExportDuration() {
        // Check if the start and end timestamps are defined
        if (isset($this->config['start_time']) && isset($this->config['end_time'])) {
            // Calculate the duration in seconds
            $duration = $this->config['end_time'] - $this->config['start_time'];

            // Convert the duration to hours, minutes, and seconds
            $hours = floor($duration / 3600);
            $minutes = floor(($duration % 3600) / 60);
            $seconds = $duration % 60;

            // Format the duration
            $formattedDuration = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

            // Log the duration
            $this->log("Total export duration: $formattedDuration");
        } else {
            $this->log("Start or end timestamp is not defined.");
        }
    }

    /**
     * Save the start timestamp in the configuration.
     *
     * @throws Exception If the configuration file cannot be written.
     */
    public function startTime() {
        // Save the current timestamp in the configuration
        $this->config['start_time'] = time();

        // Call the updateConfig method to save the changes
        $this->updateConfig();
    }

    /**
     * Save the end timestamp in the configuration.
     *
     * @throws Exception If the configuration file cannot be written.
     */
    public function endTime() {
        // Save the current timestamp in the configuration
        $this->config['end_time'] = time();

        // Call the updateConfig method to save the changes
        $this->updateConfig();
    }

    /**
     * Estimate the remaining time for the complete export.
     *
     * @param int $importedEmails The number of emails already imported.
     * @param int $totalEmails The total number of emails to import.
     * @return string The estimated remaining time formatted in hours, minutes, and seconds.
     */
    private function estimateRemainingTime($importedEmails, $totalEmails) {
        // Check if the start timestamp is defined
        if (!isset($this->config['start_time'])) {
            return "Start timestamp not defined.";
        }

        // Calculate the elapsed time in seconds
        $elapsedTime = time() - $this->config['start_time'];

        // Check if any emails have already been imported
        if ($importedEmails == 0) {
            return "No emails imported.";
        }

        // Calculate the estimated time to import one email
        $timePerEmail = $elapsedTime / $importedEmails;

        // Calculate the number of remaining emails
        $remainingEmails = $totalEmails - $importedEmails;

        // Calculate the estimated remaining time in seconds
        $remainingTime = $timePerEmail * $remainingEmails;

        // Convert the remaining time to hours, minutes, and seconds
        $hours = floor($remainingTime / 3600);
        $minutes = floor(($remainingTime % 3600) / 60);
        $seconds = $remainingTime % 60;

        // Format the remaining time
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Create a ZIP archive by importing files one by one and creating empty folders.
     *
     * @param string $sourceDir The source folder to archive.
     * @param string $destFile The destination ZIP file.
     * @param bool $deleteSource If true, the source folder will be deleted after archiving.
     * @throws Exception If the archiving fails.
     */
    public function createZipArchive($sourceDir, $destFile, $deleteSource = false) {
        // Check if the source folder exists
        if (!is_dir($sourceDir)) {
            throw new Exception("The source folder '$sourceDir' does not exist.");
        }

        // Create a new instance of ZipArchive
        $zip = new \ZipArchive();

        // Open the destination file in write mode
        if ($zip->open($destFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception("Unable to open the ZIP file '$destFile'.");
        }

        // Recursive function to add files and folders to the archive
        $addFilesToZip = function($dir) use (&$addFilesToZip, $zip, $sourceDir) {
            // List the files and folders in the current directory
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($items as $item) {
                // Get the relative path of the file or folder
                $filePath = $item->getPathname();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);

                if ($item->isDir()) {
                    // Add an empty folder to the archive
                    $zip->addEmptyDir($relativePath);
                } else {
                    // Add a file to the archive
                    $zip->addFile($filePath, $relativePath);
                }
            }
        };

        // Add the files and folders from the source folder to the archive
        $addFilesToZip($sourceDir);

        // Close the ZIP archive
        $zip->close();

        // Delete the source folder if necessary
        if ($deleteSource) {
            $this->deleteDirectory($sourceDir);
        }
    }

    /**
     * Delete a directory and its contents recursively.
     *
     * @param string $dir The directory to delete.
     * @throws Exception If the deletion fails.
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            throw new Exception("The directory '$dir' does not exist.");
        }

        // List the files and folders in the directory
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                // Delete the folder
                rmdir($item->getPathname());
            } else {
                // Delete the file
                unlink($item->getPathname());
            }
        }

        // Delete the directory itself
        rmdir($dir);
    }

    /**
     * Check if the static array PROHIBITED_CONFIG does not exist in the $inputHandler and $outputHandler classes
     * or if PROHIBITED_CONFIG[$entry] does not exist.
     *
     * @param string $entry The key to check in the PROHIBITED_CONFIG array.
     * @return bool Returns true if the condition is met for both handlers, otherwise false.
     */
    public function isConfigEntryAllowed($entry) {
        // Check in inputHandler
        //$entry = "::$entry";

        if (defined(get_class($this->inputHandler)."::PROHIBITED_CONFIG") && isset($this->inputHandler::PROHIBITED_CONFIG[$entry]) && $this->inputHandler::PROHIBITED_CONFIG[$entry]) {
            throw new Exception("The configuration '$entry' is not allowed for ".get_class($this->inputHandler).".");
        }

        // Check in outputHandler
        if (defined(get_class($this->outputHandler)."::PROHIBITED_CONFIG") && isset($this->outputHandler::PROHIBITED_CONFIG[$entry]) && $this->outputHandler::PROHIBITED_CONFIG[$entry]) {
            throw new Exception("The configuration '$entry' is not allowed for ".get_class($this->outputHandler).".");
        }

        return true;
    }

    /**
     * Get the save path for the email.
     *
     * @param object $eml The email object.
     * @return string The save path.
     */
    private function emlSavePath($eml) {
        return $this->sourcePath($eml).$eml->filename().'.eml';
    }

    /**
     * Get the source path for the email.
     *
     * @param object $eml The email object.
     * @return string The source path.
     */
    private function sourcePath($eml) {
        return $this->getConfig()['emailArchivePath'].'/'.$eml->getFolder().'/.eml/';
    }

    /**
     * Save the email source if the configuration allows it.
     *
     * @param object $eml The email object.
     * @param bool $force Force saving the source.
     * @throws RuntimeException If the directory cannot be created.
     */
    private function saveSource($eml, $force = false) {
        // Logic to save the email source
        // (Add the specific logic to save the email source here)
        // Check if the 'wSource' entry exists in the configuration and is equal to 1
        if ((isset($this->getConfig()['wSource']) && $this->getConfig()['wSource'] == 1 && $this->isConfigEntryAllowed('wSource')) || $force) {
            if (!is_dir($this->sourcePath($eml))) {
                if (!mkdir($this->sourcePath($eml), 0777, true)) {
                    throw new RuntimeException("Unable to create the directory: ".$this->sourcePath());
                }
            }
            file_put_contents($this->emlSavePath($eml), $eml->getContent());
        }
    }
}

