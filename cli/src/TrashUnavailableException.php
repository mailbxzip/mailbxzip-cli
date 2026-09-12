<?php

namespace Mailbxzip\Cli;

use RuntimeException;

/**
 * Raised when the folder meant to receive the archived messages will not take
 * them.
 *
 * Distinguished from an ordinary deletion failure because the trash is the
 * same for every folder: if it turns one batch away it will turn away all of
 * them, so there is nothing to gain from carrying on -- only pages of
 * identical errors, and a connection worn down by pointless attempts.
 */
class TrashUnavailableException extends RuntimeException {
}
