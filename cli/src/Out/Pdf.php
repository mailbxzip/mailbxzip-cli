<?php

namespace Mailbxzip\Cli\Out;

use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;
use RuntimeException;
use Mpdf\Mpdf;

/**
 * Class Pdf
 *
 * This class handles the export of emails to PDF format.
 * It includes methods for setting folders, saving emails, and converting HTML to PDF.
 */
class Pdf {
    public const HELP = 'Export e-mails to pdf in folders and subfolders';

    public const MINIMAL_CONFIG_VAR = [
        'out' => 'Pdf'
    ];

    public const CONFIG_VAR = [
        'debugHtml' => '(1|0) store html source of pdf for debugging',
    ];

    public const CAN_DELETE = true;
    public const CAN_SAVE_SOURCE = true;

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
        $html = $eml->view('pdf/mail.html');
        $html = $this->convertToUtf8($html);
        $pdfFilePath = $this->pdfSavePath($eml);

        try {
            $this->html2pdf($html, $pdfFilePath);
        } catch (Exception $e) {
            // If html2pdf fails, save the source
            $this->mailbox->saveSource($eml, true);
            // Optionally, you can log the error
            $this->mailbox->log('Unable to save PDF file', 'ERROR');
            $this->mailbox->log($e->getMessage(), 'ERROR');
        }

        $this->attachFilesToPdf($eml, $pdfFilePath);
    }

    /**
     * Get the PDF save path for an email.
     *
     * @param object $eml Email object.
     * @return string The PDF save path.
     */
    private function pdfSavePath($eml) {
        return $this->getConfig()['emailArchivePath'].'/'.$eml->getFolder().'/'.$eml->filename().'.pdf';
    }

    /**
     * Convert HTML content to PDF.
     *
     * @param string $htmlContent HTML content to convert.
     * @param string $pdfFilePath Path to save the PDF file.
     * @return bool True if successful, false otherwise.
     */
    private function html2pdf($htmlContent, $pdfFilePath) {
        // Check if the debugHtml variable is defined and equal to 1
        if (isset($this->getConfig()['debugHtml']) && $this->getConfig()['debugHtml'] == 1) {
            // Build the path of the HTML debug file
            $htmlDebugFilePath = str_replace('.pdf', '.html', $pdfFilePath);
            // Save the HTML content to the debug file
            file_put_contents($htmlDebugFilePath, $htmlContent);
        }
        $htmlContent = $this->chunkHtml($htmlContent);
        try {
            $mpdf = new Mpdf();
            $mpdf->allow_charset_conversion=true;
            $mpdf->charset_in='UTF-8';
            foreach ($htmlContent as $key => $htmlChunk) {
                $mpdf->WriteHTML($htmlChunk);
            }
            $mpdf->OutputFile($pdfFilePath);
            return true;
        } catch (Exception $e) {
            $this->mailbox->log($e->getMessage(), 'ERROR');
            return false;
        } catch (Error $e) {
            $this->mailbox->log($e->getMessage(), 'ERROR');
            return false;
        }
    }

    /**
     * Chunk HTML content into smaller parts.
     *
     * @param string $htmlContent HTML content to chunk.
     * @return array Array of HTML chunks.
     */
    private function chunkHtml($htmlContent) {
        if(strlen($htmlContent) <= 1000000) {
            return [$htmlContent];
        }

        // Define possible separators
        $separators = ['</div>', '</p>', '<br>', '</br>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</ul>', '</ol>', '</li>', '</table>', '</tr>', '</td>', '</th>'];

        // Initialize variables to store the best separator and the maximum number of parts
        $bestSeparator = '';
        $maxChunks = 0;

        // Iterate over each separator to find the one that maximizes the number of parts
        foreach ($separators as $separator) {
            $chunks = explode($separator, $htmlContent);
            if (count($chunks) > $maxChunks) {
                $maxChunks = count($chunks);
                $bestSeparator = $separator;
            }
        }

        // Split the HTML content using the best separator found
        $finalChunks = explode($bestSeparator, $htmlContent);

        // Return the parts of the HTML content
        return $finalChunks;
    }

    /**
     * Attach files to the PDF.
     *
     * @param object $eml Email object.
     * @param string $pdfFilePath Path to the PDF file.
     * @throws RuntimeException If unable to create a temporary directory.
     */
    private function attachFilesToPdf($eml, $pdfFilePath) {
        $attachments = $eml->getAttachments();

        if (!empty($attachments)) {
            // Use mPDF to add file annotations
            $mpdf = new Mpdf(['allowAnnotationFiles' => true]);
            $mpdf->allow_charset_conversion=true;
            $mpdf->charset_in='UTF-8';

            // Import existing pages from the PDF
            $pageCount = $mpdf->SetSourceFile($pdfFilePath);
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $mpdf->ImportPage($pageNo);
                $mpdf->UseTemplate($templateId);
                if ($pageNo < $pageCount) {
                    $mpdf->AddPage();
                }
            }

            // Create a temporary directory for attachments
            $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('attachments_', true);
            if (!mkdir($tmpDir, 0777, true)) {
                throw new RuntimeException("Unable to create temporary directory: $tmpDir");
            }

            // Add file annotations
            foreach ($attachments as $attachment) {
                // Check if the filename is empty and give it a generic name if necessary
                $filename = !empty($attachment['filename']) ? $attachment['filename'] : 'attachment_' . uniqid() . '.bin';
                $attachmentPath = $tmpDir . DIRECTORY_SEPARATOR . $filename;
                file_put_contents($attachmentPath, $attachment['content']);

                $mpdf->Annotation($filename, 0, 0, 'Note', '', '', 0, false, '', $attachmentPath);
            }

            $mpdf->Output($pdfFilePath, 'F');

            // Delete the temporary directory and its contents
            $this->deleteDirectory($tmpDir);
        }
    }

    /**
     * Delete a directory and its contents.
     *
     * @param string $dir Directory to delete.
     */
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }

        rmdir($dir);
    }

    /**
     * Convert HTML content to UTF-8.
     *
     * @param string $htmlContent HTML content to convert.
     * @return string Converted HTML content.
     */
    private function convertToUtf8($htmlContent) {
        // Convert the HTML content to UTF-8
        return mb_convert_encoding($htmlContent, 'UTF-8', mb_detect_encoding($htmlContent));
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