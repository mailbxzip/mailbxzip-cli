<?php
/**
 * End-to-end smoke test for the In/Out pipeline.
 *
 * Runs the Test input connector through every output connector that does not
 * need a mail server, in a throwaway working directory, and checks that the
 * contract holds: every message lands inside its folder, accents included,
 * and a resumed run skips what was already written.
 *
 * Usage: php tests/smoke.php
 */

require_once __DIR__.'/../vendor/autoload.php';

use Mailbxzip\Cli\Mailbox;

$failures = 0;
$checks = 0;

function check(string $label, bool $ok): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "  FAIL  $label\n";
        return;
    }
    echo "  ok    $label\n";
}

function fails(string $label, callable $fn, string $expectedNeedle): void {
    try {
        $fn();
        check($label, false);
    } catch (Throwable $e) {
        check($label.' ('.get_class($e).')', str_contains($e->getMessage(), $expectedNeedle));
    }
}

function workingDir(string $name): string {
    $dir = sys_get_temp_dir().'/mailbxzip-smoke-'.$name.'-'.uniqid();
    mkdir($dir.'/config', 0777, true);
    return $dir;
}

function writeConfig(string $dir, string $in, string $out, array $extra = []): string {
    $config = array_merge([
        'address' => 'test@mailbxzip.com',
        'in' => $in,
        'out' => $out,
    ], $extra);

    $ini = '';
    foreach ($config as $key => $value) {
        $ini .= "$key = \"$value\"\n";
    }

    file_put_contents($dir.'/config/test@mailbxzip.com', $ini);

    return 'test@mailbxzip.com';
}

function rmrf(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
        $path = $dir.'/'.$item;
        is_dir($path) ? rmrf($path) : unlink($path);
    }
    rmdir($dir);
}

// The Pdf connector renders Twig templates through a relative loader path.
chdir(dirname(__DIR__));

$cleanup = [];

// ---------------------------------------------------------------- Out/Eml ---
echo "\nOut/Eml — one file per message\n";

$dir = workingDir('eml');
$cleanup[] = $dir;
$mailbox = new Mailbox(writeConfig($dir, 'Test', 'Eml'), $dir);
$mailbox->start();

$archive = $dir.'/archives/test@mailbxzip.com';

check('INBOX directory created', is_dir($archive.'/INBOX'));
check('accented folder created', is_dir($archive.'/INBOX/Éléments envoyés'));
check('plain message written', is_file($archive.'/INBOX/2024-01-08-yann-Bonjour.eml'));
check('accented subject message written', count(glob($archive.'/INBOX/2024-01-15*.eml')) === 1);

// A sent message must be named after its recipient, not after the account.
check('sent message named after recipient', count(glob($archive.'/INBOX/Éléments envoyés/2024-01-16-destinataire-*.eml')) === 1);

// Unparsable date falls back to email-<uid>.
check('undated message falls back to email-<uid>', is_file($archive.'/INBOX/Brouillons/email-4.eml'));

$saved = json_decode(file_get_contents($archive.'/saved_emails.json'), true);
check('resume index lists every message', array_sum(array_map('count', $saved)) === 9);
check('zip archive produced', is_file($dir.'/archives/test@mailbxzip.com.zip'));

// -------------------------------------------------------------- collisions ---
echo "\nName collisions — the second message no longer overwrites the first\n";

$archives = $archive.'/INBOX/Archives';
$collided = glob($archives.'/2024-02-01-dup-*.eml');
$names = array_map('basename', $collided);

check('both colliding messages kept', count($collided) === 2);
check('first keeps the plain name', in_array('2024-02-01-dup-Compte-rendu-de-la-reunion-du-comit.eml', $names, true));
check('second suffixed with its uid', in_array('2024-02-01-dup-Compte-rendu-de-la-reunion-du-comit-6.eml', $names, true));
check('collision reported in the log', str_contains(file_get_contents($archive.'/export.log'), 'name collision'));
check('the two files hold different messages', count($collided) === 2 && file_get_contents($collided[0]) !== file_get_contents($collided[1]));

$accented = glob($archives.'/*Réunion*.eml');
check('accented name truncated on a character boundary', count($accented) === 1 && mb_check_encoding(basename($accented[0]), 'UTF-8'));

// ------------------------------------------------------------- resumption ---
echo "\nResumption — a second run writes nothing new\n";

$before = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archive, FilesystemIterator::SKIP_DOTS)) as $f) {
    $before[$f->getPathname()] = $f->getSize();
}

$mailbox = new Mailbox('test@mailbxzip.com', $dir);
$mailbox->start();

$after = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archive, FilesystemIterator::SKIP_DOTS)) as $f) {
    $after[$f->getPathname()] = $f->getSize();
}

unset($before[$archive.'/export.log'], $after[$archive.'/export.log']);
check('archive unchanged after a second run', $before == $after);

