<?php

namespace Mailbxzip\Cli\Contract;

/**
 * Implemented by the input connectors able to remove messages from their
 * source, so a mailbox can be emptied once archived.
 *
 * Implementing this interface is not enough for anything to be deleted. The
 * mailbox also requires:
 *   - the 'delete' configuration entry set to 1, off by default;
 *   - CAN_DELETE to be true on the input *and* on the output connector, the
 *     latter vouching that its format is a faithful enough archive;
 *   - the ZIP archive to have been produced successfully.
 *
 * Deletion happens at the very end of the run, never while messages are still
 * being written.
 */
interface DeletableInputInterface {

    /**
     * Remove messages from a folder of the source.
     *
     * Called once per folder with every message to purge, so a connector can
     * batch the work -- flagging then expunging a mailbox one message at a
     * time is needlessly slow.
     *
     * An id that no longer exists must not be treated as an error: a previous
     * run may have removed it already.
     *
     * The return value carries which messages actually went, not merely how
     * many: the mailbox records exactly those as purged, so anything a
     * partial failure left behind is offered again on the next run instead of
     * being written off.
     *
     * @param string $folder Folder key, as returned by getEmails().
     * @param array<int|string> $ids Identifiers to remove.
     * @return array<int|string> The identifiers actually removed, as given.
     */
    public function deleteEmails(string $folder, array $ids): array;
}
