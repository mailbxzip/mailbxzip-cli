<?php

namespace Mailbxzip\Cli\Out;

use Mpdf\Mpdf;
use RuntimeException;
use Throwable;

/**
 * Class Pdf
 *
 * This class handles the export of emails to PDF format.
 * It includes methods for saving emails, converting HTML to PDF and
 * re-attaching the original attachments to the produced document.
 */
class Pdf extends AbstractOutput {
    public const HELP = 'Export e-mails to pdf in folders and subfolders';

    public const MINIMAL_CONFIG_VAR = [
        'out' => 'Pdf'
    ];

    public const CONFIG_VAR = [
        'debugHtml' => '(1|0) store html source of pdf for debugging',
    ];

    public const CAN_DELETE = true;

    /**
     * Save one email as a PDF document.
     *
     * Attachments are only re-injected once the document exists: running that
     * step on a missing file used to abort the whole export.
     */
    public function saveEmails(\Mailbxzip\Cli\Eml $eml): void {
        $pdfFilePath = $this->uniquePath($this->pdfSavePath($eml), $eml);

        try {
            $html = $this->convertToUtf8($eml->view('pdf/mail.html'));
            $this->ensureDirectory(dirname($pdfFilePath));
            $this->html2pdf($html, $pdfFilePath);
            $this->attachFilesToPdf($eml, $pdfFilePath);
        } catch (Throwable $e) {
            $this->fallback($eml, $e, 'Unable to save PDF file');
        }
    }

    /**
     * Get the PDF save path for an email.
     */
    private function pdfSavePath(\Mailbxzip\Cli\Eml $eml): string {
        return $this->archivePath().'/'.$eml->getFolder().'/'.$eml->filename().'.pdf';
    }

    /**
     * Convert HTML content to PDF.
     *
     * Failures are deliberately left to bubble up: swallowing them here made
     * the caller believe the document had been produced.
     *
     * @throws Throwable If the document cannot be produced.
     */
    private function html2pdf(string $htmlContent, string $pdfFilePath): void {
        // Check if the debugHtml variable is defined and equal to 1
        if (isset($this->getConfig()['debugHtml']) && $this->getConfig()['debugHtml'] == 1) {
            // Build the path of the HTML debug file
            $htmlDebugFilePath = preg_replace('/\.pdf$/', '.html', $pdfFilePath);
            // Save the HTML content to the debug file
            $this->write($htmlDebugFilePath, $htmlContent);
        }

        $mpdf = new Mpdf();
        $mpdf->allow_charset_conversion = true;
        $mpdf->charset_in = 'UTF-8';

        foreach ($this->chunkHtml($htmlContent) as $htmlChunk) {
            $mpdf->WriteHTML($htmlChunk);
        }

        $mpdf->OutputFile($pdfFilePath);

        if (!is_file($pdfFilePath)) {
            throw new RuntimeException("mPDF reported no error but produced no file: $pdfFilePath");
        }
    }

    /**
     * Chunk HTML content into smaller parts.
     *
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
     * Attach the original files to the produced PDF, as file annotations.
     *
     * @throws RuntimeException If the PDF is missing or a temporary directory
     *                          cannot be created.
     */
    private function attachFilesToPdf(\Mailbxzip\Cli\Eml $eml, string $pdfFilePath): void {
        $attachments = $eml->getAttachments();

        if (empty($attachments)) {
            return;
        }

        if (!is_file($pdfFilePath)) {
            throw new RuntimeException("Cannot attach files to a missing PDF: $pdfFilePath");
        }

        // Use mPDF to add file annotations
        $mpdf = new Mpdf(['allowAnnotationFiles' => true]);
        $mpdf->allow_charset_conversion = true;
        $mpdf->charset_in = 'UTF-8';

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
        $this->ensureDirectory($tmpDir);

        try {
            // Add file annotations
            foreach ($attachments as $attachment) {
                // Check if the filename is empty and give it a generic name if necessary
                $filename = !empty($attachment['filename']) ? $attachment['filename'] : 'attachment_' . uniqid() . '.bin';
                $attachmentPath = $tmpDir . DIRECTORY_SEPARATOR . $filename;
                $this->write($attachmentPath, $attachment['content']);

                $mpdf->Annotation($filename, 0, 0, 'Note', '', '', 0, false, '', $attachmentPath);
            }

            $mpdf->Output($pdfFilePath, 'F');
        } finally {
            // Delete the temporary directory and its contents
            $this->deleteDirectory($tmpDir);
        }
    }

    /**
     * Delete a directory and its contents.
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
     */
    private function convertToUtf8(string $htmlContent): string {
        $detected = mb_detect_encoding($htmlContent, mb_detect_order(), true);

        if ($detected === false || $detected === 'UTF-8') {
            return $htmlContent;
        }

        return mb_convert_encoding($htmlContent, 'UTF-8', $detected);
    }
}
