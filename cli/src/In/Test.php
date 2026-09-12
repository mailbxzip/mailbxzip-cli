<?php

namespace Mailbxzip\Cli\In;

use Mailbxzip\Cli\Contract\DescribesFoldersInterface;
use Mailbxzip\Cli\Eml;
use RuntimeException;

/**
 * Class Test
 *
 * A fixed, offline set of e-mails. Lets the whole pipeline be exercised
 * without a mail server, and doubles as the reference implementation of
 * InputHandlerInterface.
 *
 * The fixtures deliberately include an accented folder name and a message
 * whose headers cannot be parsed, so that the folder normalisation and the
 * filename fallback are both covered.
 */
class Test extends AbstractInput implements DescribesFoldersInterface {
    public const HELP = 'Fake eml email for test only';

    public const MINIMAL_CONFIG_VAR = [
        'In' => 'Test'
    ];

    public const CONFIG_VAR = self::DATE_CONFIG_VAR;

    /** The fixture playing the part of a trash folder. */
    private const TRASH_FOLDER = 'INBOX/Corbeille';

    public const CAN_DELETE = false;

    /**
     * @return array{folders: array<string,int>, total: int}
     */
    public function getFolders(): array {
        $folders = [];
        $total = 0;

        foreach ($this->messages() as $folder => $messages) {
            $kept = count($this->selected($messages));

            $folders[$this->folderName($folder)] = $kept;
            $total += $kept;
        }

        return [
            'folders' => $folders,
            'total' => $total,
        ];
    }

    /**
     * @return array<string,array<int|string>>
     */
    public function getEmails(): array {
        $emails = [];

        foreach ($this->messages() as $folder => $messages) {
            $emails[$folder] = array_keys($this->selected($messages));
        }

        return $emails;
    }

    public function getEmail($id, $folder): Eml {
        $messages = $this->messages();

        if (!isset($messages[$folder][$id])) {
            throw new RuntimeException("Unknown test e-mail '$id' in folder '$folder'.");
        }

        return new Eml($messages[$folder][$id], $this->folderName($folder), $id, $this->config['address'] ?? null);
    }

    /**
     * @return array<int,array{name: string, path: string, count: int, flags: array<int,string>}>
     */
    public function describeFolders(): array {
        $described = [];

        foreach ($this->messages() as $folder => $messages) {
            $name = $this->folderName($folder);

            $described[] = [
                'name' => $name,
                'path' => $folder,
                'count' => count($this->selected($messages)),
                'flags' => ($name === self::TRASH_FOLDER) ? ['HasNoChildren', 'Trash'] : ['HasNoChildren'],
            ];
        }

        return $described;
    }

    public function detectTrashFolder(): ?string {
        return isset($this->messages()[self::TRASH_FOLDER]) ? self::TRASH_FOLDER : null;
    }

    /**
     * Keep only the messages inside the date window.
     *
     * A local source has no server to push the filter down to, so it sorts
     * them out on the Date header itself.
     *
     * @param array<int,string> $messages
     * @return array<int,string>
     */
    private function selected(array $messages): array {
        if (!$this->hasDateFilter()) {
            return $messages;
        }

        return array_filter($messages, function (string $raw): bool {
            preg_match('/^Date:\s*(.+)$/mi', $raw, $matches);

            return $this->withinDateRange($matches[1] ?? '');
        });
    }

    /**
     * The fixture set, keyed by folder then by UID.
     *
     * @return array<string,array<int,string>>
     */
    private function messages(): array {
        return [
            'INBOX' => [
                1 => $this->message(
                    'yann@mailbxzip.com',
                    'test@mailbxzip.com',
                    'Bonjour',
                    'Mon, 8 Jan 2024 22:41:44 +0100',
                    'Hello world!'
                ),
                2 => $this->message(
                    'contact@example.org',
                    'test@mailbxzip.com',
                    'Facture n°2024-017 — réglée',
                    'Mon, 15 Jan 2024 09:02:00 +0100',
                    "Bonjour,\n\nVeuillez trouver ci-joint la facture.\n\nCordialement."
                ),
            ],
            'INBOX/Éléments envoyés' => [
                3 => $this->message(
                    'test@mailbxzip.com',
                    'destinataire@example.org',
                    'Re: proposition',
                    'Tue, 16 Jan 2024 18:30:00 +0100',
                    'Bien reçu, merci.'
                ),
            ],
            // Same date, same sender, subjects that collide once truncated to
            // 50 characters: exercises the collision suffix.
            'INBOX/Archives' => [
                5 => $this->message(
                    'dup@example.org',
                    'test@mailbxzip.com',
                    'Compte rendu de la reunion du comite de pilotage partie 1',
                    'Thu, 1 Feb 2024 10:00:00 +0100',
                    'Premiere partie.'
                ),
                6 => $this->message(
                    'dup@example.org',
                    'test@mailbxzip.com',
                    'Compte rendu de la reunion du comite de pilotage partie 2',
                    'Thu, 1 Feb 2024 10:00:00 +0100',
                    'Deuxieme partie.'
                ),
                // Long accented subject: the truncation must not split a
                // multibyte character.
                7 => $this->message(
                    'accents@example.org',
                    'test@mailbxzip.com',
                    'Réunion trimestrielle des équipes opérationnelles à Évry',
                    'Fri, 2 Feb 2024 11:00:00 +0100',
                    'Ordre du jour.'
                ),
                // A body line starting with "From ": must be quoted in mbox,
                // otherwise a reader splits the message in two.
                8 => $this->message(
                    'quote@example.org',
                    'test@mailbxzip.com',
                    'Citation',
                    'Sat, 3 Feb 2024 12:00:00 +0100',
                    "Il a ecrit :\nFrom here on, nothing matters.\nFin."
                ),
            ],
            // Multipart message: exercises attachment extraction and, on the
            // Pdf side, their re-injection as annotations.
            'INBOX/Pieces jointes' => [
                9 => $this->messageWithAttachment(
                    'pj@example.org',
                    'test@mailbxzip.com',
                    'Avec piece jointe',
                    'Sun, 4 Feb 2024 13:00:00 +0100',
                    'Voir le document joint.',
                    'note.txt',
                    "Contenu de la piece jointe.\n"
                ),
            ],
            // Stands in for a trash folder, so the listing command and the
            // detection have something to find offline.
            'INBOX/Corbeille' => [],
            'INBOX/Brouillons' => [
                // No parsable Date header: exercises the Eml::filename() fallback.
                4 => "Subject: sans date\r\n\r\nBrouillon.",
            ],
        ];
    }

    /**
     * Build a multipart/mixed message carrying a single attachment.
     */
    private function messageWithAttachment(string $from, string $to, string $subject, string $date, string $body, string $filename, string $attachment): string {
        $boundary = 'mailbxzip-test-boundary';

        return implode("\r\n", [
            'From: '.$from,
            'To: '.$to,
            'Subject: '.$subject,
            'Date: '.$date,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
            '',
            '--'.$boundary,
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $body,
            '--'.$boundary,
            'Content-Type: text/plain; charset=UTF-8; name="'.$filename.'"',
            'Content-Disposition: attachment; filename="'.$filename.'"',
            'Content-Transfer-Encoding: base64',
            '',
            trim(chunk_split(base64_encode($attachment), 76, "\r\n")),
            '--'.$boundary.'--',
            '',
        ]);
    }

    private function message(string $from, string $to, string $subject, string $date, string $body): string {
        return implode("\r\n", [
            'From: '.$from,
            'To: '.$to,
            'Subject: '.$subject,
            'Date: '.$date,
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $body,
        ]);
    }
}
