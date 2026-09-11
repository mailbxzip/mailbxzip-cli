<?php

namespace Mailbxzip\Cli\In;

use Mailbxzip\Cli\Contract\DeletableInputInterface;
use Mailbxzip\Cli\Eml;
use Mailbxzip\Cli\Mailbox;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
// Aliased: PHP class names are case-insensitive, so IMAP would clash with
// this very class.
use Webklex\PHPIMAP\IMAP as Protocol;

/**
 * Class Imap
 *
 * Imports e-mails from an IMAP account. This is the default IMAP connector.
 *
 * It speaks the protocol over a socket through webklex/php-imap, and needs no
 * PHP extension: ext-imap was deprecated and unbundled in PHP 8.4, the
 * c-client library behind it having gone unmaintained since 2007. The RFC that
 * removed it names webklex/php-imap as one of two maintained replacements.
 *
 * The previous implementation is kept as ImapLegacy for the installations that
 * still have ext-imap. Both honour the same contract, so a mailbox can be
 * exported with either and the results compared. The "{host:port/imap/ssl}"
 * server string is understood by both, so existing configurations keep
 * working untouched.
 */
class Imap extends AbstractInput implements DeletableInputInterface {

    public const HELP = 'Import e-mails from an imap account (no PHP extension required)';

    /** This source can remove messages once they are archived. */
    public const CAN_DELETE = true;

    /**
     * Longest sequence set sent in one command, in bytes. Well under the
     * usual 8 kB line limit, since the rest of the command and the mailbox
     * name count too, and some servers cap lower.
     */
    private const MAX_SET_LENGTH = 900;

    public const MINIMAL_CONFIG_VAR = [
        'in' => 'Imap',
        'host' => 'imap server hostname, e.g. ssl0.ovh.net',
        'username' => '',
        'password' => ''
    ];

    public const CONFIG_VAR = self::DATE_CONFIG_VAR + self::TRASH_CONFIG_VAR + [
        'port' => 'server port, 993 by default',
        'encryption' => 'ssl (default), tls, starttls or none',
        'validate_cert' => '(1|0) verify the TLS certificate, 1 by default',
        'server' => 'legacy ext-imap string, e.g. {ssl0.ovh.net:993/imap/ssl}, used when host is absent',
    ];

    private $client;

    /** @var array<string,string>|null Raw IMAP path => folder name on disk */
    private $folderNames = null;

    /** @var string|null Resolved trash mailbox, looked up once */
    private $trashFolder = null;

