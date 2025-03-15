<?php

namespace Mailbxzip\Cli\Out;

use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;
use RuntimeException;

class Pdf {
    public const HELP = 'Export e-mails to pdf in folders and subfolders';
    public const CONFIG_VAR = [
        'wSource' => '(1|0) store eml source file in source subdir default 0'
    ];

    private $config;

    public function __construct($config) {
        // Charger la configuration
        $this->config = $config;
    }

    public function setFolders($folders) {
        // Vérifier si la clé 'emailArchivePath' existe dans la configuration
        if (isset($this->config['emailArchivePath'])) {
            $basePath = $this->config['emailArchivePath'];

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
        } else {
            throw new RuntimeException("La clé 'emailArchivePath' n'est pas définie dans la configuration.");
        }
    }

    public function saveEmails($eml) {
        $html = $eml->view('pdf/mail.html');
        $this->html2pdf($html, $this->pdfSavePath($eml));
        $this->saveSource($eml);
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
        return $this->config['emailArchivePath'].'/'.$eml->getFolder().'/'.$eml->filename().'.pdf';
    }

    private function emlSavePath($eml) {
        return $this->sourcePath($eml).$eml->filename().'.eml';
    }

    private function sourcePath($eml) {
        return $this->config['emailArchivePath'].'/'.$eml->getFolder().'/.eml/';
    }

    private function html2pdf($htmlContent, $pdfFilePath) {
        try {
            $dompdf = new Dompdf();
            $encoding = mb_detect_encoding($htmlContent, mb_list_encodings(), true);

            if($encoding != false) {
                $encodedContent = mb_convert_encoding($htmlContent, 'UTF-8', $encoding);
            } else {
                return false;
            }

            $dompdf->loadHtml($encodedContent);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            file_put_contents($pdfFilePath, $dompdf->output());
            return true;
        } catch (Exception $e) {
            return false;
        } catch (Error $e) {
            return false;
        }
    }

    public function preFunc() {
        //echo 'start';
    }

    public function postFunc() {
        //echo 'end';
    }
}