// --------------------------------------------------------------- Out/Mbox ---
echo "\nOut/Mbox — one mailbox file per folder\n";

$dir = workingDir('mbox');
$cleanup[] = $dir;
$mailbox = new Mailbox(writeConfig($dir, 'Test', 'Mbox'), $dir);
$mailbox->start();

$archive = $dir.'/archives/test@mailbxzip.com';
check('INBOX mbox written', is_file($archive.'/INBOX/email.mbox'));
check('accented folder mbox written', is_file($archive.'/INBOX/Éléments envoyés/email.mbox'));
check('INBOX mbox holds both messages', substr_count(file_get_contents($archive.'/INBOX/email.mbox'), 'Subject:') === 2);

// ------------------------------------------------------------- mbox format ---
echo "\nMbox format — readable by a mail client\n";

$mbox = file_get_contents($archive.'/INBOX/email.mbox');
$archivesMbox = file_get_contents($archive.'/INBOX/Archives/email.mbox');

check('entry opens with a From separator', str_starts_with($mbox, 'From '));
check('one separator per message', preg_match_all('/^From \S+ \w{3} \w{3} [ \d]\d \d{2}:\d{2}:\d{2} \d{4}$/m', $mbox) === 2);
check('envelope holds the bare sender address', (bool) preg_match('/^From yann@mailbxzip\.com /m', $mbox));
check('body line starting with From is quoted', str_contains($archivesMbox, "\n>From here on, nothing matters."));
check('no unquoted From line inside a body', preg_match_all('/^From /m', $archivesMbox) === 4);
check('entries separated by a blank line', str_ends_with($mbox, "\n\n"));

// --------------------------------------------------------------- wSource ----
echo "\nwSource — raw sources kept alongside\n";

$dir = workingDir('wsource');
$cleanup[] = $dir;
$mailbox = new Mailbox(writeConfig($dir, 'Test', 'Mbox', ['wSource' => 1]), $dir);
$mailbox->start();

$archive = $dir.'/archives/test@mailbxzip.com';
check('.eml source folder created', is_dir($archive.'/INBOX/.eml'));
check('sources kept for both INBOX messages', count(glob($archive.'/INBOX/.eml/*.eml')) === 2);

// --------------------------------------------------------------- Out/Test ---
echo "\nOut/Test — dry run touches no archive folder\n";

$dir = workingDir('test');
$cleanup[] = $dir;
$mailbox = new Mailbox(writeConfig($dir, 'Test', 'Test'), $dir);
$mailbox->start();

$archive = $dir.'/archives/test@mailbxzip.com';
check('no folder created by the dry run', !is_dir($archive.'/INBOX'));
check('run reported in the log', str_contains(file_get_contents($archive.'/export.log'), '[out:test] INBOX/'));

// ------------------------------------------------------------- validation ---
echo "\nConnector resolution — misconfiguration is reported, not fatal\n";

$dir = workingDir('bad');
$cleanup[] = $dir;

fails('unknown input connector', function () use ($dir) {
    new Mailbox(writeConfig($dir, 'Nope', 'Eml'), $dir);
}, "Unknown in connector 'Nope'");

fails('unknown output connector', function () use ($dir) {
    new Mailbox(writeConfig($dir, 'Test', 'Nope'), $dir);
}, "Unknown out connector 'Nope'");

fails('missing out entry', function () use ($dir) {
    file_put_contents($dir.'/config/bare', "address = \"a@b.c\"\nin = \"Test\"\n");
    new Mailbox('bare', $dir);
}, "has no 'out' entry");

// --------------------------------------------------------------- fallback ---
echo "\nFailed write — reported, never silent\n";

$dir = workingDir('readonly');
$cleanup[] = $dir;
$mailbox = new Mailbox(writeConfig($dir, 'Test', 'Eml', ['wSource' => 1]), $dir);
$archive = $dir.'/archives/test@mailbxzip.com';

$eml = (new \Mailbxzip\Cli\In\Test(['address' => 'test@mailbxzip.com']))->getEmail(1, 'INBOX');

// (a) The folder refuses the write, but the .eml fallback directory already
//     exists and is writable: the source must be kept.
mkdir($archive.'/INBOX/.eml', 0777, true);
chmod($archive.'/INBOX', 0555);

$out = new \Mailbxzip\Cli\Out\Eml($mailbox->getConfig(), $mailbox);
$out->saveEmails($eml);

$log = file_get_contents($archive.'/export.log');
check('failure logged as ERROR', str_contains($log, '[ERROR] Unable to save Eml file'));
check('raw source kept as fallback', count(glob($archive.'/INBOX/.eml/*.eml')) === 1);

// (b) Standalone, with nowhere to fall back to: the failure must surface
//     rather than be returned as a false nobody reads.
fails('write failure raises instead of returning false', function () use ($archive, $eml) {
    $out = new \Mailbxzip\Cli\Out\Eml(['emailArchivePath' => $archive]);
    $out->saveEmails($eml);
}, 'Unable to write file');

