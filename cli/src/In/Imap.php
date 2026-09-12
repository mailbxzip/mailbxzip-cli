<?php

namespace Mailbxzip\Cli\In;

use Mailbxzip\Cli\Contract\DeletableInputInterface;
use Mailbxzip\Cli\Contract\DescribesFoldersInterface;
use Mailbxzip\Cli\Eml;
use Mailbxzip\Cli\TrashUnavailableException;
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
class Imap extends AbstractInput implements DeletableInputInterface, DescribesFoldersInterface {

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

    /** @var array<string,array>|null Raw LIST answer, fetched once */
    private $rawFolders = null;

    /** @var bool|null Whether the server implements MOVE, asked once */
    private $supportsMove = null;

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

        return new Eml(
            $this->rawMessage($connection, $id, $folder),
            $this->folderNameFor($folder),
            $id,
            $this->config['address'] ?? null
        );
    }

    /**
     * Read one message as it stands on the server, header and body.
     *
     * The caller has already selected the folder.
     */
    private function rawMessage($connection, $id, string $folder): string {
        $header = $this->fetch($connection->headers([(int) $id], 'RFC822', Protocol::ST_UID), $id, $folder, 'header');
        $body = $this->fetch($connection->content([(int) $id], 'RFC822', Protocol::ST_UID), $id, $folder, 'body');

        return $header.$body;
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

        // Erase each message then put it back in the trash. The only order a
        // mailbox with no room left will accept, since neither MOVE nor COPY
        // is on offer.
        if ($moving && $this->trashMode() === 'append') {
            return $this->relocateByAppend($connection, $original, $folder, $target);
        }

        // MOVE relocates a message; COPY duplicates it and only then is the
        // original erased. On a mailbox that is short of room -- the very
        // reason one archives -- the copy is refused and nothing moves.
        $relocate = $moving && $this->supportsMove();

        if ($moving) {
            $this->log($relocate
                ? "moving to '".$this->folderNameFor($target)."' with MOVE"
                : "the server has no MOVE, falling back to COPY; it needs room for a second copy of each message");
        }

        $removed = [];
        $expunge = false;

        foreach (self::sequenceChunks(array_keys($original)) as $ranges) {
            $set = self::renderSet($ranges);

            // Both raise on refusal rather than moving on: the trash is the
            // same for every batch, so insisting only piles up identical
            // errors and wears the connection down.
            if ($relocate) {
                $this->moveBatch($connection, $set, $folder, $target);
            } elseif ($moving) {
                $this->copyBatch($connection, $set, $folder, $target);
            }

            // MOVE has already taken the messages out of the folder.
            if (!$relocate && !$this->flagBatch($connection, $ranges, $folder)) {
                continue;
            }

            $expunge = $expunge || !$relocate;

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
     * Relocate messages by erasing them first and putting them back after.
     *
     * MOVE and COPY both need the server to hold the message twice, if only
     * for an instant, which a mailbox at its quota refuses. Reversing the
     * order works because erasing is what frees the room the append needs.
     *
     * The price is a window, one message wide, where the e-mail is gone from
     * the server and not yet in the trash. It is never lost: the purge only
     * runs once the zip archive exists, so the message is on disk throughout.
     * An append that fails stops everything and names the message concerned.
     *
     * @param array<int,int|string> $original uid => identifier as given
     * @return array<int|string> The identifiers actually relocated.
     */
    private function relocateByAppend($connection, array $original, string $folder, string $target): array {
        $name = $this->folderNameFor($target);
        $total = count($original);

        $this->log(
            "moving $total e-mail(s) of folder ".$this->folderNameFor($folder)." to '$name' one at a time: "
            .'each is read, erased, then put back. Slow, but the only order a mailbox out of room accepts.'
        );

        $removed = [];

        foreach ($original as $uid => $id) {
            try {
                $raw = $this->rawMessage($connection, $uid, $folder);
            } catch (Throwable $e) {
                $this->log("e-mail $uid of folder $folder could not be read, it stays where it is: ".$e->getMessage(), 'ERROR');
                continue;
            }

            if (!$this->eraseOne($connection, $uid, $folder)) {
                continue;
            }

            // Past this point the message is off the server; only the archive
            // holds it until the append lands.
            $this->appendOne($connection, $target, $raw, $uid, $folder);

            $removed[] = $id;

            if (count($removed) % 100 === 0) {
                $this->log('... '.count($removed)."/$total moved to '$name'");
            }
        }

        return $removed;
    }

    /**
     * Flag one message deleted and expunge, which is what frees the room.
     *
     * @return bool Whether the message really went.
     */
    private function eraseOne($connection, $uid, string $folder): bool {
        try {
            $flagged = $connection->store(['\\Deleted'], (int) $uid, (int) $uid, '+FLAGS', true, Protocol::ST_UID);

            if (!$flagged->successful()) {
                $this->log("e-mail $uid of folder $folder could not be flagged for deletion, it stays where it is", 'ERROR');

                return false;
            }

            $connection->expunge();

            return true;
        } catch (Throwable $e) {
            $this->log("e-mail $uid of folder $folder could not be erased, it stays where it is: ".$e->getMessage(), 'ERROR');

            return false;
        }
    }

    /**
     * Put a message back into the trash.
     *
     * @throws TrashUnavailableException If it will not go, since the message
     *         is already off the server and every later one would fare the
     *         same.
     */
    private function appendOne($connection, string $target, string $raw, $uid, string $folder): void {
        try {
            // Marked as read: thousands of unread messages landing in the
            // trash would drown the mailbox in notifications.
            $appended = $connection->appendMessage($target, $raw, ['\\Seen'], $this->internalDate($raw));

            if ($appended->successful()) {
                return;
            }

            $detail = $this->serverSaid($appended);
        } catch (Throwable $e) {
            $detail = trim($e->getMessage());
        }

        throw new TrashUnavailableException(
            "e-mail $uid of folder '".$this->folderNameFor($folder)."' was erased from the server but could NOT be put "
            ."into the trash '".$this->folderNameFor($target)."'. It survives in the zip archive, and only there. "
            .'Server said: '.($detail === '' ? '(no detail)' : $detail).'. The purge stops here.'
        );
    }

    /**
     * The date to give the message in the trash, taken from its own headers
     * so it does not surface as if it had just arrived.
     */
    private function internalDate(string $raw): ?string {
        if (!preg_match('/^Date:\s*(.+)$/mi', $raw, $matches)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($matches[1])))->format('d-M-Y H:i:s O');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Whether the server implements MOVE (RFC 6851).
     */
    private function supportsMove(): bool {
        if (!is_null($this->supportsMove)) {
            return $this->supportsMove;
        }

        $capabilities = [];

        try {
            $response = $this->client->getConnection()->getCapabilities();

            if ($response->successful()) {
                // array_walk_recursive takes its array by reference, so the
                // cast has to land in a variable first.
                $data = (array) $response->data();

                array_walk_recursive($data, function ($value) use (&$capabilities) {
                    $capabilities[] = strtoupper(trim((string) $value));
                });
            }
        } catch (Throwable $e) {
            // An unreadable answer is not a reason to fail; COPY still works
            // wherever there is room.
        }

        return $this->supportsMove = in_array('MOVE', $capabilities, true);
    }

    /**
     * Move one batch to the trash, in a single command and without ever
     * holding two copies of a message.
     *
     * @throws TrashUnavailableException If the server turns the move down.
     */
    private function moveBatch($connection, string $set, string $folder, string $target): void {
        try {
            $moved = $connection->moveManyMessages([$set], $target, Protocol::ST_UID);

            if ($moved->successful()) {
                return;
            }

            $detail = $this->serverSaid($moved);
        } catch (Throwable $e) {
            $detail = trim($e->getMessage());
        }

        throw new TrashUnavailableException($this->refusalMessage('move', $set, $folder, $target, $detail));
    }

    /**
     * Copy one batch to the trash.
     *
     * @throws TrashUnavailableException If the server turns the copy down.
     */
    private function copyBatch($connection, string $set, string $folder, string $target): void {
        // A refusal arrives as an exception on some paths and as a failed
        // response on others; both mean the same thing here.
        try {
            $copied = $connection->copyManyMessages([$set], $target, Protocol::ST_UID);

            if ($copied->successful()) {
                return;
            }

            $detail = $this->serverSaid($copied);
        } catch (Throwable $e) {
            $detail = trim($e->getMessage());
        }

        throw new TrashUnavailableException($this->refusalMessage('copy', $set, $folder, $target, $detail));
    }

    /**
     * Word a refusal so the cause can be acted on.
     *
     * A bare "NO ... failed" is what most servers answer when the account is
     * out of room, so the possibility is raised rather than left to be
     * guessed at.
     */
    private function refusalMessage(string $verb, string $set, string $folder, string $target, string $detail): string {
        $detail = ($detail === '') ? '(no detail)' : $detail;

        $message = "the trash '".$this->folderNameFor($target)."' refused a $verb of ".(substr_count($set, ',') + 1)
            ." batch(es) from folder '".$this->folderNameFor($folder)."', nothing was deleted. Server said: $detail.";

        if ($verb === 'copy') {
            $message .= ' A copy needs room for a second instance of every message, so a mailbox at its quota refuses it;'
                .' a server offering MOVE would not need that room.';
        }

        return $message.' Check the folder with "php cli.php folders <config>", and name another with trash = "...".';
    }

    /**
     * The server's own words for a failed command.
     *
     * webklex reduces every refusal to "NO UID COPY failed", which says
     * nothing about the cause. The raw lines usually carry it: [TRYCREATE]
     * for a missing mailbox, [OVERQUOTA] for a full one, a permission
     * complaint for a read-only one.
     */
    private function serverSaid($response): string {
        $lines = [];

        foreach ((array) $response->getResponse() as $line) {
            $line = trim(is_array($line) ? implode(' ', array_map('strval', $line)) : (string) $line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? '(no detail)' : implode(' | ', array_slice($lines, -3));
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

        $folders = $this->rawFolders();

        if ($folders === []) {
            throw new RuntimeException('Unable to list the folders to locate the trash.');
        }

        $wanted = $this->trashSetting()['folder'];

        $found = is_null($wanted)
            ? $this->detectTrashFolder()
            : $this->folderMatching($folders, $wanted);

        if (is_null($found)) {
            // Name the folders rather than leave the operator guessing: they
            // travel encoded, so they cannot be worked out from the outside.
            $available = $this->readableFolderNames($folders);

            throw new RuntimeException(
                (is_null($wanted)
                    ? "'trash = 1' was asked for, but no trash folder could be found on the server."
                    : "The trash folder '$wanted' does not exist on the server.")
                ." Name one of: ".$available.". Run \"php cli.php folders <config>\" to see them all."
            );
        }

        // A container that holds only sub-folders cannot store a message, and
        // every copy into it is refused. Say so now rather than once per
        // folder, in the server's own words.
        if (!$this->isSelectable($folders[$found] ?? [])) {
            throw new RuntimeException(
                "The trash folder '".$this->folderNameFor($found)."' is marked \\Noselect by the server: it cannot hold messages. "
                ."Name a selectable one instead, among: ".$this->readableFolderNames($folders)."."
            );
        }

        // Which folder was picked is the first thing to know when a copy is
        // refused, and nothing else reveals it. Say too where the choice came
        // from: a name match is a guess, and a wrong guess looks exactly like
        // a broken server.
        $origin = is_null($wanted)
            ? (($this->folderFlaggedAsTrash($folders) === $found)
                ? 'the server declares it as its trash'
                : 'GUESSED FROM ITS NAME, the server declares no trash; check it is the right one')
            : 'named in the configuration';

        $this->log("trash resolved to '".$this->folderNameFor($found)."' (".$found.") -- ".$origin);

        return $this->trashFolder = $found;
    }

    /**
     * Whether a folder can actually hold messages.
     *
     * @param array $attributes The LIST attributes of the folder.
     */
    private function isSelectable(array $attributes): bool {
        foreach ($attributes['flags'] ?? [] as $flag) {
            if (strcasecmp(ltrim((string) $flag, '\\'), 'Noselect') === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The raw LIST answer, fetched once per connection.
     *
     * @return array<string,array>
     */
    private function rawFolders(): array {
        if (is_null($this->rawFolders)) {
            $response = $this->client->getConnection()->folders();
            $folders = $response->successful() ? $response->data() : [];

            $this->rawFolders = is_array($folders) ? $folders : [];
        }

        return $this->rawFolders;
    }

    /**
     * A short, readable list of the folders, for an error message.
     *
     * @param array<string,array> $folders
     */
    private function readableFolderNames(array $folders, int $limit = 12): string {
        $names = [];

        foreach (array_keys($folders) as $path) {
            $names[] = '"'.$this->folderNameFor((string) $path).'"';
        }

        if (count($names) > $limit) {
            $names = array_slice($names, 0, $limit);
            $names[] = '...';
        }

        return implode(', ', $names);
    }

    /**
     * Describe every folder: name on disk, raw path, volume and attributes.
     *
     * @return array<int,array{name: string, path: string, count: int, flags: array<int,string>}>
     */
    public function describeFolders(): array {
        $raw = $this->rawFolders();
        $described = [];

        foreach ($this->client->getFolders(false) as $folder) {
            $flags = [];

            foreach ($raw[$folder->path]['flags'] ?? [] as $flag) {
                $flags[] = ltrim((string) $flag, '\\');
            }

            $described[] = [
                'name' => $this->folderNameFor($folder->path),
                'path' => (string) $folder->path,
                'count' => (int) ($folder->examine()['exists'] ?? 0),
                'flags' => $flags,
            ];
        }

        return $described;
    }

    /**
     * The folder this connector would use as trash, left to itself.
     */
    public function detectTrashFolder(): ?string {
        $folders = $this->rawFolders();

        return $this->folderFlaggedAsTrash($folders) ?? $this->folderNamedAsTrash($folders);
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
