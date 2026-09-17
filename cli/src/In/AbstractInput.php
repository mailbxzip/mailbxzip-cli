<?php

namespace Mailbxzip\Cli\In;

use DateTimeImmutable;
use Mailbxzip\Cli\Contract\InputHandlerInterface;
use Mailbxzip\Cli\Mailbox;
use RuntimeException;
use Throwable;

/**
 * Class AbstractInput
 *
 * Shared plumbing for the input connectors: configuration access, logging,
 * lifecycle hooks, and the folder-name safety net every source must go
 * through.
 *
 * A concrete connector implements getFolders(), getEmails() and getEmail().
 */
abstract class AbstractInput implements InputHandlerInterface {

    /**
     * Configuration entries every input connector understands.
     *
     * A connector should merge these into its own CONFIG_VAR so they show up
     * in the generated help.
     */
    public const DATE_CONFIG_VAR = [
        'since' => 'only export messages sent on or after this date (YYYY-MM-DD)',
        'before' => 'only export messages sent strictly before this date (YYYY-MM-DD)',
    ];

    public const FOLDER_CONFIG_VAR = [
        'folders' => 'only export these folders, by the name "php cli.php folders" shows; several separated by commas; naming a folder takes its sub-folders too',
    ];

    public const SENDER_CONFIG_VAR = [
        'from' => 'only export messages whose From header contains this, e.g. "bulletin@exemple.fr" or "Alice"; several separated by commas, any one of which is enough',
    ];

    /**
     * Configuration entry understood by the connectors able to delete.
     */
    public const TRASH_CONFIG_VAR = [
        'trash' => 'with delete = 1: "1" moves the archived messages to the server trash instead of erasing them, or name the folder to move them to',
        'trash_mode' => '"auto" (default) relocates with MOVE, or COPY when the server has no MOVE; "append" erases each message then puts it back in the trash, the only order a mailbox out of room accepts',
    ];

    protected $config;
    protected $mailbox;

    /** @var array{since: ?DateTimeImmutable, before: ?DateTimeImmutable}|null */
    private $dateRange = null;

    /** @var array<int,string>|null Senders the export is restricted to */
    private $senders = null;

    /** @var array<int,string>|null Folders the export is restricted to */
    private $folderFilter = null;

    /** @var array<string,bool> Filter entries that matched at least one folder */
    private $matchedFolders = [];

    /** @var bool Whether the unknown folders have already been reported */
    private $reportedUnknownFolders = false;

    /**
     * @param array        $config  Configuration array.
     * @param Mailbox|null $mailbox Owning mailbox, when running under one.
     */
    public function __construct($config, Mailbox $mailbox = null) {
        $this->config = $config;
        $this->mailbox = $mailbox;

        // Checked now rather than at purge time: a typo would otherwise only
        // surface once the whole archive had been built.
        $this->trashMode();
    }

    /**
     * Get the configuration.
     *
     * The mailbox is preferred over the local copy: keys computed after the
     * connector was built only exist there.
     *
     * @return array The configuration array.
     */
    public function getConfig() {
        return (!is_null($this->mailbox)) ? $this->mailbox->getConfig() : $this->config;
    }

    /**
     * Report something, when running under a mailbox.
     */
    protected function log($message, $level = 'INFO') {
        if (!is_null($this->mailbox)) {
            $this->mailbox->log($message, $level);
        }
    }

    /**
     * The date window the export is restricted to, from the 'since' and
     * 'before' configuration entries.
     *
     * Both bounds are day-granular, because that is all the IMAP protocol
     * compares: 'since' is inclusive, 'before' is exclusive.
     *
     * @return array{since: ?DateTimeImmutable, before: ?DateTimeImmutable}
     * @throws RuntimeException If a date is unreadable or the window is empty.
     */
    protected function dateRange(): array {
        if (!is_null($this->dateRange)) {
            return $this->dateRange;
        }

        $since = $this->parseConfigDate('since');
        $before = $this->parseConfigDate('before');

        if (!is_null($since) && !is_null($before) && $since->format('Y-m-d') >= $before->format('Y-m-d')) {
            throw new RuntimeException(
                "The 'since' date (".$since->format('Y-m-d').") must be earlier than the 'before' date "
                ."(".$before->format('Y-m-d')."), which is exclusive."
            );
        }

        return $this->dateRange = ['since' => $since, 'before' => $before];
    }

