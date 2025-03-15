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
            'htmlBody' => $message->getHtmlContent(),
            'attachments' => []
        ];

        foreach ($message->getAllAttachmentParts() as $attachment) {
            $info['attachments'][] = [
                'filename' => $attachment->getFilename(),
                'contentType' => $attachment->getContentType(),
                'size' => $attachment->getSize()
            ];
        }
        var_dump($info['attachments']);
        return $info;
    }

    public function filename(): string {
        try {
            $data = $this->get();
            $date = new \DateTime($data['date']);
            $formattedDate = $date->format('Y-m-d');

            $from = $data['from'];
            if (preg_match('/<(.+?)>/', $data['from'], $matches)) {
                $from = $matches[1];
            }

            $to = $data['to'];
            if ($from === $this->originAddress) {
                if (preg_match('/<(.+?)>/', $to, $matches)) {
                    $from = $matches[1];
                } else {
                    $from = $to;
                }
            }

            $from = $this->sanitizeFilename($from);

            $filename = sprintf('%s - %s - %d',
                $formattedDate,
                $from,
                $data['subject']
            );

            return $filename;
        } catch (Exception $e) {
            return sprintf('email-%d', $emailNumber);
        }
    }

    private function sanitizeFilename(string $string): string {
        $string = preg_replace('/[^\p{L}\p{N}_.-]/u', '-', $string);
        $string = preg_replace('/-+/', '-', $string);
        $string = substr($string, 0, 50);
        return trim($string, '-');
    }
}
