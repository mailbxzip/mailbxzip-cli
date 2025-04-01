<?php

namespace Mailbxzip\Cli\Out;

use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;
use RuntimeException;
use Mpdf\Mpdf;

class Pdf {
    public const HELP = 'Export e-mails to pdf in folders and subfolders';

    public const MINIMAL_CONFIG_VAR = [

    ];

    public const CONFIG_VAR = [
        'wSource' => '(1|0) store eml source file in source subdir default 0',
        'debugHtml' => '(1|0) store html source of pdf for debugging',
    ];

    public const CAN_DELETE = true;
    public const CAN_SAVE_SOURCE = true;

    private $config;
    private $mailbox;

    public function __construct($config, \Mailbxzip\Cli\Mailbox $mailbox = null) {
        // Charger la configuration
        $this->config = $config;
        $this->mailbox = $mailbox;
    }

    public function getConfig() {
        return (!is_null($this->mailbox)) ? $this->mailbox->getConfig() : $this->config;
    }

    public function setFolders($folders) {
        // Vérifier si la clé 'emailArchivePath' existe dans la configuration
        if (!isset($this->getConfig()['emailArchivePath'])) {
            // Créer la clé 'emailArchivePath' avec un chemin par défaut
            $this->getConfig()['emailArchivePath'] = '/chemin/par/defaut/vers/archives';
        }

        $basePath = $this->getConfig()['emailArchivePath'];

        // Parcourir chaque clé du tableau $folders
        foreach ($folders as $folder => $nb) {
            // Construire le chemin complet du dossier
            $folderPath = $basePath . DIRECTORY_SEPARATOR . $folder;

            // Créer le dossier si il n'existe pas déjà
            if (!is_dir($folderPath)) {
                if (!mkdir($folderPath, 0777, true)) {
                    throw new RuntimeException("Impossible de créer le dossier: $folderPath");
                }
            }
        }
    }

    public function saveEmails($eml) {
        $html = $eml->view('pdf/mail.html');
        $html = $this->convertToUtf8($html);
        $pdfFilePath = $this->pdfSavePath($eml);
        $this->html2pdf($html, $pdfFilePath);

        // Vérifier si l'entrée 'wSource' existe dans la configuration et est égale à 1
        if (isset($this->getConfig()['wSource']) && $this->getConfig()['wSource'] == 1) {
            $this->saveSource($eml);
        }

        $this->attachFilesToPdf($eml, $pdfFilePath);
    }

    private function saveSource($eml) {
        if (!is_dir($this->sourcePath($eml))) {
            if (!mkdir($this->sourcePath($eml), 0777, true)) {
                throw new RuntimeException("Impossible de créer le dossier: ".$this->sourcePath());
            }
        }
        file_put_contents($this->emlSavePath($eml), $eml->getContent());
    }

    private function pdfSavePath($eml) {
        return $this->getConfig()['emailArchivePath'].'/'.$eml->getFolder().'/'.$eml->filename().'.pdf';
    }

    private function emlSavePath($eml) {
        return $this->sourcePath($eml).$eml->filename().'.eml';
    }

    private function sourcePath($eml) {
        return $this->getConfig()['emailArchivePath'].'/'.$eml->getFolder().'/.eml/';
    }

    private function html2pdf($htmlContent, $pdfFilePath) {
        // Vérifier si la variable debugHtml est définie et égale à 1
        if (isset($this->getConfig()['debugHtml']) && $this->getConfig()['debugHtml'] == 1) {
            // Construire le chemin du fichier HTML de débogage
            $htmlDebugFilePath = str_replace('.pdf', '.html', $pdfFilePath);
            // Enregistrer le contenu HTML dans le fichier de débogage
            file_put_contents($htmlDebugFilePath, $htmlContent);
        }
        $htmlContent = $this->chunkHtml($htmlContent);
        try {
            $mpdf = new Mpdf();
            $mpdf->allow_charset_conversion=true;
            $mpdf->charset_in='UTF-8';
            foreach ($htmlContent as $htmlChunk) {
                //var_dump($htmlChunk);
                $mpdf->WriteHTML($htmlChunk);
            }
            $mpdf->OutputFile($pdfFilePath);
            return true;
        } catch (Exception $e) {

            var_dump($e->getMessage());
            return false;
        } catch (Error $e) {
            var_dump($e->getMessage());
            return false;
        }
    }

    private function chunkHtml($htmlContent) {
        //return [$htmlContent];
        if(strlen($htmlContent) <= 10000) {
            return [$htmlContent];
        }
        //return str_split($htmlContent, 10000);
        $separators = ['</div>', '</br>', '<hr>', '</p>', '</br>', '</span>'];
        $chunks = [$htmlContent];
        $i = 0;
        while (count($chunks) == 1 && $i <= (count($separators)-1)) {
            $chunks = array_map(function ($elem) use($separators, $i) {
                return $elem.$separators[$i];
            }, explode($separators[$i], $htmlContent));
            $i ++;
        }
        foreach ($chunks as $chunk) {
            var_dump(strlen($chunk));
        }
        return $chunks;
    }

    private function attachFilesToPdf($eml, $pdfFilePath) {
        $attachments = $eml->getAttachments();

        if (!empty($attachments)) {
            // Utiliser mPDF pour ajouter des annotations de fichiers
            $mpdf = new Mpdf(['allowAnnotationFiles' => true]);
            $mpdf->allow_charset_conversion=true;
            $mpdf->charset_in='UTF-8';

            // Importer les pages existantes du PDF
            $pageCount = $mpdf->SetSourceFile($pdfFilePath);
            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $mpdf->ImportPage($pageNo);
                $mpdf->UseTemplate($templateId);
                if ($pageNo < $pageCount) {
                    $mpdf->AddPage();
                }
            }

            // Créer un dossier temporaire pour les pièces jointes
            $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('attachments_', true);
            if (!mkdir($tmpDir, 0777, true)) {
                throw new RuntimeException("Impossible de créer le dossier temporaire: $tmpDir");
            }

            // Ajouter des annotations de fichiers
            foreach ($attachments as $attachment) {
                // Vérifier si le nom de fichier est vide et lui donner un nom générique si nécessaire
                $filename = !empty($attachment['filename']) ? $attachment['filename'] : 'attachment_' . uniqid() . '.bin';
                $attachmentPath = $tmpDir . DIRECTORY_SEPARATOR . $filename;
                file_put_contents($attachmentPath, $attachment['content']);

                $mpdf->Annotation($filename, 0, 0, 'Note', '', '', 0, false, '', $attachmentPath);
            }

            $mpdf->Output($pdfFilePath, 'F');

            // Supprimer le dossier temporaire et son contenu
            $this->deleteDirectory($tmpDir);
        }
    }

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

    private function convertToUtf8($htmlContent) {
        // Convertir le contenu HTML en UTF-8
        return mb_convert_encoding($htmlContent, 'UTF-8', mb_detect_encoding($htmlContent));
    }

    public function preFunc() {
        //echo 'start';
    }

    public function postFunc() {
        //echo 'end';
    }
}