    /**
     * Whether the export is restricted at all.
     */
    protected function hasDateFilter(): bool {
        $range = $this->dateRange();

        return !is_null($range['since']) || !is_null($range['before']);
    }

    /**
     * How the source should get rid of the archived messages.
     *
     * @return array{enabled: bool, folder: ?string} enabled false erases them;
     *         a null folder asks the connector to find the trash itself.
     */
    protected function trashSetting(): array {
        $value = trim((string) ($this->config['trash'] ?? ''));

        if ($value === '' || $value === '0') {
            return ['enabled' => false, 'folder' => null];
        }

        return ['enabled' => true, 'folder' => ($value === '1') ? null : $value];
    }

    /**
     * The folders the export is restricted to.
     *
     * @return array<int,string> Empty when nothing is asked for.
     */
    protected function folderFilter(): array {
        if (!is_null($this->folderFilter)) {
            return $this->folderFilter;
        }

        $wanted = [];

        foreach (explode(',', (string) ($this->config['folders'] ?? '')) as $folder) {
            $folder = trim($folder, " \t\n\r\0\x0B/");

            if ($folder !== '') {
                $wanted[] = $folder;
            }
        }

        return $this->folderFilter = $wanted;
    }

    /**
     * Whether a folder is one of those asked for.
     *
     * Naming a folder takes its sub-folders with it, which is what an
     * operator writing "INBOX" almost always means. Matching ignores case,
     * like the rest of the filters.
     */
    protected function keepsFolder(string $name): bool {
        $wanted = $this->folderFilter();

        if ($wanted === []) {
            return true;
        }

        $name = trim($name, '/');

        foreach ($wanted as $folder) {
            if (strcasecmp($name, $folder) === 0 || stripos($name, $folder.'/') === 0) {
                // Remembered so an entry that never matches anything can be
                // pointed out afterwards.
                $this->matchedFolders[$folder] = true;

                return true;
            }
        }

        return false;
    }

    /**
     * Point out the folders that were asked for but do not exist.
     *
     * A typo in 'folders' otherwise archives nothing at all, in silence, and
     * looks exactly like an empty mailbox. Called once the connector has seen
     * every folder it has.
     *
     * @param array<int,string> $available Every folder name the source holds.
     */
    protected function warnUnknownFolders(array $available): void {
        if ($this->reportedUnknownFolders || $this->folderFilter() === []) {
            return;
        }

        $this->reportedUnknownFolders = true;

        $unknown = array_values(array_diff($this->folderFilter(), array_keys($this->matchedFolders)));

        if ($unknown !== []) {
            $this->log(
                'no folder matches '.$this->quoted($unknown).'. The source holds: '.$this->quoted($available, 12)
                .'. Run "php cli.php folders <config>" to see them all.',
                'WARNING'
            );
        }

        if ($this->matchedFolders === []) {
            $this->log(
                "the 'folders' entry matches nothing at all, so this export would archive no message.",
                'ERROR'
            );
        }
    }

    /**
     * Render a list of names for a message, shortened when it runs long.
     *
     * @param array<int,string> $names
     */
    private function quoted(array $names, int $limit = 0): string {
        if ($limit > 0 && count($names) > $limit) {
            $names = array_slice($names, 0, $limit);
            $names[] = '...';
        }

        return implode(', ', array_map(function ($name) {
            return ($name === '...') ? $name : '"'.$name.'"';
        }, $names));
    }

    /**
     * The senders the export is restricted to.
     *
     * Independent of the date window: a sender may be archived across every
     * year at once, or a single year of one sender, by setting both.
     *
     * @return array<int,string> Empty when nothing is asked for.
     */
    protected function senders(): array {
        if (!is_null($this->senders)) {
            return $this->senders;
        }

        $raw = (string) ($this->config['from'] ?? '');
        $senders = [];

        foreach (explode(',', $raw) as $sender) {
            $sender = trim($sender);

            if ($sender !== '') {
                $senders[] = $sender;
            }
        }

        return $this->senders = $senders;
    }

    /**
     * Whether the export is restricted to given senders.
     */
    protected function hasSenderFilter(): bool {
        return $this->senders() !== [];
    }