chmod($archive.'/INBOX', 0777);

// ------------------------------------------------------ export resilience ---
echo "\nUnwritable folder — the export finishes and retries later\n";

$dir = workingDir('resilient');
$cleanup[] = $dir;
$mailbox = new Mailbox(writeConfig($dir, 'Test', 'Eml'), $dir);
$archive = $dir.'/archives/test@mailbxzip.com';

// Lock the accented folder before the run: neither the message nor its
// fallback can be written there.
mkdir($archive.'/INBOX/Éléments envoyés', 0777, true);
chmod($archive.'/INBOX/Éléments envoyés', 0555);

$mailbox->start();

$log = file_get_contents($archive.'/export.log');
$saved = json_decode(file_get_contents($archive.'/saved_emails.json'), true);

check('export completed despite the failure', str_contains($log, 'Total export duration'));
check('other folders still exported', count(glob($archive.'/INBOX/*.eml')) === 2);
check('failure reported in the log', str_contains($log, 'could not be saved and will be retried'));
check('failed message kept out of the resume index', !in_array(3, $saved['INBOX/Éléments envoyés'] ?? []));

// Unlock and rerun: the message that failed is the one picked up.
chmod($archive.'/INBOX/Éléments envoyés', 0777);
(new Mailbox('test@mailbxzip.com', $dir))->start();

check('retried on the next run', count(glob($archive.'/INBOX/Éléments envoyés/*.eml')) === 1);

// ------------------------------------------------------------ folder names ---
echo "\nFolder names — hostile input stays inside the archive\n";

$probe = new class ([]) extends \Mailbxzip\Cli\In\AbstractInput {
    public function getFolders(): array { return ['folders' => [], 'total' => 0]; }
    public function getEmails(): array { return []; }
    public function getEmail($id, $folder): \Mailbxzip\Cli\Eml { throw new RuntimeException('unused'); }
    public function normalize(string $name): string { return $this->folderName($name); }
};

check('plain name untouched', $probe->normalize('INBOX') === 'INBOX');
check('accents preserved', $probe->normalize('INBOX/Éléments envoyés') === 'INBOX/Éléments envoyés');
check('empty segments collapsed', $probe->normalize('INBOX//Sent/') === 'INBOX/Sent');
check('parent segments neutralised', $probe->normalize('../../etc') === '__/__/etc');
check('parent segment mid-path neutralised', $probe->normalize('INBOX/../../root') === 'INBOX/__/__/root');
check('self segments dropped', $probe->normalize('./INBOX/./Sent') === 'INBOX/Sent');
check('NUL bytes stripped', $probe->normalize("INBOX\0/Sent") === 'INBOX/Sent');
check('backslashes treated as separators', $probe->normalize('INBOX\\Sent') === 'INBOX/Sent');

// ------------------------------------------------------------- attachments ---
echo "\nAttachments — extracted from the MIME message\n";

$withAttachment = (new \Mailbxzip\Cli\In\Test(['address' => 'test@mailbxzip.com']))
    ->getEmail(9, 'INBOX/Pieces jointes');

$attachments = $withAttachment->getAttachments();

check('attachment found', count($attachments) === 1);
check('attachment name preserved', ($attachments[0]['filename'] ?? '') === 'note.txt');
check('attachment content decoded', ($attachments[0]['content'] ?? '') === "Contenu de la piece jointe.\n");
check('human readable size computed', ($attachments[0]['humanSize'] ?? '') !== '');

// -------------------------------------------------------------- pdf template ---
echo "\nPDF template — a plain text message keeps its body\n";

// The Twig loader uses a relative path, hence the chdir at the top.
$plain = (new \Mailbxzip\Cli\In\Test(['address' => 'test@mailbxzip.com']))->getEmail(1, 'INBOX');
$html = $plain->view('pdf/mail.html');

check('headers rendered', str_contains($html, 'yann@mailbxzip.com') && str_contains($html, 'Bonjour'));
check('plain text body rendered', str_contains($html, 'Hello world!'));

$multiline = (new \Mailbxzip\Cli\In\Test(['address' => 'test@mailbxzip.com']))->getEmail(8, 'INBOX/Archives');
check('line breaks preserved', str_contains($multiline->view('pdf/mail.html'), '<br />'));

$attachmentHtml = $withAttachment->view('pdf/mail.html');
check('attachment listed in the document', str_contains($attachmentHtml, 'note.txt'));

