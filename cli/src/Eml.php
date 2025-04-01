<?php
namespace Mailbxzip\Cli;

class Eml {
    private $content;
    private $folder;
    private $uid;
    private $originAddress;
    private $get;

    // Constructeur qui prend le contenu du fichier EML en paramètre
    public function __construct($emlContent, $folder = 'INBOX', $uid = '0', $originAddress = null) {
        $this->content = $emlContent;
        $this->folder = $folder;
        $this->uid = $uid;
        $this->originAddress = $originAddress;
        $this->get = $this->decode();
    }

    // Méthode pour obtenir le contenu du fichier EML
    public function getContent() {
        return $this->content;
    }

    // Méthode pour extraire les en-têtes du fichier EML
    public function getHeaders() {
        $headers = [];
        $lines = explode("\n", $this->content);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            } else {
                break;
            }
        }
        return $headers;
    }

    // Méthode pour extraire le corps du fichier EML
    public function getBody() {
        $parts = explode("\n\n", $this->content, 2);
        return isset($parts[1]) ? $parts[1] : '';
    }

    public function getFolder() {
        return $this->folder;
    }

    public function view($viewName) {
        return View::R($viewName, $this->get());
    }

    public function get() {
        return $this->get;
    }

    public function getAttachments() {
        return $this->get['attachments'];
    }

    // Méthode pour obtenir les informations du fichier EML et les stocker dans un tableau associatif
    private function decode() {
        $parser = new \ZBateson\MailMimeParser\MailMimeParser();
        $message = $parser->parse($this->content, false);

        $info = [
            'subject' => $message->getHeaderValue('subject'),
            'from' => $message->getHeaderValue('from'),
            'to' => $message->getHeaderValue('to'),
            'cc' => $message->getHeaderValue('cc'),
            'bcc' => $message->getHeaderValue('bcc'),
            'date' => $message->getHeaderValue('date'),
            'body' => $message->getTextContent(),
            'htmlBody' => $this->repairHtmlContent($message->getHtmlContent()),
            'attachments' => []
        ];

        foreach ($message->getAllAttachmentParts() as $attachment) {
            $content = $attachment->getContent();
            $size = strlen($content);

            if (!is_null($attachment->getFilename())) {
                $info['attachments'][] = [
                    'filename' => $this->sanitizeFilename($attachment->getFilename()),
                    'contentType' => $attachment->getContentType(),
                    'size' => $size,
                    'humanSize' => $this->formatSizeUnits($size),
                    'content' => $content
                ];
            }
        }
        return $info;
    }

    private function repairHtmlContent($htmlContent) {
        // Vérifier si le contenu HTML est une chaîne vide
        if (empty($htmlContent)) {
            return $htmlContent;
        }

        // Créer une nouvelle instance de Dom\HTMLDocument
        $doc = new \DOMDocument();

        // Charger le contenu HTML
        @$doc->loadHTML($htmlContent);

        // Exporter le contenu HTML réparé
        return $doc->saveHTML();
    }

    private function formatSizeUnits($bytes) {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2) . ' Go';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2) . ' Mo';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2) . ' Ko';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' octets';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' octet';
        } else {
            $bytes = '0 octets';
        }

        return $bytes;
    }

    public function filename(): string {
        try {
            $data = $this->get();

            if(substr($data['date'], -2) == 'UT') {
                $data['date'] .= 'C';
            }
            $date = new \DateTime($data['date']);
            $formattedDate = $date->format('Y-m-d');

            $from = $data['from'] ?? '';
            if (preg_match('/<(.+?)>/', $data['from'], $matches)) {
                $from = $matches[1];
            }

            $to = $data['to'] ?? '';
            if ($from === $this->originAddress) {
                if (preg_match('/<(.+?)>/', $to, $matches)) {
                    $from = $matches[1];
                } else {
                    $from = $to;
                }
            }

            $from = explode('@', $from)[0];
            $from = $this->sanitizeFilename($from);

            $filename = sprintf('%s - %s - %s',
                $formattedDate,
                $from,
                $data['subject']
            );

            return $this->sanitizeFilename($filename);
        } catch (Exception $e) {
            return sprintf('email-%d', $this->uid);
        }
    }

    private function sanitizeFilename(string $string): string {
        $string = preg_replace('/[^\p{L}\p{N}_.-]/u', '-', $string);
        $string = preg_replace('/-+/', '-', $string);
        $string = substr($string, 0, 50);
        return trim($string, '-');
    }
}
