<?php
ini_set("pcre.backtrack_limit", "5000000");
require_once 'vendor/autoload.php';

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Command\CompleteCommand;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\TableSeparator;
use Mailbxzip\Cli\Contract\DescribesFoldersInterface;
use Mailbxzip\Cli\In\Gmail;
use Mailbxzip\Cli\Mailbox;

$workingDir = getenv('HOME').'/.config/mailbxzip';
$config = null;

function config($data = null) {
    global $config;
    if (is_null($data)) {
        return $config;
    } elseif (is_array($data) && is_null($config)) {
        $config = $data;
        return $config;
    } elseif (is_string($data)) {
        if (isset($config[$data])) {
            return $config[$data];
        } else {
            return false;
        }
    }
    return $config;
}


class MailbxzipApp extends Application
{
    public function __construct()
    {
        parent::__construct('mailbxzip', '0.1');
    }

    protected function getDefaultCommands(): array
    {
        return [new HelpCommand(), new ListCommand(), new CompleteCommand()];
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--config', '-c', InputOption::VALUE_NONE, 'Specify a config folder, default ~/.config/mailbxzip'),
            new InputOption('--daemon', '-d', InputOption::VALUE_NONE, 'start in background'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display help for the given command. When no command is given display general help'),
            new InputOption('--silent', null, InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Only errors are displayed. All other output is suppressed'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question'),
        ]);
    }
}

class MailboxCommand extends Command
{
    
    private $configFile = null;
    private $mailbxzip = null;

    public function __construct()
    {
        parent::__construct('mailbox');
    }

    protected function configure()
    {
        $this
            ->setDescription('Manage mailbox download process.')
            ->addOption('start', null, InputOption::VALUE_NONE, 'Start the mailbox download')
            ->addOption('stop', null, InputOption::VALUE_NONE, 'Stop the mailbox download')
            ->addOption('pause', null, InputOption::VALUE_NONE, 'Pause the mailbox download')
            ->addArgument('email', InputArgument::REQUIRED, 'config filename');
            
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $workingDir;
        $this->configFile = $input->getArgument('email');
        $this->mailbxzip = new Mailbox($this->configFile, $workingDir);

        if ($input->getOption('start')) {
            $output->writeln('Starting the mailbox download ...');
            $this->mailbxzip->start();
        } elseif ($input->getOption('stop')) {
            $output->writeln('Starting the mailbox...');
            // Add your logic here
        } elseif ($input->getOption('pause')) {
            $output->writeln('Starting the mailbox...');
            // Add your logic here
        } elseif  ($input->getOption('all')) {
            $output->writeln('Starting the mailbox...');
            // Add your logic here
        } else {
            $output->writeln('No command specified. Use --help to see available commands.');
        }

        return Command::SUCCESS;
    }
}

class FoldersCommand extends Command
{
    public function __construct()
    {
        parent::__construct('folders');
    }