// -------------------------------------------------------------- pdf output ---
if (!extension_loaded('gd')) {
    echo "\nPDF output — skipped, ext-gd is not loaded (run: php -d extension=gd tests/smoke.php)\n";
} else {
    echo "\nPDF output — documents produced and attachments re-injected\n";

    $dir = workingDir('pdf');
    $cleanup[] = $dir;
    $mailbox = new Mailbox(writeConfig($dir, 'Test', 'Pdf'), $dir);
    $mailbox->start();

    $archive = $dir.'/archives/test@mailbxzip.com';
    $pdfs = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archive, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (strtolower($f->getExtension()) === 'pdf') {
            $pdfs[] = $f->getPathname();
        }
    }

    check('one document per message', count($pdfs) === 9);
    check('all documents are valid PDFs', count(array_filter($pdfs, fn ($p) => file_get_contents($p, false, null, 0, 5) === '%PDF-')) === 9);
    check('accented folder and name preserved', count(glob($archive.'/INBOX/Éléments envoyés/*.pdf')) === 1);
    check('collision suffixed on PDF too', count(glob($archive.'/INBOX/Archives/2024-02-01-dup-*.pdf')) === 2);

    $attached = glob($archive.'/INBOX/Pieces jointes/*.pdf');
    $raw = (count($attached) === 1) ? file_get_contents($attached[0]) : '';

    check('attachment listed on the page', str_contains($raw, 'note.txt'));
    // Listing the name is not enough: the file itself must travel with the
    // document, as a PDF file annotation.
    check('attachment truly embedded', str_contains($raw, '/EmbeddedFile') && str_contains($raw, '/FileAttachment'));
}

// ------------------------------------------------------------ date filter ---
echo "\nDate filter — messages outside the window are never fetched\n";

$make = function (array $extra) {
    return new \Mailbxzip\Cli\In\Test(array_merge(['address' => 'test@mailbxzip.com'], $extra));
};

$countAll = function ($connector) {
    return array_sum(array_map('count', $connector->getEmails()));
};

check('no window keeps everything', $countAll($make([])) === 9);
check('since drops what precedes it', $countAll($make(['since' => '2024-02-01'])) === 5);
check('before is exclusive', $countAll($make(['before' => '2024-02-01'])) === 3);
check('both bounds combine', $countAll($make(['since' => '2024-02-01', 'before' => '2024-02-03'])) === 3);
check('window can select nothing', $countAll($make(['since' => '2030-01-01'])) === 0);

// The undated draft cannot be vouched for, so a filtered export leaves it out.
$filtered = $make(['since' => '2020-01-01'])->getEmails();
check('undated message excluded once filtering', !in_array(4, $filtered['INBOX/Brouillons'] ?? [], true));
check('undated message kept when unfiltered', in_array(4, $make([])->getEmails()['INBOX/Brouillons'] ?? [], true));

// Folder counts follow the filter, so the progress figures stay honest.
$folders = $make(['since' => '2024-02-01'])->getFolders();
check('folder counts follow the filter', $folders['total'] === 5 && $folders['folders']['INBOX'] === 0);

fails('unreadable date is reported', function () use ($make) {
    $make(['since' => 'la semaine derniere'])->getEmails();
}, "not a readable date");

fails('empty window is reported', function () use ($make) {
    $make(['since' => '2025-01-01', 'before' => '2024-01-01'])->getEmails();
}, 'must be earlier than');

// ------------------------------------------------------- legacy server string ---
echo "\nImap — legacy ext-imap server strings still understood\n";

$parse = ['Mailbxzip\\Cli\\In\\Imap', 'parseLegacyServer'];

check('host and port extracted', $parse('{ssl0.ovh.net:993/imap/ssl}') == ['host' => 'ssl0.ovh.net', 'port' => 993, 'encryption' => 'ssl', 'validate_cert' => true]);
check('notls maps to no encryption', $parse('{localhost:143/imap/notls}')['encryption'] === 'none');
check('novalidate-cert honoured', $parse('{ex.net/imap/ssl/novalidate-cert}')['validate_cert'] === false);
check('port defaults to 993', $parse('{ex.net/imap/ssl}')['port'] === 993);
check('garbage yields no host', $parse('INBOX')['host'] === '');

// ------------------------------------------------------------------ Imap ---
$imapPort = 11439;
$server = @stream_socket_client("tcp://127.0.0.1:$imapPort", $errno, $errstr, 0.2);
$spawned = false;

if (!$server) {
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $process = @proc_open('python3 '.escapeshellarg(__DIR__.'/fake-imap-server.py')." $imapPort", $descriptors, $pipes);

    if (is_resource($process)) {
        // Wait for the READY line rather than sleeping blindly.
        stream_set_timeout($pipes[1], 5);
        fgets($pipes[1]);
        $spawned = true;
    }
} else {
    fclose($server);
}

