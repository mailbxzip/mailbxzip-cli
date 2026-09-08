<?php

namespace Mailbxzip\Cli\Contract;

use Mailbxzip\Cli\Eml;

/**
 * Contract every output connector (Mailbxzip\Cli\Out\*) must fulfil.
 *
 * An output connector turns a message into files on disk. It is instantiated
 * by Mailbox from the 'out' configuration key.
 *
 * Implementations may also expose preFunc() and postFunc(); both are optional
 * hooks called by Mailbox when they exist. Mailbxzip\Cli\Out\AbstractOutput
 * provides a ready-made implementation of this interface.
 */
interface OutputHandlerInterface {

    /**
     * Materialise the folder tree, before any message is saved.
     *
     * @param array<string,int> $folders Folder name => message count, as
     *                                   returned by the input connector.
     */
    public function setFolders(array $folders): void;

    /**
     * Persist one message. Despite its plural name, this is called once per
     * e-mail.
     *
     * An implementation that cannot write the message must not fail silently:
     * either let the exception bubble up, or fall back to saving the raw
     * source through Mailbox::saveSource().
     */
    public function saveEmails(Eml $eml): void;
}
