<?php

namespace Mailbxzip\Cli\Out;

use Throwable;

/**
 * Class Eml
 *
 * This class handles the export of emails to the raw .eml format, one file
 * per message, in folders and subfolders.
 */
class Eml extends AbstractOutput {
    public const HELP = 'Export e-mails to eml';

    public const MINIMAL_CONFIG_VAR = [
        'out' => 'Eml'
    ];

    public const CAN_DELETE = true;

    /**
     * Save one email as a .eml file.
     */
    public function saveEmails(\Mailbxzip\Cli\Eml $eml): void {
        try {
            $this->write($this->uniquePath($this->savePath($eml), $eml), $eml->getContent());
        } catch (Throwable $e) {
            $this->fallback($eml, $e, 'Unable to save Eml file');
        }
    }

    /**
     * Get the .eml save path for an email.
     */
    private function savePath(\Mailbxzip\Cli\Eml $eml): string {
        return $this->archivePath().'/'.$eml->getFolder().'/'.$eml->filename().'.eml';
    }
}