    protected function configure()
    {
        $this
            ->setDescription('List the folders of the mailbox, and say which one is its trash.')
            ->setHelp(
                "Folder names travel encoded over IMAP, so they cannot be guessed from\n"
                ."the outside. This lists them as the archive will write them, next to\n"
                ."the raw identifier the server uses. Either spelling is accepted by the\n"
                ."'trash' configuration entry."
            )
            ->addArgument('email', InputArgument::REQUIRED, 'config filename');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $workingDir;

        $handler = (new Mailbox($input->getArgument('email'), $workingDir))->getInputHandler();

        if (!$handler instanceof DescribesFoldersInterface) {
            $output->writeln('<error>The input connector '.get_class($handler).' cannot list its folders.</error>');

            return Command::FAILURE;
        }

        $folders = $handler->describeFolders();

        if ($folders === []) {
            $output->writeln('<comment>No folder found.</comment>');

            return Command::SUCCESS;
        }

        $trash = $handler->detectTrashFolder();
        $encoded = false;
        $rows = [];

        foreach ($folders as $folder) {
            $isTrash = ($folder['path'] === $trash);
            $encoded = $encoded || ($folder['path'] !== $folder['name']);

            $rows[] = [
                $folder['count'],
                $isTrash ? '<info>corbeille</info>' : '',
                $isTrash ? '<info>'.$folder['name'].'</info>' : $folder['name'],
                $folder['path'] === $folder['name'] ? '' : $folder['path'],
                implode(' ', $folder['flags']),
            ];
        }

        $table = new Table($output);
        $table->setHeaders(['Messages', '', 'Nom (pour trash)', 'Chemin IMAP', 'Attributs']);
        $table->setRows($rows);
        $table->render();

        $output->writeln('');

        if (!is_null($trash)) {
            $name = $trash;

            foreach ($folders as $folder) {
                if ($folder['path'] === $trash) {
                    $name = $folder['name'];
                }
            }

            $output->writeln('Corbeille reconnue : <info>'.$name.'</info> — <comment>trash = 1</comment> suffit.');
            $output->writeln('Pour en imposer une autre : <comment>trash = "'.$name.'"</comment>');
        } else {
            $output->writeln('<comment>Aucune corbeille reconnue automatiquement.</comment>');
            $output->writeln('Indiquez-la dans la configuration, avec le nom ou le chemin ci-dessus :');
            $output->writeln('    <comment>trash = "'.$folders[0]['name'].'"</comment>');
        }

        if ($encoded) {
            $output->writeln('');
            $output->writeln('<comment>Les deux écritures sont acceptées par « trash », et « / » remplace le séparateur du serveur.</comment>');
        }

        return Command::SUCCESS;
    }
}

class GmailAuthCommand extends Command
{
    /** How long to wait for the browser to come back, in seconds. */
    private const WAIT = 300;

    public function __construct()
    {
        parent::__construct('gmail-auth');
    }

    protected function configure()
    {
        $this
            ->setDescription('Obtain the Gmail refresh token a configuration needs, once.')
            ->setHelp(
                "The Gmail API takes no password. Create an OAuth client of type\n"
                ."\"Desktop app\" in a Google Cloud project, put its client_id and\n"
                ."client_secret in the configuration, then run this command.\n\n"
                ."It listens on a loopback port and Google sends the authorisation\n"
                ."back to it, so nothing has to be copied by hand. Over SSH, where the\n"
                ."browser cannot reach that port, use --paste instead and hand back the\n"
                ."address the browser was sent to."
            )
            ->addArgument('email', InputArgument::REQUIRED, 'config filename')
            ->addOption('paste', null, InputOption::VALUE_NONE, 'do not listen; paste back the address the browser landed on')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'listen on this port instead of a free one, to forward it over SSH');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $workingDir;

        $file = $workingDir.'/config/'.$input->getArgument('email');

        if (!is_file($file)) {
            $output->writeln("<error>The configuration file '$file' does not exist.</error>");

            return Command::FAILURE;
        }

        $config = parse_ini_file($file);

        foreach (['client_id', 'client_secret'] as $key) {
            if (empty($config[$key])) {
                $output->writeln("<error>The configuration has no '$key'. Create an OAuth client of type \"Desktop app\" under Google Auth Platform > Clients, and copy its credentials there.</error>");

                return Command::FAILURE;
            }
        }

        // Listen first: the port is part of the redirect Google is told about,
        // so it has to be known before the link is built.
        $server = null;

        if (!$input->getOption('paste')) {
            $server = $this->listen($input->getOption('port'), $output);

            if (is_null($server)) {
                return Command::FAILURE;
            }
        }

        $port = is_null($server) ? null : (int) substr(
            stream_socket_get_name($server, false),
            strrpos(stream_socket_get_name($server, false), ':') + 1
        );

        $client = Gmail::client($config + ['refresh_token' => null]);
        $client->setRedirectUri(is_null($port) ? 'http://127.0.0.1:1' : 'http://127.0.0.1:'.$port);

