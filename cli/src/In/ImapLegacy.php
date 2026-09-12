<?php

namespace Mailbxzip\Cli\In;

use RuntimeException;
use Mailbxzip\Cli\Contract\DeletableInputInterface;
use Mailbxzip\Cli\Contract\DescribesFoldersInterface;
use Mailbxzip\Cli\Eml;
use Mailbxzip\Cli\Mailbox;

/**
 * Class ImapLegacy
 *
 * Imports e-mails from IMAP/POP3/NNTP accounts through the ext-imap
 * extension.
 *
 * @deprecated Kept only for installations that still have ext-imap, which PHP
 *             deprecated and unbundled in 8.4. Prefer the Imap connector,
 *             which speaks the protocol directly and needs no extension. Both
 *             honour the same contract and read the same configuration.
 */
class ImapLegacy extends AbstractInput implements DeletableInputInterface, DescribesFoldersInterface {
    private $imap;

    /** @var string|null Resolved trash mailbox, looked up once */
    private $trashFolder = null;

    public const HELP = 'DEPRECATED, prefer "Imap" -- import e-mails through the ext-imap extension, removed from PHP core in 8.4';

    public const MINIMAL_CONFIG_VAR = [
        'in' => 'ImapLegacy',
        'server' => 'server php string',
        'username' => '',
        'password' => ''
    ];

    public const CONFIG_VAR = self::DATE_CONFIG_VAR + self::TRASH_CONFIG_VAR;

    /** This source can remove messages once they are archived. */
    public const CAN_DELETE = true;

    /**
     * Imap constructor.
     *
     * @param array        $config  Configuration array containing server, username and password.
     * @param Mailbox|null $mailbox Owning mailbox, when running under one.
     * @throws RuntimeException If the IMAP connection cannot be opened.
     */
    public function __construct($config, Mailbox $mailbox = null) {
        parent::__construct($config, $mailbox);

        if (!function_exists('imap_open')) {
            throw new RuntimeException(
                'The ext-imap extension is missing: PHP deprecated and unbundled it in 8.4. '
                .'Use the "Imap" connector instead, which needs no extension and reads the same configuration.'
            );
        }

        $this->log('the ImapLegacy connector relies on ext-imap, removed from PHP core in 8.4; the "Imap" connector replaces it', 'WARNING');

        // Initialize the IMAP connection
        $this->imap = imap_open(
            $this->config['server'],
            $this->config['username'],
            $this->config['password']
        );

        if (!$this->imap) {
            throw new RuntimeException('Unable to open IMAP connection: '.imap_last_error());
        }
    }

    /**
     * Get the list of folders and the number of emails in each folder.
     *
     * @return array{folders: array<string,int>, total: int}
     */
    public function getFolders(): array {
        // Get the list of folders
        $folders = imap_list($this->imap, $this->config['server'], '*');

        if ($folders === false) {
            throw new RuntimeException('Unable to list IMAP folders: '.imap_last_error());
        }

        // Initialize an array to store the folder structure
        $folderStructure = [];
        $totalEmails = 0;

        // Iterate through each folder
        foreach ($folders as $folder) {
            // Select the folder
            imap_reopen($this->imap, $folder);

            // Get the number of emails in the folder
            $numEmails = imap_num_msg($this->imap);

            // Add the folder and the number of emails to the structure
            $folderStructure[$this->normalizeFolderName($folder)] = $numEmails;

            // Add the number of emails to the total
            $totalEmails += $numEmails;
        }

        // Return the folder structure and the total number of emails
        return [
            'folders' => $folderStructure,
            'total' => $totalEmails
        ];
    }

