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

    protected $config;
    protected $mailbox;

    /** @var array{since: ?DateTimeImmutable, before: ?DateTimeImmutable}|null */
    private $dateRange = null;

    /**
     * @param array        $config  Configuration array.
     * @param Mailbox|null $mailbox Owning mailbox, when running under one.
     */
    public function __construct($config, Mailbox $mailbox = null) {
        $this->config = $config;
        $this->mailbox = $mailbox;
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