        // Proof key for code exchange: recommended by Google for installed
        // apps, and free to add.
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = $client->createAuthUrl(null, [
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $output->writeln('Open this link and allow access:');
        $output->writeln('');
        $output->writeln('  <info>'.$url.'</info>');
        $output->writeln('');

        $code = is_null($server)
            ? $this->pasted($input, $output)
            : $this->awaited($server, $output);

        if (is_null($code)) {
            return Command::FAILURE;
        }

        $token = $client->fetchAccessTokenWithAuthCode($code, $verifier);

        if (isset($token['error'])) {
            $output->writeln('<error>Google refused the code: '.($token['error_description'] ?? $token['error']).'</error>');

            return Command::FAILURE;
        }

        if (empty($token['refresh_token'])) {
            $output->writeln('<error>Google returned no refresh token. Revoke the access at myaccount.google.com and try again, so the consent screen is shown afresh.</error>');

            return Command::FAILURE;
        }

        // Appended rather than rewritten: the file is the operator's, and
        // nothing else in it should move.
        file_put_contents($file, "\nrefresh_token = \"".$token['refresh_token']."\"\n", FILE_APPEND);

        $output->writeln('<info>Refresh token written to '.$file.'</info>');
        $output->writeln('It does not expire, provided the app is published rather than left in testing.');
        $output->writeln('Keep the file readable by you alone: <comment>chmod 600 '.$file.'</comment>');

        return Command::SUCCESS;
    }

    /**
     * Open the loopback socket Google will send the authorisation back to.
     *
     * @return resource|null
     */
    private function listen($port, OutputInterface $output)
    {
        $port = is_null($port) ? 0 : (int) $port;
        $server = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);

        if (!$server) {
            $output->writeln("<error>Cannot listen on 127.0.0.1:$port ($errstr). Use --port with a free one, or --paste.</error>");

            return null;
        }

        return $server;
    }

    /**
     * Wait for the browser to come back with the authorisation.
     *
     * @param resource $server
     */
    private function awaited($server, OutputInterface $output): ?string
    {
        $output->writeln('Waiting for the browser... (Ctrl-C to give up)');

        $connection = @stream_socket_accept($server, self::WAIT);

        if (!$connection) {
            fclose($server);
            $output->writeln('<error>Nothing came back within '.self::WAIT.' seconds. Over SSH the browser cannot reach this machine: try --paste.</error>');

            return null;
        }

        $request = (string) fread($connection, 8192);
        $code = null;
        $error = null;

        if (preg_match('/^GET\s+(\S+)/', $request, $matches)) {
            $query = [];
            parse_str((string) parse_url($matches[1], PHP_URL_QUERY), $query);
            $code = $query['code'] ?? null;
            $error = $query['error'] ?? null;
        }

        $message = is_null($code)
            ? 'Authorisation failed'.(is_null($error) ? '' : ': '.htmlspecialchars((string) $error))
            : 'Authorisation received. You can close this tab and go back to the terminal.';

        $body = '<!doctype html><meta charset="utf-8"><title>mailbxzip</title>'
            .'<body style="font-family:system-ui;padding:3rem"><p>'.$message.'</p></body>';

        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\n"
            ."Content-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
        fclose($connection);
        fclose($server);

        if (is_null($code)) {
            $output->writeln('<error>'.strip_tags($message).'</error>');

            return null;
        }

        return (string) $code;
    }

    /**
     * Take the authorisation from the address the browser landed on.
     *
     * The way through when the browser runs elsewhere: the page will not
     * load, but the address bar holds the code all the same.
     */
    private function pasted(InputInterface $input, OutputInterface $output): ?string
    {
        $output->writeln('The page will not load -- nothing is listening there. Copy the address it tried');
        $output->writeln('to reach, from the address bar, and paste it below.');
        $output->writeln('');

        $answer = trim((string) $this->getHelper('question')->ask($input, $output, new Question('Address or code: ')));

        if ($answer === '') {
            $output->writeln('<error>Nothing given, the configuration was left alone.</error>');

            return null;
        }

        // A whole address, or just the code lifted out of it.
        if (stripos($answer, 'http') === 0) {
            $query = [];
            parse_str((string) parse_url($answer, PHP_URL_QUERY), $query);

            if (empty($query['code'])) {
                $output->writeln('<error>That address carries no code'.(empty($query['error']) ? '' : ': '.$query['error']).'.</error>');

                return null;
            }

            return (string) $query['code'];
        }

        return $answer;
    }
}

