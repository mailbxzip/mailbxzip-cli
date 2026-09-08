<?php

namespace Mailbxzip\Cli\Contract;

use Mailbxzip\Cli\Eml;

/**
 * Contract every input connector (Mailbxzip\Cli\In\*) must fulfil.
 *
 * An input connector is a source of e-mails: an IMAP account, a local archive,
 * a remote API. It is instantiated by Mailbox from the 'in' configuration key
 * and is never referenced directly by the orchestrator.
 *
 * Implementations may also expose preFunc() and postFunc(); both are optional
 * hooks called by Mailbox when they exist.
 */
interface InputHandlerInterface {

    /**
     * Describe the folder tree of the source.
     *
     * Folder names are used by the output connector to create directories, so
     * they must be normalised: decoded to UTF-8, free of any server prefix,
     * and using '/' as the sub-folder separator. The very same names must be
     * carried by the Eml objects returned by getEmail(), otherwise messages
     * are written next to the directories instead of inside them.
     *
     * @return array{folders: array<string,int>, total: int}
     */
    public function getFolders(): array;

    /**
     * List the identifiers of every message to process, per folder.
     *
     * The keys are passed back verbatim to getEmail() as $folder, so a
     * connector is free to key this array with its own internal folder
     * identifiers rather than the display names returned by getFolders().
     *
     * @return array<string,array<int|string>>
     */
    public function getEmails(): array;

    /**
     * Fetch a single message.
     *
     * @param int|string $id     Identifier, as listed by getEmails().
     * @param string     $folder Folder key, as listed by getEmails().
     */
    public function getEmail($id, $folder): Eml;
}
