<?php

namespace Mailbxzip\Cli\In;

use Exception;
use RuntimeException;
use Mailbxzip\Cli\Eml;

class Imap {
    private $config;
    private $imap;
    
    public function __construct($config) {
        // Charger la configuration
        $this->config = $config;

        // Initialiser la connexion IMAP
        $this->imap = imap_open(
            $this->config['server'],
            $this->config['username'],
            $this->config['password']
        );

        if (!$this->imap) {
            throw new RuntimeException('Impossible d\'ouvrir la connexion IMAP');
        }
    }

    public function getFolders() {
        // Obtenir la liste des dossiers
        $folders = imap_list($this->imap, $this->config['server'], '*');
        // Initialiser un tableau pour stocker la structure des dossiers
        $folderStructure = [];
        $totalEmails = 0;

        // Parcourir chaque dossier
        foreach ($folders as $folder) {
            // Décoder le nom du dossier en UTF-7
            $decodedFolder = imap_utf7_decode($folder);

            // Détecter l'encodage du nom du dossier
            $detectedEncoding = mb_detect_encoding($decodedFolder, ['UTF-7', 'ISO-8859-1', 'Windows-1252', 'ISO-8859-15', 'CP1252'], true);

            // Convertir le nom du dossier en UTF-8
            $utf8Folder = mb_convert_encoding($decodedFolder, 'UTF-8', $detectedEncoding);

            // Sélectionner le dossier
            imap_reopen($this->imap, $folder);

            // Obtenir le nombre d'emails dans le dossier
            $numEmails = imap_num_msg($this->imap);

            // Enlever le nom du serveur du nom du dossier
            $cleanFolderName = str_replace($this->config['server'], '', $utf8Folder);

            // Remplacer les points par des slashs dans les noms des sous-dossiers
            $cleanFolderName = str_replace('.', '/', $cleanFolderName);
            $cleanFolderName = str_replace("\0", "", $cleanFolderName);
            // Ajouter le dossier et le nombre d'emails à la structure
            $folderStructure[$cleanFolderName] = $numEmails;

            // Ajouter le nombre d'emails au total
            $totalEmails += $numEmails;
        }

        // Retourner la structure des dossiers et le nombre total d'emails
        return [
            'folders' => $folderStructure,
            'total' => $totalEmails
        ];
    }

    public function getEmails() {
        $folders = imap_list($this->imap, $this->config['server'], '*');
        $allEmails = [];
        // Parcourir chaque dossier
        foreach ($folders as $folder) {
            // Sélectionner le dossier (par exemple, 'INBOX')
            imap_reopen($this->imap, $folder);
            //imap_reopen($this->imap, 'INBOX');
            
            // Obtenir les identifiants de tous les emails dans le dossier
            $emails = imap_search($this->imap, 'ALL', SE_UID);

            // Fusionner les emails trouvés avec le tableau $allEmails
            $allEmails[$folder] = ($emails !== false) ? $emails : [];
        }

        // Retourner le tableau des identifiants des emails
        return $allEmails;
    }

    public function getEmail($id, $folder) {
        // Sélectionner le dossier (par exemple, 'INBOX')
        imap_reopen($this->imap, $folder);

        // Obtenir l'en-tête de l'email
        $header = imap_fetchheader($this->imap, $id, FT_UID);

        // Obtenir le corps de l'email
        $body = imap_body($this->imap, $id, FT_UID);

        // Concaténer l'en-tête et le corps pour obtenir l'email au format EML
        $email = $header . $body;
        // Retourner l'email au format EML
        return new Eml($email, $this->sanitizeFolderName($folder), $id, $this->config['address']);
    }

    private function sanitizeFolderName($folder) {
        $cleanFolderName = str_replace($this->config['server'], '', $folder);

        // Remplacer les points par des slashs dans les noms des sous-dossiers
        $cleanFolderName = str_replace('.', '/', $cleanFolderName);
        return $cleanFolderName;
    }

    public function preFunc() {
        //echo 'start';
    }

    public function postFunc() {
        //echo 'end';
    }
}