if (!$spawned && !@stream_socket_client("tcp://127.0.0.1:$imapPort", $errno, $errstr, 0.2)) {
    echo "\nImap — skipped, the fake IMAP server could not be started (python3 needed)\n";
} else {
    echo "\nImap — full export against a real IMAP conversation\n";

    $dir = workingDir('imap');
    $cleanup[] = $dir;
    $config = writeConfig($dir, 'Imap', 'Eml', [
        'server' => '{127.0.0.1:'.$imapPort.'/imap/notls}',
        'username' => 'u',
        'password' => 'p',
    ]);

    $imapMailbox = new Mailbox($config, $dir);
    $imapMailbox->start();
    $archive = $dir.'/archives/test@mailbxzip.com';

    check('folders created from the IMAP listing', is_dir($archive.'/INBOX'));
    // The server names it "INBOX.&AMk-l&AOk-ments envoy&AOk-s": writing to the
    // raw path would drop every message of that folder.
    check('modified UTF-7 folder decoded', is_dir($archive.'/INBOX/Éléments envoyés'));
    check('no raw UTF-7 directory left behind', count(glob($archive.'/INBOX*&*')) === 0);
    check('messages fetched and written', count(glob($archive.'/INBOX/*.eml')) === 4);
    check('message of the accented folder written', count(glob($archive.'/INBOX/Éléments envoyés/*.eml')) === 1);

    $raw = file_get_contents($archive.'/INBOX/2024-01-08-yann-Bonjour.eml');
    check('header and body reassembled', str_contains($raw, 'Subject: Bonjour') && str_contains($raw, 'Hello world!'));

    $empty = $archive.'/INBOX/Brouillons';
    check('empty folder still created', is_dir($empty) && count(glob($empty.'/*')) === 0);

    // Same mailbox, restricted to 2024: the server itself sorts the
    // messages out, through SENTSINCE / SENTBEFORE.
    $dir = workingDir('imap-filtered');
    $cleanup[] = $dir;
    $filteredConfig = writeConfig($dir, 'Imap', 'Eml', [
        'server' => '{127.0.0.1:'.$imapPort.'/imap/notls}',
        'username' => 'u',
        'password' => 'p',
        'since' => '2024-01-01',
        'before' => '2025-01-01',
    ]);

    $filteredMailbox = new Mailbox($filteredConfig, $dir);
    $filteredMailbox->start();
    $filteredArchive = $dir.'/archives/test@mailbxzip.com';

    check('window applied server side', count(glob($filteredArchive.'/INBOX/*.eml')) === 2);
    check('older message left on the server', count(glob($filteredArchive.'/INBOX/2023-*.eml')) === 0);
    check('newer message left on the server', count(glob($filteredArchive.'/INBOX/2025-*.eml')) === 0);
    check('accented folder still exported', count(glob($filteredArchive.'/INBOX/Éléments envoyés/*.eml')) === 1);

    // A dedicated server, since the purge mutates its mailbox.
    $purgePort = 0;
    $purgeProbe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($purgeProbe) {
        $name = stream_socket_get_name($purgeProbe, false);
        $purgePort = (int) substr($name, strrpos($name, ':') + 1);
        fclose($purgeProbe);
    }

    $purgeProcess = @proc_open(
        'python3 '.escapeshellarg(__DIR__.'/fake-imap-server.py')." $purgePort",
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $purgePipes
    );

    if (is_resource($purgeProcess)) {
        stream_set_timeout($purgePipes[1], 5);
        fgets($purgePipes[1]);

        $dir = workingDir('imap-purge');
        $cleanup[] = $dir;
        $purgeConfig = writeConfig($dir, 'Imap', 'Eml', [
            'server' => '{127.0.0.1:'.$purgePort.'/imap/notls}',
            'username' => 'u',
            'password' => 'p',
            'before' => '2025-01-01',
            'delete' => 1,
        ]);

        $purgeMailbox = new Mailbox($purgeConfig, $dir);
        $purgeMailbox->start();
        $purgeArchive = $dir.'/archives/test@mailbxzip.com';

        check('archived messages written before any deletion', count(glob($purgeArchive.'/INBOX/*.eml')) === 3);
        check('deletion recorded', is_file($purgeArchive.'/purged_emails.json'));

        // Ask the server again: only what fell outside the window may remain.
        $left = (new \Mailbxzip\Cli\In\Imap([
            'address' => 'test@mailbxzip.com',
            'host' => '127.0.0.1', 'port' => $purgePort, 'encryption' => 'none',
            'username' => 'u', 'password' => 'p',
        ]))->getEmails();

        $remaining = array_merge(...array_values(array_map('array_values', $left)));

        check('archived messages removed from the server', count($remaining) === 1);
        check('message outside the window left untouched', in_array('104', $remaining, true));

        unset($purgeMailbox);
        gc_collect_cycles();
        proc_terminate($purgeProcess);
        proc_close($purgeProcess);
    }

    // --- trash mode, on its own server since the purge mutates the mailbox ---
    $trashPort = 0;
    $trashProbe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($trashProbe) {
        $name = stream_socket_get_name($trashProbe, false);
        $trashPort = (int) substr($name, strrpos($name, ':') + 1);
        fclose($trashProbe);
    }

    $trashProcess = @proc_open(
        'python3 '.escapeshellarg(__DIR__.'/fake-imap-server.py')." $trashPort",
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $trashPipes
    );

    if (is_resource($trashProcess)) {
        stream_set_timeout($trashPipes[1], 5);
        fgets($trashPipes[1]);

        echo "\nTrash — archived messages moved instead of erased\n";

        $server = ['server' => '{127.0.0.1:'.$trashPort.'/imap/notls}', 'username' => 'u', 'password' => 'p'];
        $read = function () use ($trashPort) {
            $folders = (new \Mailbxzip\Cli\In\Imap([
                'address' => 'test@mailbxzip.com',
                'host' => '127.0.0.1', 'port' => $trashPort, 'encryption' => 'none',
                'username' => 'u', 'password' => 'p',
            ]))->getEmails();

            return array_map('array_values', $folders);
        };

        // A trash folder that does not exist must stop the purge, not fall
        // back to erasing the messages.
        $dir = workingDir('imap-trash-missing');
        $cleanup[] = $dir;
        $missing = new Mailbox(writeConfig($dir, 'Imap', 'Eml', $server + [
            'before' => '2025-01-01', 'delete' => 1, 'trash' => 'INBOX/Corbeille',
        ]), $dir);
        $missing->start();

        $log = file_get_contents($dir.'/archives/test@mailbxzip.com/export.log');
        check('messages still archived when the trash is missing', count(glob($dir.'/archives/test@mailbxzip.com/INBOX/*.eml')) === 3);
        check('unknown trash reported', str_contains($log, "trash folder 'INBOX/Corbeille' does not exist"));
        check('nothing erased when the trash is missing', str_contains($log, 'Total emails deleted from the source: 0'));

        $before = $read();
        check('mailbox untouched when the trash is missing', count($before['INBOX'] ?? []) === 4);

        unset($missing);
        gc_collect_cycles();

        // Now the real thing: the server advertises INBOX.Trash as \Trash.
        $dir = workingDir('imap-trash');
        $cleanup[] = $dir;
        $trashed = new Mailbox(writeConfig($dir, 'Imap', 'Eml', $server + [
            'before' => '2025-01-01', 'delete' => 1, 'trash' => 1,
        ]), $dir);
        $trashed->start();

        $after = $read();
        $log = file_get_contents($dir.'/archives/test@mailbxzip.com/export.log');

        check('trash located through its SPECIAL-USE flag', str_contains($log, "moved to 'INBOX.Trash'"));

        // What the folders command shows: the readable name next to the raw,
        // encoded identifier nobody could guess.
        $inspector = new \Mailbxzip\Cli\In\Imap([
            'address' => 'test@mailbxzip.com',
            'host' => '127.0.0.1', 'port' => $trashPort, 'encryption' => 'none',
            'username' => 'u', 'password' => 'p',
        ]);
        $imapFolders = $inspector->describeFolders();
        $byName = array_column($imapFolders, null, 'name');

        check('encoded path surfaced beside the readable name', ($byName['INBOX/Éléments envoyés']['path'] ?? '') === 'INBOX.&AMk-l&AOk-ments envoy&AOk-s');
        check('imap trash detected from its flag', $inspector->detectTrashFolder() === 'INBOX.Trash');

        // An unknown trash must say which names would have worked.
        $wrong = new \Mailbxzip\Cli\In\Imap([
            'address' => 'test@mailbxzip.com',
            'host' => '127.0.0.1', 'port' => $trashPort, 'encryption' => 'none',
            'username' => 'u', 'password' => 'p', 'trash' => 'Corbeille',
        ]);

        fails('unknown trash lists the available folders', function () use ($wrong) {
            $wrong->deleteEmails('INBOX', [101]);
        }, 'Name one of: "INBOX"');
        check('archived messages left their folder', count($after['INBOX'] ?? []) === 1);
        check('message outside the window stayed put', in_array('104', $after['INBOX'] ?? [], true));
        check('archived messages are in the trash', count($after['INBOX.Trash'] ?? []) === 4);

        unset($trashed, $inspector, $wrong);
        gc_collect_cycles();
        proc_terminate($trashProcess);
        proc_close($trashProcess);
    }

    // --- a folder large enough to overshoot the server's line limit ---------
    $bulkPort = 0;
    $bulkProbe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($bulkProbe) {
        $name = stream_socket_get_name($bulkProbe, false);
        $bulkPort = (int) substr($name, strrpos($name, ':') + 1);
        fclose($bulkProbe);
    }

    $bulkProcess = @proc_open(
        'python3 '.escapeshellarg(__DIR__.'/fake-imap-server.py')." $bulkPort --bulk",
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $bulkPipes
    );

    if (is_resource($bulkProcess)) {
        stream_set_timeout($bulkPipes[1], 5);
        fgets($bulkPipes[1]);

        echo "\nLarge folders — uid sets batched below the line limit\n";

        $connect = function (array $extra = []) use ($bulkPort) {
            return new \Mailbxzip\Cli\In\Imap([
                'address' => 'test@mailbxzip.com',
                'host' => '127.0.0.1', 'port' => $bulkPort, 'encryption' => 'none',
                'username' => 'u', 'password' => 'p',
            ] + $extra);
        };

        $uids = $connect()->getEmails()['INBOX.Bulk'] ?? [];
        check('bulk folder large enough to matter', count($uids) === 2000 && strlen(implode(',', $uids)) > 8192);

        $removed = $connect(['trash' => 1])->deleteEmails('INBOX.Bulk', $uids);
        check('every message of a large folder is moved', count($removed) === 2000);

        $after = $connect()->getEmails();
        check('large folder emptied', count($after['INBOX.Bulk'] ?? []) === 0);
        check('large folder landed in the trash', count($after['INBOX.Trash'] ?? []) === 2000);

        proc_terminate($bulkProcess);
        proc_close($bulkProcess);
    }

    // Let the connectors close their sockets while the server is still up,
    // otherwise the teardown logs broken pipe notices.
    unset($imapMailbox, $filteredMailbox);
    gc_collect_cycles();
}

