<?php

namespace Mailbxzip\Cli\Out;

use Throwable;

/**
 * Class Mbox
 *
 * This class handles the export of emails to the mbox format: one mailbox
 * file per folder, messages appended one after the other.
 *
 * The mboxrd variant is produced: each message is introduced by a "From "
 * envelope line, and any line of the message already starting with "From "
 * -- or ">From ", ">>From "... -- gets one more ">" so it cannot be mistaken
 * for a separator. Without that, a reader splits messages in the middle and
 * the archive is unusable.
 */
class Mbox extends AbstractOutput {
    public const HELP = 'Export e-mails to Mbox format';

    public const MINIMAL_CONFIG_VAR = [
        'out' => 'Mbox'
    ];

    public const CAN_DELETE = true;

    /**
     * Append one email to the mbox file of its folder.
     */
    public function saveEmails(\Mailbxzip\Cli\Eml $eml): void {
        try {
            $this->write($this->savePath($eml), $this->toMboxEntry($eml), FILE_APPEND);
        } catch (Throwable $e) {
            $this->fallback($eml, $e, 'Unable to save Mbox file');
        }
    }

    /**
     * Get the mbox save path for an email.
     */
    private function savePath(\Mailbxzip\Cli\Eml $eml): string {
        return $this->archivePath().'/'.$eml->getFolder().'/email.mbox';
    }

    /**
     * Turn a message into a complete mbox entry.
     *
     * The message bytes are kept verbatim apart from the ">From " quoting:
     * this is an archiving tool, the source must stay faithful.
     */
    private function toMboxEntry(\Mailbxzip\Cli\Eml $eml): string {
        $content = preg_replace('/^(>*From )/m', '>$1', $eml->getContent());

        // A reader expects the entry to end on a blank line.
        if (substr($content, -1) !== "\n") {
            $content .= "\n";
        }

        return $this->envelopeLine($eml)."\n".$content."\n";
    }

    /**
     * Build the "From " separator line: sender then asctime date.
     */
    private function envelopeLine(\Mailbxzip\Cli\Eml $eml): string {
        $data = $eml->get();

        return 'From '.$this->envelopeSender($data['from'] ?? '').' '.$this->envelopeDate($data['date'] ?? '');
    }

    /**
     * Extract a bare address, with the conventional fallback of a message
     * whose origin is unknown.
     */
    private function envelopeSender($from): string {
        if (preg_match('/<(.+?)>/', (string) $from, $matches)) {
            $from = $matches[1];
        }

        $from = trim((string) $from);

        // No whitespace allowed: it would break the separator line.
        return ($from === '') ? 'MAILER-DAEMON' : preg_replace('/\s+/', '', $from);
    }

    /**
     * Format the date the way mbox expects it, falling back to the current
     * time when the header is missing or unreadable.
     */
    private function envelopeDate($date): string {
        try {
            $parsed = new \DateTime((string) $date);
        } catch (Throwable $e) {
            $parsed = new \DateTime();
        }

        if (trim((string) $date) === '') {
            $parsed = new \DateTime();
        }

        // "Mon Jan  8 22:41:44 2024": the day is space padded.
        return $parsed->format('D M').' '.str_pad($parsed->format('j'), 2, ' ', STR_PAD_LEFT).$parsed->format(' H:i:s Y');
    }
}