class ConfigCommand extends Command
{

    public function __construct()
    {
        parent::__construct('config');
        $this->generateHelp();
    }

    private function generateHelp()
    {
        $help = "";
        $help .= "Config file format : \n\n";
        $help .= $this->generateHelpFromClass("Mailbxzip\Cli\Mailbox");
        $help .= "\nIN\n";
        // Parcourir le dossier src/In et appeler chaque classe présente dans chaque fichier
        $directory = __DIR__ . '/src/In';
        
        if (is_dir($directory)) {
            $files = scandir($directory);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    
                    $className = pathinfo($file, PATHINFO_FILENAME);
                    $fullClassName = "Mailbxzip\\Cli\\In\\" . $className;
                    if (class_exists($fullClassName)) {
                        
                        $help .= $this->generateHelpFromClass($fullClassName);
                    }
                }
            }
        }
        $help .= "\nOUT\n";
        // Parcourir le dossier src/In et appeler chaque classe présente dans chaque fichier
        $directory = __DIR__ . '/src/Out';
        
        if (is_dir($directory)) {
            $files = scandir($directory);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    
                    $className = pathinfo($file, PATHINFO_FILENAME);
                    $fullClassName = "Mailbxzip\\Cli\\Out\\" . $className;
                    if (class_exists($fullClassName)) {
                        
                        $help .= $this->generateHelpFromClass($fullClassName);
                    }
                }
            }
        }
        $this->setHelp($help);
    }

    private function generateHelpFromClass($className)
    {
        $help = "";
        if(defined("$className::HELP")) {
            $help .= "$className : ".$className::HELP."\n";
        }

        if(defined("$className::MINIMAL_CONFIG_VAR")) {
            $help .= "\tMinimal config :\n";
            foreach ($className::MINIMAL_CONFIG_VAR as $key => $value) {
                $help .= "\t\t$key = $value \n";
            }
            $help .= "\n";
        }
        
        if(defined("$className::CONFIG_VAR")) {
            $help .= "\tOptional config :\n";
            foreach ($className::CONFIG_VAR as $key => $value) {
                $help .= "\t\t$key = $value \n";
            }
            $help .= "\n";
        }
        
        return $help;
    }

    protected function configure()
    {
        $this
            ->setDescription('Manage configurations files.')
            ->addOption('addConfig', null, InputOption::VALUE_NONE, 'Add a new configuration')
            ->addOption('listConfig', null, InputOption::VALUE_NONE, 'List all configurations')
            ->addOption('stateConfig', null, InputOption::VALUE_NONE, 'Show the state of the configuration')
            ->addOption('option', null, InputOption::VALUE_NONE, 'show config file help');
            
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('addConfig')) {
            $output->writeln('Adding a new configuration...');
            // Add your logic here
        } elseif ($input->getOption('start')) {
            $output->writeln('Starting the mailbox...');
            // Add your logic here
        } elseif ($input->getOption('listConfig')) {
            $output->writeln('Listing all configurations...');
            // Add your logic here
        } elseif ($input->getOption('stateConfig')) {
            $output->writeln('Showing the state of the configuration...');
            // Add your logic here
        } elseif ($input->getOption('help')) {
            $output->writeln($this->getHelp());
        } else {
            $output->writeln('No command specified. Use --help to see available commands.');
        }

        return Command::SUCCESS;
    }
}

$application = new MailbxzipApp();
$application->add(new MailboxCommand());
$application->add(new FoldersCommand());
$application->add(new GmailAuthCommand());
$application->add(new ConfigCommand());
$application->run();