    /**
     * Get the list of email IDs in each folder.
     *
     * The keys are the raw IMAP mailbox names: they are handed back to
     * getEmail(), which needs them to reopen the right mailbox.
     *
     * @return array<string,array<int|string>>
     */
    public function getEmails(): array {
        $folders = imap_list($this->imap, $this->config['server'], '*');

        if ($folders === false) {
            throw new RuntimeException('Unable to list IMAP folders: '.imap_last_error());
        }

        $allEmails = [];

        // Iterate through each folder
        foreach ($folders as $folder) {
            // Select the folder (e.g., 'INBOX')
            imap_reopen($this->imap, $folder);

            // Get the IDs of the emails to export in the folder. The date
            // window, if any, is evaluated by the server.
            $emails = imap_search($this->imap, $this->searchCriteria(), SE_UID);

            // Merge the found emails with the $allEmails array
            $allEmails[$folder] = ($emails !== false) ? $emails : [];
        }

        // Return the array of email IDs
        return $allEmails;
    }

    /**
     * Get an email by its ID and folder.
     *
     * @param int|string $id     The UID of the email.
     * @param string     $folder The raw IMAP mailbox name, as keyed by getEmails().
     */
    public function getEmail($id, $folder): Eml {
        // Select the folder (e.g., 'INBOX')
        imap_reopen($this->imap, $folder);

        // Get the email header
        $header = imap_fetchheader($this->imap, $id, FT_UID);

        // Get the email body
        $body = imap_body($this->imap, $id, FT_UID);

        if ($header === false || $body === false) {
            throw new RuntimeException("Unable to fetch e-mail $id in folder $folder: ".imap_last_error());
        }

        // Concatenate the header and body to get the email in EML format
        $email = $header . $body;

        // Return the email in EML format
        return new Eml($email, $this->normalizeFolderName($folder), $id, $this->config['address'] ?? null);
    }

    /**
     * Remove messages from the server.
     *
     * @param array<int|string> $ids
     */
    public function deleteEmails(string $folder, array $ids): array {
        if ($ids === []) {
            return [];
        }

        $trash = $this->trashSetting();
        $target = $trash['enabled'] ? $this->resolveTrashFolder() : null;
        $moving = !is_null($target) && $target !== $folder;

        imap_reopen($this->imap, $folder);
        $removed = [];

        foreach ($ids as $id) {
            // imap_mail_move copies then flags, so a failed move never
            // erases the message.
            $done = $moving
                ? imap_mail_move($this->imap, (string) $id, $target, CP_UID)
                : imap_delete($this->imap, (string) $id, FT_UID);

            if ($done) {
                $removed[] = $id;
                continue;
            }

            $this->log(
                $moving
                    ? "e-mail $id of folder $folder could not be moved to '$target'"
                    : "e-mail $id of folder $folder could not be flagged for deletion, it may already be gone",
                'WARNING'
            );
        }

        if ($removed !== []) {
            imap_expunge($this->imap);
        }

        if ($moving && $removed !== []) {
            $this->log(count($removed)." e-mail(s) of folder $folder moved to '$target'");
        }

        return $removed;
    }

    /**
     * Describe every folder of the account.
     *
     * ext-imap exposes no SPECIAL-USE attribute, so the flags stay empty and
     * the trash can only be recognised by name here.
     *
     * @return array<int,array{name: string, path: string, count: int, flags: array<int,string>}>
     */
    public function describeFolders(): array {
        $described = [];

        foreach (imap_list($this->imap, $this->config['server'], '*') ?: [] as $path) {
            imap_reopen($this->imap, $path);

            $described[] = [
                'name' => $this->normalizeFolderName((string) $path),
                'path' => (string) $path,
                'count' => (int) imap_num_msg($this->imap),
                'flags' => [],
            ];
        }

        return $described;
    }

