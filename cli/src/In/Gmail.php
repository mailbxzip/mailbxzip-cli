<?php

namespace Mailbxzip\Cli\In;

use Google\Client;
use Google\Service\Gmail as GmailService;
use Mailbxzip\Cli\Contract\DeletableInputInterface;
use Mailbxzip\Cli\Contract\DescribesFoldersInterface;
use Mailbxzip\Cli\Eml;
use Mailbxzip\Cli\Mailbox;
use RuntimeException;
use Throwable;

/**
 * Class Gmail
 *
 * Imports e-mails through the Gmail API rather than IMAP.
 *
 * Gmail has labels, not folders, and a message carries several at once. Taken
 * literally that would archive the same e-mail once per label, plus once more
 * for "All Mail" which holds every message anyway. So by default only All Mail
 * is read: every message exactly once, in a single folder. Name labels in the
 * 'folders' entry to get the label tree instead, knowing a message will then
 * appear under each of its labels.
 */
class Gmail extends AbstractInput implements DeletableInputInterface, DescribesFoldersInterface {

    public const HELP = 'Import e-mails from a Gmail account through the Gmail API (no IMAP, no password)';

    public const MINIMAL_CONFIG_VAR = [
        'in' => 'Gmail',
        'client_id' => 'OAuth client id of your Google Cloud project',
        'client_secret' => 'OAuth client secret',
        'refresh_token' => 'obtained once with: php cli.php gmail-auth <config>',
    ];

    public const CONFIG_VAR = self::FOLDER_CONFIG_VAR + self::DATE_CONFIG_VAR + self::SENDER_CONFIG_VAR + [
        'user' => 'mailbox to read, "me" by default',
    ];

    /** This source can remove messages once they are archived. */
    public const CAN_DELETE = true;

    /** The label holding every message, read on its own by default. */
    private const ALL_MAIL = '[Gmail]/All Mail';

    /** Messages asked for per page; the API caps a page at 500. */
    private const PAGE = 500;

    /** @var GmailService */
    private $service;

    /** @var string The mailbox being read */
    private $user;

    /** @var array<string,string>|null Folder name => label id */
    private $labels = null;

    /**
     * @throws RuntimeException If the account cannot be reached.
     */
    public function __construct($config, Mailbox $mailbox = null) {
        parent::__construct($config, $mailbox);

        $this->user = trim((string) ($this->config['user'] ?? 'me')) ?: 'me';

        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
            if (empty($this->config[$key])) {
                throw new RuntimeException(
                    "The Gmail connector needs a '$key' entry. Run \"php cli.php gmail-auth <config>\" to obtain a refresh token."
                );
            }
        }