    /**
     * Decide whether a raw From header names one of the wanted senders.
     *
     * A plain, case-insensitive substring match, the same thing IMAP's FROM
     * criterion does, so a connector filtering on its own agrees with one
     * letting the server do it. It therefore matches a display name as
     * readily as an address.
     */
    protected function matchesSender($rawFrom): bool {
        $senders = $this->senders();

        if ($senders === []) {
            return true;
        }

        $from = trim((string) $rawFrom);

        if ($from === '') {
            $this->log('a message carries no readable From header and falls outside the sender filter', 'WARNING');

            return false;
        }

        foreach ($senders as $sender) {
            if (mb_stripos($from, $sender) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * How the messages should reach the trash.
     *
     * @return string 'auto' or 'append'.
     * @throws RuntimeException On an unknown value.
     */
    protected function trashMode(): string {
        $mode = strtolower(trim((string) ($this->config['trash_mode'] ?? 'auto')));

        if ($mode === '') {
            return 'auto';
        }

        if (!in_array($mode, ['auto', 'append'], true)) {
            throw new RuntimeException("Unknown trash_mode '$mode'. Use \"auto\" or \"append\".");
        }

        return $mode;
    }

    /**
     * Format a bound the way IMAP expects it: 01-Jan-2024.
     */
    protected function imapDate(DateTimeImmutable $date): string {
        return $date->format('d-M-Y');
    }

    /**
     * Decide whether a raw Date header falls inside the window.
     *
     * For connectors that cannot push the filter down to their source and
     * have to sort messages out themselves.
     */
    protected function withinDateRange($rawDate): bool {
        $range = $this->dateRange();

        if (is_null($range['since']) && is_null($range['before'])) {
            return true;
        }

        $value = trim((string) $rawDate);

        if ($value === '') {
            // Nothing to compare against: a filtered export cannot vouch for
            // this message, so it stays out -- but never silently.
            $this->log('a message carries no readable Date header and falls outside the date filter', 'WARNING');

            return false;
        }

        try {
            $date = (new DateTimeImmutable($value))->setTime(0, 0, 0);
        } catch (Throwable $e) {
            $this->log("unreadable Date header '$value', the message falls outside the date filter", 'WARNING');

            return false;
        }

        // Compare calendar days, not instants. IMAP disregards the time and
        // the offset, so "1 Feb 2024 10:00 +0100" is simply the 1st of
        // February; comparing timestamps would push it before a UTC midnight
        // bound and silently drop a day's worth of messages at each edge.
        $day = $date->format('Y-m-d');

        if (!is_null($range['since']) && $day < $range['since']->format('Y-m-d')) {
            return false;
        }

        return is_null($range['before']) || $day < $range['before']->format('Y-m-d');
    }

    /**
     * Read one date entry out of the configuration.
     *
     * @throws RuntimeException If it cannot be understood.
     */
    private function parseConfigDate(string $key): ?DateTimeImmutable {
        $value = trim((string) ($this->config[$key] ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable $e) {
            throw new RuntimeException("The '$key' configuration entry is not a readable date: '$value'. Expected YYYY-MM-DD.");
        }

        // Day granularity, to match what the protocol can express.
        return $date->setTime(0, 0, 0);
    }

    /**
     * Turn a decoded folder name into the relative path used on disk.
     *
     * Every connector must run its folder names through this method, in
     * getFolders() *and* in getEmail(). The two must agree: the first creates
     * the directories, the second decides where each message lands, so any
     * divergence writes messages into a directory that does not exist.
     *
     * The name is also made safe to append to the archive path: a source is
     * not necessarily trustworthy, and a '..' segment would otherwise let
     * messages be written outside the archive.
     */
    protected function folderName(string $name): string {
        $name = str_replace(["\0", '\\'], ['', '/'], $name);

        $segments = [];
        foreach (explode('/', $name) as $segment) {
            $segment = trim($segment);

            // Drop empty and self segments, neutralise parent ones.
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $segments[] = ($segment === '..') ? '__' : $segment;
        }

        return implode('/', $segments);
    }

    /**
     * Pre-function hook.
     */
    public function preFunc() {
    }

    /**
     * Post-function hook.
     */
    public function postFunc() {
    }
}
