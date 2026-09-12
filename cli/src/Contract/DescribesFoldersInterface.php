<?php

namespace Mailbxzip\Cli\Contract;

/**
 * Implemented by the input connectors able to describe their folders beyond
 * the name and count that archiving needs.
 *
 * This exists for one practical reason: a mailbox cannot be told which folder
 * is its trash if its folder names are never shown. IMAP names them in
 * modified UTF-7, so "Éléments supprimés" reaches the wire as
 * "&AMk-l&AOk-ments supprim&AOk-s" and no one can guess it.
 */
interface DescribesFoldersInterface {

    /**
     * Describe every folder of the source.
     *
     * @return array<int,array{name: string, path: string, count: int, flags: array<int,string>}>
     *         name  the folder as written in the archive, and as accepted by
     *               the 'trash' configuration entry;
     *         path  the raw identifier used by the source, also accepted;
     *         count how many messages it holds;
     *         flags the attributes the source advertises, without their
     *               leading backslash -- 'Trash', 'Sent', 'HasNoChildren'...
     */
    public function describeFolders(): array;

    /**
     * The folder the source would use as its trash, left to itself.
     *
     * Answers what 'trash = 1' resolves to, without the caller having to
     * attempt a deletion to find out. Null when nothing convincing was found,
     * in which case the folder has to be named in the configuration.
     */
    public function detectTrashFolder(): ?string;
}
