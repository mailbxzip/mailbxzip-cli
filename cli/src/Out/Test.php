<?php

namespace Mailbxzip\Cli\Out;

/**
 * Class Test
 *
 * Dry-run output: reports what would be written, without touching the disk.
 * Used to exercise an input connector on its own.
 */
class Test extends AbstractOutput {
    public const HELP = 'Fake email output for test only';

    public const MINIMAL_CONFIG_VAR = [
        'out' => 'Test'
    ];

    /**
     * Report the folder tree instead of creating it.
     *
     * @param array<string,int> $folders
     */
    public function setFolders(array $folders): void {
        foreach ($folders as $folder => $nb) {
            $this->report(sprintf('folder %s (%d message(s))', $folder, $nb));
        }
    }

    /**
     * Report the email instead of saving it.
     */
    public function saveEmails(\Mailbxzip\Cli\Eml $eml): void {
        $this->report(sprintf('%s/%s', $eml->getFolder(), $eml->filename()));
    }

    /**
     * Send a line to the mailbox log, or to the console when standalone.
     */
    private function report(string $message): void {
        if (!is_null($this->mailbox)) {
            $this->mailbox->log('[out:test] '.$message);
            return;
        }

        echo '[out:test] '.$message."\n";
    }
}