    /**
     * @throws RuntimeException If the connection cannot be established.
     */
    public function __construct($config, Mailbox $mailbox = null) {
        parent::__construct($config, $mailbox);

        try {
            $this->client = (new ClientManager())->make($this->connectionSettings());
            $this->client->connect();
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to open IMAP connection: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Build the client settings, from explicit keys or from the legacy
     * ext-imap server string.
     *
     * @return array<string,mixed>
     * @throws RuntimeException If the host cannot be determined.
     */
    private function connectionSettings(): array {
        $legacy = self::parseLegacyServer($this->config['server'] ?? '');

        $host = $this->config['host'] ?? $legacy['host'];

        if ($host === '') {
            throw new RuntimeException("The configuration needs a 'host' entry (or a legacy 'server' string).");
        }

        $encryption = strtolower((string) ($this->config['encryption'] ?? $legacy['encryption']));

        return [
            'host' => $host,
            'port' => (int) ($this->config['port'] ?? $legacy['port']),
            'encryption' => ($encryption === 'none' || $encryption === '') ? false : $encryption,
            'validate_cert' => !isset($this->config['validate_cert'])
                ? $legacy['validate_cert']
                : (bool) $this->config['validate_cert'],
            'username' => $this->config['username'] ?? '',
            'password' => $this->config['password'] ?? '',
            'protocol' => 'imap',
        ];
    }

    /**
     * Decode an ext-imap mailbox string such as "{ssl0.ovh.net:993/imap/ssl}".
     *
     * @return array{host: string, port: int, encryption: string, validate_cert: bool}
     */
    public static function parseLegacyServer($server): array {
        $settings = ['host' => '', 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => true];

        if (!preg_match('/^\{([^}]+)\}/', trim((string) $server), $matches)) {
            return $settings;
        }

        $parts = explode('/', $matches[1]);
        $address = array_shift($parts);

        if (str_contains($address, ':')) {
            [$settings['host'], $port] = explode(':', $address, 2);
            $settings['port'] = (int) $port;
        } else {
            $settings['host'] = $address;
        }

        foreach ($parts as $flag) {
            switch (strtolower($flag)) {
                case 'ssl':
                    $settings['encryption'] = 'ssl';
                    break;
                case 'tls':
                    $settings['encryption'] = 'tls';
                    break;
                case 'starttls':
                    $settings['encryption'] = 'starttls';
                    break;
                case 'notls':
                    $settings['encryption'] = 'none';
                    break;
                case 'novalidate-cert':
                    $settings['validate_cert'] = false;
                    break;
                case 'validate-cert':
                    $settings['validate_cert'] = true;
                    break;
            }
        }

        return $settings;
    }

    /**
     * @return array{folders: array<string,int>, total: int}
     */
    public function getFolders(): array {
        $structure = [];
        $total = 0;

        foreach ($this->client->getFolders(false) as $folder) {
            $count = (int) ($folder->examine()['exists'] ?? 0);

            $structure[$this->folderNameFor($folder->path)] = $count;
            $total += $count;
        }

        return ['folders' => $structure, 'total' => $total];
    }

    /**
     * The keys are the raw IMAP mailbox paths: getEmail() needs them to
     * select the right mailbox.
     *
     * @return array<string,array<int|string>>
     */
    public function getEmails(): array {
        $emails = [];
        $connection = $this->client->getConnection();

        foreach ($this->client->getFolders(false) as $folder) {
            $connection->selectFolder($folder->path);

            // Ask the server for uids only. Going through the query builder
            // would fetch every header just to read them back, which is far
            // too costly on a large mailbox.
            $response = $connection->search($this->searchCriteria(), Protocol::ST_UID);
            $uids = $response->successful() ? $response->data() : [];

            $emails[$folder->path] = is_array($uids) ? array_values($uids) : [];
        }

        return $emails;
    }

    /**
     * Build the IMAP SEARCH criteria.
     *
     * The date window is pushed down to the server: it answers with the
     * matching uids only, so nothing outside the window is ever downloaded.
     *
     * SENTSINCE/SENTBEFORE compare the Date header, not the server's own
     * internal date. That is deliberate: the archive names its files after
     * that same header, and a mailbox migrated between providers carries an
     * internal date of the migration, which would make every old message look
     * recent.
     *
     * @return array<string>
     */
    private function searchCriteria(): array {
        $range = $this->dateRange();
        $criteria = [];

        if (!is_null($range['since'])) {
            $criteria[] = 'SENTSINCE';
            $criteria[] = $this->imapDate($range['since']);
        }

        if (!is_null($range['before'])) {
            $criteria[] = 'SENTBEFORE';
            $criteria[] = $this->imapDate($range['before']);
        }

        return $criteria === [] ? ['ALL'] : $criteria;
    }

    /**
     * @param int|string $id     The UID of the message.
     * @param string     $folder The raw IMAP mailbox path, as keyed by getEmails().
     */
    public function getEmail($id, $folder): Eml {
        $connection = $this->client->getConnection();
        $connection->selectFolder($folder);

        $header = $this->fetch($connection->headers([(int) $id], 'RFC822', Protocol::ST_UID), $id, $folder, 'header');
        $body = $this->fetch($connection->content([(int) $id], 'RFC822', Protocol::ST_UID), $id, $folder, 'body');

        return new Eml($header.$body, $this->folderNameFor($folder), $id, $this->config['address'] ?? null);
    }

    /**
     * Pull one part of a message out of a protocol response.
     *
     * @throws RuntimeException If the server did not return it.
     */
    private function fetch($response, $id, $folder, string $what): string {
        if (!$response->successful()) {
            throw new RuntimeException("Unable to fetch the $what of e-mail $id in folder $folder.");
        }

        $data = $response->data();

        if (is_array($data)) {
            // Keyed by uid when several were requested, plain list otherwise.
            $data = $data[$id] ?? reset($data);
        }

        if (!is_string($data)) {
            throw new RuntimeException("Unexpected $what returned for e-mail $id in folder $folder.");
        }

        return $data;
    }

    /**
     * Remove messages from the server.
     *
     * Flags the whole batch, then expunges the mailbox once: flagging and
     * expunging message by message would multiply the round trips.
     *
     * @param array<int|string> $ids
     */
    public function deleteEmails(string $folder, array $ids): array {
        if ($ids === []) {
            return [];
        }

        $trash = $this->trashSetting();
        // Resolved before anything is touched: if the trash was asked for but
        // cannot be found, the export must stop rather than quietly fall back
        // to erasing the messages.
        $target = $trash['enabled'] ? $this->resolveTrashFolder() : null;
        $moving = !is_null($target) && $target !== $folder;

        if (!is_null($target) && !$moving) {
            $this->log("folder $folder is the trash itself, its archived messages are erased");
        }

        $connection = $this->client->getConnection();
        $connection->selectFolder($folder);

        // Keep the identifiers as the mailbox knows them, so the caller can
        // tell exactly which ones went.
        $original = [];
        foreach ($ids as $id) {
            $original[(int) $id] = $id;
        }

        $removed = [];
        $expunge = false;

        foreach (self::sequenceChunks(array_keys($original)) as $ranges) {
            $set = self::renderSet($ranges);

            if ($moving && !$this->copyBatch($connection, $set, $folder, $target)) {
                // Never flag what could not be copied: that would be the very
                // data loss the trash is meant to prevent. The batch stays and
                // is retried on the next run.
                continue;
            }

            if (!$this->flagBatch($connection, $ranges, $folder)) {
                continue;
            }

            $expunge = true;

            foreach (self::expandRanges($ranges) as $id) {
                $removed[] = $original[$id];
            }
        }

        if ($expunge) {
            $connection->expunge();
        }

        if ($moving && $removed !== []) {
            $this->log(count($removed)." e-mail(s) of folder $folder moved to '$target'");
        }

        return $removed;
    }

    /**
     * Copy one batch to the trash.
     *
     * @return bool Whether the batch may now be flagged for deletion.
     */
    private function copyBatch($connection, string $set, string $folder, string $target): bool {
        $copied = $connection->copyManyMessages([$set], $target, Protocol::ST_UID);

        if ($copied->successful()) {
            return true;
        }

        $this->log("e-mails $set of folder $folder could not be copied to the trash '$target', they stay where they are", 'ERROR');

        return false;
    }

    /**
     * Flag one batch as deleted, range by range.
     *
     * @param array<int,array{0:int,1:int}> $ranges
     */
    private function flagBatch($connection, array $ranges, string $folder): bool {
        foreach ($ranges as $range) {
            $response = $connection->store(['\\Deleted'], $range[0], $range[1], '+FLAGS', true, Protocol::ST_UID);

            if (!$response->successful()) {
                $this->log(
                    'e-mails '.self::renderRange($range)." of folder $folder could not be flagged for deletion",
                    'ERROR'
                );

                return false;
            }
        }

        return true;
    }

    /**
     * Turn a list of uids into IMAP sequence sets, small enough to send.
     *
     * Two problems are solved at once. Consecutive uids collapse into ranges,
     * which is the usual shape of a full-folder archive: 4568 messages become
     * "1:4568", six bytes instead of twenty-one kilobytes. And whatever
     * remains is split into batches, because an IMAP command line is capped
     * -- commonly at 8 kB, sometimes less. Overshooting it does not return an
     * error, it gets the connection dropped, and every later command on that
     * connection fails too.
     *
     * @param array<int> $ids
     * @return array<int,array<int,array{0:int,1:int}>> Batches of ranges.
     */
    public static function sequenceChunks(array $ids, int $maxLength = self::MAX_SET_LENGTH): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $ranges = [];

        foreach ($ids as $id) {
            $last = $ranges === [] ? null : count($ranges) - 1;

            if (!is_null($last) && $id === $ranges[$last][1] + 1) {
                $ranges[$last][1] = $id;
                continue;
            }

            $ranges[] = [$id, $id];
        }

        $chunks = [];
        $current = [];
        $length = 0;

        foreach ($ranges as $range) {
            $piece = strlen(self::renderRange($range));

            if ($current !== [] && $length + 1 + $piece > $maxLength) {
                $chunks[] = $current;
                $current = [];
                $length = 0;
            }

            $length += ($current === [] ? 0 : 1) + $piece;
            $current[] = $range;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Render a batch of ranges as an IMAP sequence set.
     *
     * @param array<int,array{0:int,1:int}> $ranges
     */
    public static function renderSet(array $ranges): string {
        return implode(',', array_map([self::class, 'renderRange'], $ranges));
    }

    /**
     * @param array{0:int,1:int} $range
     */
    private static function renderRange(array $range): string {
        return $range[0] === $range[1] ? (string) $range[0] : $range[0].':'.$range[1];
    }

    /**
     * @param array<int,array{0:int,1:int}> $ranges
     * @return array<int,int>
     */
    private static function expandRanges(array $ranges): array {
        $ids = [];

        foreach ($ranges as $range) {
            for ($id = $range[0]; $id <= $range[1]; $id++) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Find the mailbox to move deleted messages into.
     *
     * A name given in the configuration wins. Otherwise the server is asked:
     * the SPECIAL-USE \Trash attribute (RFC 6154) first, then the names such a
     * folder usually carries.
     *
     * @throws RuntimeException If the trash was requested but cannot be found.
     */
    private function resolveTrashFolder(): string {
        if (!is_null($this->trashFolder)) {
            return $this->trashFolder;
        }

        $response = $this->client->getConnection()->folders();
        $folders = $response->successful() ? $response->data() : [];

        if (!is_array($folders) || $folders === []) {
            throw new RuntimeException('Unable to list the folders to locate the trash.');
        }

        $wanted = $this->trashSetting()['folder'];

        $found = is_null($wanted)
            ? ($this->folderFlaggedAsTrash($folders) ?? $this->folderNamedAsTrash($folders))
            : $this->folderMatching($folders, $wanted);

        if (is_null($found)) {
            throw new RuntimeException(
                is_null($wanted)
                    ? "'trash = 1' was asked for, but no trash folder could be found on the server. Name it explicitly, for instance trash = \"INBOX.Trash\"."
                    : "The trash folder '$wanted' does not exist on the server."
            );
        }

        return $this->trashFolder = $found;
    }

    /**
     * The folder the server itself declares as its trash.
     *
     * @param array<string,array> $folders
     */
    private function folderFlaggedAsTrash(array $folders): ?string {
        foreach ($folders as $path => $attributes) {
            foreach ($attributes['flags'] ?? [] as $flag) {
                if (strcasecmp(ltrim((string) $flag, '\\'), 'Trash') === 0) {
                    return (string) $path;
                }
            }
        }

        return null;
    }

    /**
     * Fall back to the names a trash folder usually carries.
     *
     * @param array<string,array> $folders
     */
    private function folderNamedAsTrash(array $folders): ?string {
        $known = [
            'trash', 'deleted', 'deleted items', 'deleted messages',
            'corbeille', 'éléments supprimés', 'elements supprimes',
            'messages supprimés', 'messages supprimes',
            'papierkorb', 'gelöschte elemente', 'prullenbak', 'cestino',
            'papelera', 'elementos eliminados', 'kosz', 'skrapan', 'lixeira',
        ];

        foreach ($folders as $path => $attributes) {
            $leaf = $this->lastSegment((string) $path, (string) ($attributes['delimiter'] ?? '.'));

            if (in_array(mb_strtolower($this->decode($leaf)), $known, true)) {
                return (string) $path;
            }
        }

        return null;
    }

    /**
     * Match a folder named in the configuration, tolerating the separator and
     * the encoding the operator happened to use.
     *
     * @param array<string,array> $folders
     */
    private function folderMatching(array $folders, string $wanted): ?string {
        foreach ($folders as $path => $attributes) {
            $delimiter = (string) ($attributes['delimiter'] ?? '.');
            $candidates = [
                (string) $path,
                $this->decode((string) $path),
                str_replace($delimiter, '/', $this->decode((string) $path)),
            ];

            foreach ($candidates as $candidate) {
                if (strcasecmp($candidate, $wanted) === 0) {
                    return (string) $path;
                }
            }
        }

        return null;
    }

    /**
     * Last segment of a mailbox path.
     */
    private function lastSegment(string $path, string $delimiter): string {
        if ($delimiter === '') {
            return $path;
        }

        $segments = explode($delimiter, $path);

        return (string) end($segments);
    }

    /**
     * Decode a mailbox name from modified UTF-7.
     */
    private function decode(string $name): string {
        $decoded = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');

        return (is_string($decoded) && $decoded !== '') ? $decoded : $name;
    }

    /**
     * Turn a raw IMAP mailbox path into the relative path used on disk.
     *
     * The mapping is built once from the folder listing and reused, so
     * getFolders() and getEmail() cannot drift apart -- that divergence is
     * exactly what writes messages into a directory that was never created.
     * It also spares a round trip per message.
     */
    private function folderNameFor(string $path): string {
        if (is_null($this->folderNames)) {
            $this->folderNames = [];

            foreach ($this->client->getFolders(false) as $folder) {
                // webklex exposes the name already decoded from modified
                // UTF-7 in full_name; only the server separator is left.
                $name = $folder->full_name ?: $folder->path;
                $delimiter = $folder->delimiter ?: '.';

                $this->folderNames[$folder->path] = $this->folderName(str_replace($delimiter, '/', $name));
            }
        }

        if (isset($this->folderNames[$path])) {
            return $this->folderNames[$path];
        }

        // Unknown mailbox: decode it ourselves rather than fall back to the
        // raw, still encoded, path.
        $decoded = @mb_convert_encoding($path, 'UTF-8', 'UTF7-IMAP');

        return $this->folderName(str_replace('.', '/', is_string($decoded) && $decoded !== '' ? $decoded : $path));
    }

    /**
     * Close the IMAP connection.
     */
    public function __destruct() {
        try {
            if ($this->client instanceof Client && $this->client->isConnected()) {
                $this->client->disconnect();
            }
        } catch (Throwable $e) {
            // Nothing useful to do while tearing down.
        }
    }
}
