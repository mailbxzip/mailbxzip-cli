<?php

namespace Mailbxzip\Cli\Out;

use Mailbxzip\Cli\Contract\OutputHandlerInterface;
use Mailbxzip\Cli\Mailbox;
use RuntimeException;
use Throwable;

/**
 * Class AbstractOutput
 *
 * Shared plumbing for the output connectors: configuration access, folder
 * creation, guarded writes and the fallback that keeps a message when the
 * target format cannot be produced.
 *
 * A concrete connector only has to implement saveEmails().
 */
abstract class AbstractOutput implements OutputHandlerInterface {

    /**
     * Configuration entry every output connector understands.
     */
    public const INTO_CONFIG_VAR = [
        'into' => 'write every message into this one folder instead of mirroring the source tree, e.g. into = "Archive 2024"',
    ];

    protected $config;
    protected $mailbox;

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
     * connector was built, such as 'emailArchivePath', only exist there.
     *
     * @return array The configuration array.
     */
    public function getConfig() {
        return (!is_null($this->mailbox)) ? $this->mailbox->getConfig() : $this->config;
    }

    /**
     * Get the root directory of the archive being written.
     *
     * @throws RuntimeException If the mailbox never computed it.
     */
    protected function archivePath(): string {
        $config = $this->getConfig();

        if (empty($config['emailArchivePath'])) {
            throw new RuntimeException("The 'emailArchivePath' configuration entry is missing: the output connector cannot know where to write.");
        }

        return $config['emailArchivePath'];
    }

    /**
     * Create the archive directory tree.
     *
     * Just the one directory when everything is being gathered into it: the
     * source tree is then deliberately not reproduced.
     *
     * @param array<string,int> $folders Folder name => message count.
     */
    public function setFolders(array $folders): void {
        $into = $this->into();

        if (!is_null($into)) {
            $this->ensureDirectory($this->archivePath() . DIRECTORY_SEPARATOR . $into);

            return;
        }

        foreach ($folders as $folder => $nb) {
            $this->ensureDirectory($this->archivePath() . DIRECTORY_SEPARATOR . $folder);
        }
    }

    /**
     * The single folder everything is to be gathered into, if any.
     */
    protected function into(): ?string {
        $into = trim((string) ($this->getConfig()['into'] ?? ''), " \t\n\r\0\x0B/");

        return ($into === '') ? null : $into;
    }

    /**
     * Where a message belongs in the archive.
     *
     * The folder it came from, unless everything is being gathered into one.
     * Flattening makes name clashes far likelier, which uniquePath() already
     * settles by appending the source uid.
     */
    protected function destination(\Mailbxzip\Cli\Eml $eml): string {
        return $this->into() ?? $eml->getFolder();
    }

    /**
     * Create a directory if it does not exist yet.
     *
     * @throws RuntimeException If the directory cannot be created.
     */
    protected function ensureDirectory(string $path): void {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create folder: $path");
        }
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
     * Make a save path unique.
     *
     * Two messages of the same folder can perfectly well produce the same
     * name: Eml::filename() truncates to 50 characters and strips most
     * punctuation. Without this, the second one silently overwrote the first.
     *
     * The suffix is the source uid rather than a counter, so a rebuilt archive
     * ends up with the very same names.
     */
    protected function uniquePath(string $path, \Mailbxzip\Cli\Eml $eml): string {
        if (!file_exists($path)) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $base = ($extension === '') ? $path : substr($path, 0, -(strlen($extension) + 1));
        $unique = $base.'-'.$eml->getUid().(($extension === '') ? '' : '.'.$extension);

        $this->log(sprintf(
            'name collision on %s, e-mail (%s) saved as %s instead',
            basename($path),
            $eml->getUid(),
            basename($unique)
        ), 'WARNING');

        return $unique;
    }

    /**
     * Write a file, creating its directory first.
     *
     * file_put_contents() only raises a warning and returns false on failure,
     * which used to let messages disappear unnoticed; turn that into an
     * exception so the fallback can run.
     *
     * @throws RuntimeException If the file cannot be written.
     */
    protected function write(string $path, string $content, int $flags = 0): void {
        $this->ensureDirectory(dirname($path));

        if (file_put_contents($path, $content, $flags) === false) {
            throw new RuntimeException("Unable to write file: $path");
        }
    }

    /**
     * Last resort when the target format cannot be produced: keep the raw
     * source and report the failure.
     *
     * @throws Throwable Rethrown when running without a mailbox, as there is
     *                   then nowhere to fall back to.
     */
    protected function fallback(\Mailbxzip\Cli\Eml $eml, Throwable $e, string $message): void {
        if (is_null($this->mailbox)) {
            throw $e;
        }

        $this->mailbox->log($message, 'ERROR');
        $this->mailbox->log($e->getMessage(), 'ERROR');

        try {
            $this->mailbox->saveSource($eml, true);
        } catch (Throwable $fallbackFailure) {
            // The fallback lives inside the folder that just refused the
            // write, so it often shares its fate. Nothing was persisted:
            // report it and let the mailbox leave the message out of the
            // resume index, so a later run tries again.
            $this->mailbox->log('The raw source could not be kept either: '.$fallbackFailure->getMessage(), 'ERROR');
            throw $e;
        }
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