    public function detectTrashFolder(): ?string {
        try {
            $trash = $this->trashFolder;
            $this->trashFolder = null;
            $found = $this->resolveTrashFolderByName();
            $this->trashFolder = $trash;

            return $found;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Look for a folder carrying one of the usual trash names.
     */
    private function resolveTrashFolderByName(): ?string {
        $known = ['trash', 'deleted', 'deleted items', 'deleted messages', 'corbeille', 'éléments supprimés', 'elements supprimes', 'papierkorb', 'prullenbak', 'cestino', 'papelera'];

        foreach (imap_list($this->imap, $this->config['server'], '*') ?: [] as $path) {
            $name = $this->normalizeFolderName((string) $path);
            $segments = explode('/', $name);

            if (in_array(mb_strtolower((string) end($segments)), $known, true)) {
                return (string) $path;
            }
        }

        return null;
    }

    /**
     * Find the mailbox to move deleted messages into.
     *
     * Unlike the Imap connector, this one cannot read the SPECIAL-USE
     * attributes, so detection rests on the usual names only. Naming the
     * folder in the configuration is the reliable route here.
     *
     * @throws RuntimeException If the trash was requested but cannot be found.
     */
    private function resolveTrashFolder(): string {
        if (!is_null($this->trashFolder)) {
            return $this->trashFolder;
        }

        $folders = imap_list($this->imap, $this->config['server'], '*') ?: [];
        $wanted = $this->trashSetting()['folder'];
        $known = ['trash', 'corbeille', 'deleted items', 'deleted messages', 'papierkorb', 'prullenbak', 'cestino', 'papelera'];

        foreach ($folders as $path) {
            $bare = str_replace($this->config['server'], '', (string) $path);
            $decoded = @mb_convert_encoding($bare, 'UTF-8', 'UTF7-IMAP');
            $decoded = is_string($decoded) && $decoded !== '' ? $decoded : $bare;

            if (is_null($wanted)) {
                $segments = explode('.', $decoded);

                if (in_array(mb_strtolower((string) end($segments)), $known, true)) {
                    return $this->trashFolder = (string) $path;
                }

                continue;
            }

            foreach ([$bare, $decoded, str_replace('.', '/', $decoded)] as $candidate) {
                if (strcasecmp($candidate, $wanted) === 0) {
                    return $this->trashFolder = (string) $path;
                }
            }
        }

        throw new RuntimeException(
            is_null($wanted)
                ? "'trash = 1' was asked for, but no trash folder could be found. Name it explicitly, for instance trash = \"INBOX.Trash\"."
                : "The trash folder '$wanted' does not exist on the server."
        );
    }

    /**
     * Build the IMAP SEARCH criteria string.
     *
     * SENTSINCE/SENTBEFORE compare the Date header rather than the server's
     * internal date, to stay consistent with how the archive names its files.
     */
    private function searchCriteria(): string {
        $range = $this->dateRange();
        $criteria = [];

        if (!is_null($range['since'])) {
            $criteria[] = 'SENTSINCE "'.$this->imapDate($range['since']).'"';
        }

        if (!is_null($range['before'])) {
            $criteria[] = 'SENTBEFORE "'.$this->imapDate($range['before']).'"';
        }

        return $criteria === [] ? 'ALL' : implode(' ', $criteria);
    }

    /**
     * Turn a raw IMAP mailbox name into the folder name used on disk.
     *
     * This is the single normalisation used both to create the directories
     * (getFolders) and to place each message (getEmail). Keeping the two in
     * sync matters: any divergence writes messages to a directory that does
     * not exist, which used to lose every e-mail of the accented folders --
     * "Éléments envoyés", "Indésirables" -- since IMAP names them in
     * modified UTF-7.
     */
    private function normalizeFolderName(string $folder): string {
        // Strip the server prefix first: it holds dots that must not become
        // directory separators.
        $name = str_replace($this->config['server'], '', $folder);

        // IMAP mailbox names are encoded in modified UTF-7 (RFC 3501).
        $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
        if (is_string($decoded) && $decoded !== '') {
            $name = $decoded;
        }

        // Replace dots with slashes in subfolder names, then hand over to the
        // shared safety net.
        return $this->folderName(str_replace('.', '/', $name));
    }

    /**
     * Close the IMAP connection.
     */
    public function __destruct() {
        if ($this->imap) {
            @imap_close($this->imap);
        }
    }
}