if (isset($process) && is_resource($process)) {
    proc_terminate($process);
    proc_close($process);
}

// ---------------------------------------------------------------- Out/Html ---
echo "\nOut/Html — browsable archive\n";

$dir = workingDir('html');
$cleanup[] = $dir;
(new Mailbox(writeConfig($dir, 'Test', 'Html'), $dir))->start();
$archive = $dir.'/archives/test@mailbxzip.com';

check('one page per message', count(glob($archive.'/INBOX/*.html')) === 3);   // 2 messages + index
check('root index written', is_file($archive.'/index.html'));
check('one index per folder', count(glob($archive.'/INBOX/*/index.html')) === 4);
check('attachment written beside its message', is_file($archive.'/INBOX/Pieces jointes/2024-02-04-pj-Avec-piece-jointe_files/note.txt'));

$page = file_get_contents($archive.'/INBOX/Pieces jointes/2024-02-04-pj-Avec-piece-jointe.html');
check('attachment linked from the page', str_contains($page, 'href="2024-02-04-pj-Avec-piece-jointe_files/note.txt"'));
check('plain text body rendered in the page', str_contains($page, 'Voir le document joint.'));

$root = file_get_contents($archive.'/index.html');
check('root index links every folder', substr_count($root, 'index.html">') === 5);
check('accented folder linked and encoded', str_contains($root, 'INBOX/%C3%89l%C3%A9ments%20envoy%C3%A9s/index.html'));

