<?php

namespace Mailbxzip\Cli\Out;

use Mailbxzip\Cli\View;
use Throwable;

/**
 * Class Html
 *
 * Exports the mailbox as a browsable set of HTML pages: one document per
 * message, an index per folder, and a root index listing the folders. The
 * attachments are written next to each message and linked from it, so the
 * archive can be read with nothing but a web browser.
 *
 * The indexes are rebuilt from a manifest kept in the archive rather than from
 * the current run, so a resumed export still lists the messages written
 * earlier.
 */
class Html extends AbstractOutput {
    public const HELP = 'Export e-mails to a browsable set of HTML pages';

    public const MINIMAL_CONFIG_VAR = [
        'out' => 'Html'
    ];

    public const CAN_DELETE = true;

    private const MANIFEST = '.html-index.json';

    /** @var array<string,array<string,array>>|null Folder => filename => entry */
    private $manifest = null;

    /**
     * Write one message, its attachments, and record it in the manifest.
     */
    public function saveEmails(\Mailbxzip\Cli\Eml $eml): void {
        try {
            $folder = $eml->getFolder();
            $path = $this->uniquePath($this->archivePath().'/'.$folder.'/'.$eml->filename().'.html', $eml);
            $name = basename($path, '.html');

            $attachments = $this->saveAttachments($eml, $folder, $name);
            $data = $eml->get();

            $this->write($path, View::R('html/message.html.twig', $data + [
                'folder' => $folder,
                'attachmentDir' => rawurlencode($name.'_files'),
            ]));

            $this->record($folder, basename($path), [
                'date' => $this->shortDate($data['date'] ?? ''),
                'from' => $this->correspondent($data),
                'subject' => $data['subject'] ?? '',
                'attachments' => count($attachments),
            ]);
        } catch (Throwable $e) {
            $this->fallback($eml, $e, 'Unable to save HTML file');
        }
    }

    /**
     * Write the attachments of a message into its own sub-directory.
     *
     * @return array<int,string> The file names written.
     */
    private function saveAttachments(\Mailbxzip\Cli\Eml $eml, string $folder, string $name): array {
        $attachments = $eml->getAttachments();

        if (empty($attachments)) {
            return [];
        }

        $directory = $this->archivePath().'/'.$folder.'/'.$name.'_files';
        $written = [];

        foreach ($attachments as $index => $attachment) {
            // An attachment may arrive unnamed, and two may share a name.
            $filename = !empty($attachment['filename']) ? $attachment['filename'] : 'attachment-'.($index + 1).'.bin';

            if (in_array($filename, $written, true)) {
                $filename = ($index + 1).'-'.$filename;
            }

            $this->write($directory.'/'.$filename, $attachment['content']);
            $written[] = $filename;
        }

        return $written;
    }

    /**
     * Build the folder indexes and the root index, once every message is out.
     */
    public function postFunc() {
        $manifest = $this->manifest();
        $folders = [];
        $total = 0;

        ksort($manifest);

        foreach ($manifest as $folder => $messages) {
            uasort($messages, function (array $a, array $b) {
                return [$a['date'], $a['subject']] <=> [$b['date'], $b['subject']];
            });

            $rows = [];
            foreach ($messages as $file => $entry) {
                $rows[] = $entry + ['href' => rawurlencode($file)];
            }

            $this->write($this->archivePath().'/'.$folder.'/index.html', View::R('html/index.html.twig', [
                'title' => $folder,
                'messages' => $rows,
                'parent' => $this->relativeRoot($folder),
            ]));

            $folders[] = [
                'name' => $folder,
                'count' => count($rows),
                'href' => implode('/', array_map('rawurlencode', explode('/', $folder))).'/index.html',
            ];
            $total += count($rows);
        }

        $this->write($this->archivePath().'/index.html', View::R('html/index.html.twig', [
            'title' => $this->getConfig()['address'] ?? 'Archive',
            'folders' => $folders,
            'total' => $total,
        ]));
    }

    /**
     * Path back to the root index from inside a folder.
     */
    private function relativeRoot(string $folder): string {
        return str_repeat('../', substr_count($folder, '/') + 1).'index.html';
    }

    /**
     * Add an entry to the manifest and persist it.
     *
     * Written after every message, like the resume index: an export that is
     * interrupted must not lose the listing of what it already wrote.
     */
    private function record(string $folder, string $file, array $entry): void {
        $manifest = $this->manifest();
        $manifest[$folder][$file] = $entry;
        $this->manifest = $manifest;

        $this->write($this->archivePath().'/'.self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,array<string,array>>
     */
    private function manifest(): array {
        if (is_null($this->manifest)) {
            $path = $this->archivePath().'/'.self::MANIFEST;
            $this->manifest = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        }

        return $this->manifest;
    }

    /**
     * The other party of the exchange, as the file naming rule sees it.
     */
    private function correspondent(array $data): string {
        $from = (string) ($data['from'] ?? '');

        if (preg_match('/<(.+?)>/', $from, $matches)) {
            $from = $matches[1];
        }

        if (trim($from) === ($this->getConfig()['address'] ?? null)) {
            $to = (string) ($data['to'] ?? '');

            return preg_match('/<(.+?)>/', $to, $matches) ? $matches[1] : ($to ?: $from);
        }

        return $from;
    }

    /**
     * Reduce a Date header to YYYY-MM-DD, so the index sorts naturally.
     */
    private function shortDate($raw): string {
        $value = trim((string) $raw);

        if ($value === '') {
            return '';
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (Throwable $e) {
            return '';
        }
    }
}
