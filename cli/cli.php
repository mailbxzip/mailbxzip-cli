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
                    var_dump($fullClassName);
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
                    var_dump($fullClassName);
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
$application->add(new ConfigCommand());
$application->run();