$folderIndex = file_get_contents($archive.'/INBOX/index.html');
// Count message links only: the "back" link is an .html href too.
check('folder index lists its messages', preg_match_all('/href="(?!\.\.)[^"]+\.html"/', $folderIndex) === 2);
check('folder index links back to the root', str_contains($folderIndex, 'href="../index.html"'));

// A second run must not duplicate the listing.
(new Mailbox('test@mailbxzip.com', $dir))->start();
check('index stable across a resumed run', file_get_contents($archive.'/INBOX/index.html') === $folderIndex);

// ---------------------------------------------------------------- deletion ---
echo "\nDeletion — guarded, and never before the archive exists\n";

$dir = workingDir('delete-guards');
$cleanup[] = $dir;

fails('source unable to delete is refused', function () use ($dir) {
    (new Mailbox(writeConfig($dir, 'Test', 'Eml', ['delete' => 1]), $dir))->start();
}, 'cannot remove messages from its source');

// Asking for the trash is asking for the messages to leave: no delete key
// should be needed, and the guards must still apply.
fails('trash alone triggers the guards', function () use ($dir) {
    (new Mailbox(writeConfig($dir, 'Test', 'Eml', ['trash' => 1]), $dir))->start();
}, "'trash' was asked for");

check('nothing deleted without the delete key', (function () use ($dir) {
    $config = writeConfig($dir, 'Test', 'Eml');
    (new Mailbox($config, $dir))->start();

    return !is_file($dir.'/archives/test@mailbxzip.com/purged_emails.json');
})());

// A purge that does nothing must say why, otherwise the run reads as having
// ignored the request.
$dir = workingDir('purge-diagnostics');
$cleanup[] = $dir;
$archive = $dir.'/archives/test@mailbxzip.com';

(new Mailbox(writeConfig($dir, 'Test', 'Eml', ['delete' => 0, 'trash' => 0]), $dir))->start();
check('keys switched off are reported', str_contains(file_get_contents($archive.'/export.log'), 'ask for nothing'));

// The "already purged" report needs a source that can actually delete, so it
// lives with the IMAP scenarios below.

// ------------------------------------------------------- uid sequence sets ---
echo "\nSequence sets — large uid lists fit in a command\n";

