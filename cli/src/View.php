<?php
namespace Mailbxzip\Cli;

use Exception;
use RuntimeException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class View {

    public static function R($templateName, $data = []) {
        // Specify the path to the templates
        $loader = new FilesystemLoader('views');

        // Initialize Twig environment
        $twig = new Environment($loader);

        // Render the template with the provided data
        return $twig->render($templateName, $data);
    }
}