        try {
            $this->service = new GmailService(self::client($this->config));
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to reach the Gmail account: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Build an authenticated client.
     *
     * Shared with the command that obtains the refresh token, so both agree
     * on the scopes and on the retry policy.
     *
     * @param array $config
     */
    public static function client(array $config): Client {
        $client = new Client();
        $client->setApplicationName('mailbxzip');
        $client->setClientId((string) ($config['client_id'] ?? ''));
        $client->setClientSecret((string) ($config['client_secret'] ?? ''));
        $client->setScopes(self::scopes());
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        // The API allows 6000 quota units a minute and answers 429 beyond
        // that; reading a large mailbox reaches it, so let the library wait
        // and try again rather than lose the run.
        $client->setConfig('retry', ['retries' => 5]);

        if (!empty($config['refresh_token'])) {
            $client->refreshToken((string) $config['refresh_token']);
        }

        return $client;
    }

    /**
     * @return array<int,string>
     */
    public static function scopes(): array {
        // readonly cannot delete, so ask for modify only when the
        // configuration says messages are to be removed.
        return [GmailService::GMAIL_MODIFY];
    }

    /**
     * @return array{folders: array<string,int>, total: int}
     */
    public function getFolders(): array {
        $structure = [];
        $total = 0;
        $available = [];

        foreach ($this->labels() as $name => $id) {
            $available[] = $name;

            if (!$this->keeps($name)) {
                continue;
            }

            $count = $this->countIn($id);

            $structure[$name] = $count;
            $total += $count;
        }

        $this->warnUnknownFolders($available);

        return ['folders' => $structure, 'total' => $total];
    }

    /**
     * The message identifiers to export, per label.
     *
     * @return array<string,array<int,string>>
     */
    public function getEmails(): array {
        $emails = [];

        foreach ($this->labels() as $name => $id) {
            if (!$this->keeps($name)) {
                continue;
            }

            $emails[$name] = $this->idsIn($id);
        }

        return $emails;
    }

    /**
     * @param string $id     The Gmail message id.
     * @param string $folder The label name, as keyed by getEmails().
     */
    public function getEmail($id, $folder): Eml {
        try {
            $message = $this->service->users_messages->get($this->user, (string) $id, ['format' => 'raw']);
        } catch (Throwable $e) {
            throw new RuntimeException("Unable to fetch message $id: ".$e->getMessage(), 0, $e);
        }

        $raw = $this->decode((string) $message->getRaw());

        if ($raw === '') {
            throw new RuntimeException("Message $id came back empty.");
        }

        return new Eml($raw, $this->folderName($folder), $id, $this->config['address'] ?? null);
    }

    /**
     * Remove archived messages from the account.
     *
     * Gmail draws the distinction itself, so there is no flag-then-expunge
     * dance and no room needed for a second copy: trash moves the message,
     * delete destroys it.
     *
     * @param array<int|string> $ids
     * @return array<int|string> Those actually removed.
     */
    public function deleteEmails(string $folder, array $ids): array {
        $toTrash = $this->trashSetting()['enabled'];
        $removed = [];

        $this->log($toTrash
            ? 'moving '.count($ids)." archived message(s) of '$folder' to the Gmail trash"
            : 'deleting '.count($ids)." archived message(s) of '$folder' for good");

        foreach ($ids as $id) {
            try {
                if ($toTrash) {
                    $this->service->users_messages->trash($this->user, (string) $id);
                } else {
                    $this->service->users_messages->delete($this->user, (string) $id);
                }

                $removed[] = $id;
            } catch (Throwable $e) {
                // A message already gone is not a failure: an earlier run may
                // have taken it.
                $this->log("message $id could not be removed: ".$e->getMessage(), 'WARNING');
            }
        }

        return $removed;
    }

    /**
     * @return array<int,array{name: string, path: string, count: int, flags: array<int,string>}>
     */
    public function describeFolders(): array {
        $described = [];

        foreach ($this->labels() as $name => $id) {
            $described[] = [
                'name' => $name,
                'path' => $id,
                'count' => $this->countIn($id),
                'flags' => ($name === '[Gmail]/Trash') ? ['Trash'] : [],
            ];
        }

        return $described;
    }

    public function detectTrashFolder(): ?string {
        return isset($this->labels()['[Gmail]/Trash']) ? '[Gmail]/Trash' : null;
    }

    /**
     * Whether a label is to be exported.
     *
     * Without an explicit 'folders' entry only All Mail is taken, so every
     * message is archived exactly once instead of once per label it carries.
     */
    private function keeps(string $name): bool {
        if ($this->folderFilter() === []) {
            return $name === self::ALL_MAIL;
        }

        return $this->keepsFolder($name);
    }

    /**
     * The labels of the account, named the way folders are elsewhere.
     *
     * @return array<string,string> Folder name => label id.
     */
    private function labels(): array {
        if (!is_null($this->labels)) {
            return $this->labels;
        }

        try {
            $response = $this->service->users_labels->listUsersLabels($this->user);
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to list the Gmail labels: '.$e->getMessage(), 0, $e);
        }

        $labels = [];

        foreach ($response->getLabels() as $label) {
            $labels[$this->folderName(self::labelName($label))] = (string) $label->getId();
        }

        ksort($labels);

        return $this->labels = $labels;
    }

    /**
     * The folder name a label takes in the archive.
     *
     * Gmail's own labels answer as bare identifiers -- INBOX, SENT, TRASH --
     * and are given the "[Gmail]/" prefix IMAP shows them under, so a Gmail
     * archive reads like any other and the trash is recognisable.
     *
     * @param object $label
     */
    private static function labelName($label): string {
        $id = (string) $label->getId();
        $name = (string) $label->getName();

        $system = [
            'INBOX' => 'INBOX',
            'SENT' => '[Gmail]/Sent Mail',
            'DRAFT' => '[Gmail]/Drafts',
            'TRASH' => '[Gmail]/Trash',
            'SPAM' => '[Gmail]/Spam',
            'STARRED' => '[Gmail]/Starred',
            'IMPORTANT' => '[Gmail]/Important',
        ];

        return $system[$id] ?? $name;
    }

    /**
     * How many messages a label holds, once the filters are applied.
     */
    private function countIn(string $labelId): int {
        return count($this->idsIn($labelId));
    }

    /**
     * Every message id of a label, the filters pushed to the server.
     *
     * @return array<int,string>
     */
    private function idsIn(string $labelId): array {
        $ids = [];
        $page = null;
        $query = $this->query();

        do {
            $parameters = ['maxResults' => self::PAGE];

            // All Mail is not a label: it is simply every message, which is
            // what a search without a label returns.
            if ($labelId !== '') {
                $parameters['labelIds'] = [$labelId];
            }

            if ($query !== '') {
                $parameters['q'] = $query;
            }

            if (!is_null($page)) {
                $parameters['pageToken'] = $page;
            }

            try {
                $response = $this->service->users_messages->listUsersMessages($this->user, $parameters);
            } catch (Throwable $e) {
                throw new RuntimeException('Unable to list the Gmail messages: '.$e->getMessage(), 0, $e);
            }

            foreach ($response->getMessages() ?: [] as $message) {
                $ids[] = (string) $message->getId();
            }

            $page = $response->getNextPageToken();
        } while (!is_null($page) && $page !== '');

        return $this->narrow($ids);
    }

    /**
     * The date and sender filters, written as a Gmail search.
     *
     * The operators line up with what the configuration already expresses, so
     * the messages left out are never fetched -- the same bargain the IMAP
     * connector strikes with SEARCH.
     */
    public function query(): string {
        $terms = [];
        $range = $this->dateRange();

        // Deliberately wider than asked for, by a day on each side. Gmail
        // reads a bare date as midnight Pacific time and nowhere says whether
        // its bounds are inclusive; given in seconds the timezone doubt goes,
        // and the margin covers the rest. Narrowing back to the exact window
        // is done here, on the Date header, so this connector agrees with the
        // IMAP one to the day.
        if (!is_null($range['since'])) {
            $terms[] = 'after:'.$range['since']->modify('-1 day')->getTimestamp();
        }

        if (!is_null($range['before'])) {
            $terms[] = 'before:'.$range['before']->modify('+1 day')->getTimestamp();
        }

        $senders = [];

        foreach ($this->senders() as $sender) {
            $senders[] = 'from:'.self::quote($sender);
        }

        if ($senders !== []) {
            $terms[] = (count($senders) === 1) ? $senders[0] : '{'.implode(' ', $senders).'}';
        }

        return implode(' ', $terms);
    }

    /**
     * Keep only the messages the filters really call for.
     *
     * Gmail's own date search works on when a message arrived, whereas the
     * filters here speak of when it was sent -- the header the archive names
     * its files after. The two part company on anything forwarded or migrated,
     * so the header is read and judged rather than trusted to the query.
     *
     * Costs five quota units a message against the twenty a full fetch takes,
     * and only when a filter is set at all.
     *
     * @param array<int,string> $ids
     * @return array<int,string>
     */
    private function narrow(array $ids): array {
        if (!$this->hasDateFilter() && !$this->hasSenderFilter()) {
            return $ids;
        }

        $kept = [];

        foreach ($ids as $id) {
            $headers = $this->headersOf($id);

            if ($this->hasSenderFilter() && !$this->matchesSender($headers['from'] ?? '')) {
                continue;
            }

            if ($this->hasDateFilter() && !$this->withinDateRange($headers['date'] ?? '')) {
                continue;
            }

            $kept[] = $id;
        }

        $dropped = count($ids) - count($kept);

        if ($dropped > 0) {
            $this->log("$dropped message(s) returned by the Gmail search fall outside the filters and are left out");
        }

        return $kept;
    }

    /**
     * The two headers the filters judge on, without pulling the message down.
     *
     * @return array{from?: string, date?: string}
     */
    private function headersOf(string $id): array {
        try {
            $message = $this->service->users_messages->get($this->user, $id, [
                'format' => 'metadata',
                'metadataHeaders' => ['From', 'Date'],
            ]);
        } catch (Throwable $e) {
            $this->log("headers of message $id could not be read, it is left out: ".$e->getMessage(), 'WARNING');

            return [];
        }

        $headers = [];

        foreach ($message->getPayload()->getHeaders() ?: [] as $header) {
            $headers[strtolower((string) $header->getName())] = (string) $header->getValue();
        }

        return $headers;
    }

    /**
     * Quote a search term when it holds anything but a bare address.
     */
    private static function quote(string $term): string {
        return preg_match('/^[A-Za-z0-9@._-]+$/', $term) ? $term : '"'.str_replace('"', '', $term).'"';
    }

    /**
     * Gmail hands the message back base64url encoded.
     */
    private function decode(string $raw): string {
        $decoded = base64_decode(strtr($raw, '-_', '+/'), false);

        return is_string($decoded) ? $decoded : '';
    }
}