$chunks = ['Mailbxzip\\Cli\\In\\Imap', 'sequenceChunks'];
$render = ['Mailbxzip\\Cli\\In\\Imap', 'renderSet'];

// The shape of a full-folder archive: 4568 uids became a 21 kB command line,
// which no server accepts.
$consecutive = $chunks(range(1, 4568));
check('consecutive uids collapse to one range', count($consecutive) === 1 && $render($consecutive[0]) === '1:4568');

check('isolated uids are listed', $render($chunks([361, 464, 3956])[0]) === '361,464,3956');
check('mixed runs and gaps', $render($chunks([1, 2, 3, 10, 20, 21])[0]) === '1:3,10,20:21');
check('order and duplicates do not matter', $render($chunks([3, 1, 2, 2])[0]) === '1:3');
check('empty list yields no command', $chunks([]) === []);

// Worst case: nothing consecutive, so nothing collapses. Every command must
// still stay under the cap.
$fragmented = $chunks(range(1, 4000, 2));
$longest = max(array_map(fn ($c) => strlen($render($c)), $fragmented));
check('fragmented lists are split into batches', count($fragmented) > 1);
check('no batch exceeds the cap', $longest <= 900);
check('batching loses no uid', array_sum(array_map('count', array_map(fn ($c) => array_merge(...array_map(fn ($r) => range($r[0], $r[1]), $c)), $fragmented))) === 2000);

// ------------------------------------------------------- folder inspection ---
echo "\nFolder inspection — naming the trash is possible at all\n";

$describing = new \Mailbxzip\Cli\In\Test(['address' => 'test@mailbxzip.com']);
$described = $describing->describeFolders();

check('every folder described', count($described) === 6);
check('description carries name, path, count and flags', array_keys($described[0]) === ['name', 'path', 'count', 'flags']);
check('trash advertised through its flag', in_array('Trash', $described[4]['flags'] ?? [], true));
check('trash detected without attempting a deletion', $describing->detectTrashFolder() === 'INBOX/Corbeille');

// The counts follow the date window, like the archive itself.
$windowed = new \Mailbxzip\Cli\In\Test(['address' => 'test@mailbxzip.com', 'since' => '2024-02-01']);
$inbox = array_values(array_filter($windowed->describeFolders(), fn ($f) => $f['name'] === 'INBOX'))[0];
check('counts follow the date window', $inbox['count'] === 0);

// --------------------------------------------------- refused copy reporting ---
$refusePort = 0;
$refuseProbe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($refuseProbe) {
    $name = stream_socket_get_name($refuseProbe, false);
    $refusePort = (int) substr($name, strrpos($name, ':') + 1);
    fclose($refuseProbe);
}

$refuseProcess = @proc_open(
    'python3 '.escapeshellarg(__DIR__.'/fake-imap-server.py')." $refusePort --refuse-copy",
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $refusePipes
);

if (is_resource($refuseProcess)) {
    stream_set_timeout($refusePipes[1], 5);
    fgets($refusePipes[1]);

    echo "\nRefused copy — the run explains itself\n";

    $dir = workingDir('copy-refused');
    $cleanup[] = $dir;
    $archive = $dir.'/archives/test@mailbxzip.com';
    $config = writeConfig($dir, 'Imap', 'Eml', [
        'server' => '{127.0.0.1:'.$refusePort.'/imap/notls}',
        'username' => 'u', 'password' => 'p', 'trash' => 1,
    ]);

    $refused = new Mailbox($config, $dir);
    $refused->start();
    $log = file_get_contents($archive.'/export.log');

    // Which folder was picked is the first thing to know, and nothing else
    // revealed it.
    check('the resolved trash is named', str_contains($log, "trash resolved to 'INBOX/Trash'"));
    check("the server's own words are relayed", str_contains($log, '[OVERQUOTA]'));
    check('a refusal does not abandon the other folders', substr_count($log, 'could not be copied to the trash') >= 2);
    check('nothing recorded as purged', !is_file($archive.'/purged_emails.json'));
    check('the messages are announced as retried', str_contains($log, 'will be retried on the next run'));

    // Nothing moved, so a second run meets the very same messages: the case
    // where everything is already on record as gone.
    copy($archive.'/saved_emails.json', $archive.'/purged_emails.json');
    file_put_contents($archive.'/export.log', '');

    unset($refused);
    gc_collect_cycles();

    $again = new Mailbox($config, $dir);
    $again->start();
    $log = file_get_contents($archive.'/export.log');

    check('already purged is reported', str_contains($log, 'already recorded as removed'));
    check('the report names the remedy', str_contains($log, 'Delete that file'));

    unset($again);
    gc_collect_cycles();
    proc_terminate($refuseProcess);
    proc_close($refuseProcess);
}

// ------------------------------------------------------------------ done ----
foreach ($cleanup as $dir) {
    rmrf($dir);
}

echo "\n$checks checks, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
