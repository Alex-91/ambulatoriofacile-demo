<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\CLIRequest;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use App\Libraries\DatabaseConfig;
use Config\Database; // 🔹 IMPORTANTE: Importa Database!

abstract class BaseController extends Controller
{
    protected $request;
    protected $db;
    protected $dbConfig;

    /**
     * An array of helpers to be loaded automatically upon
     * class instantiation. These helpers will be available
     * to all other controllers that extend BaseController.
     *
     * @var list<string>
     */
    protected $helpers = ['crypto'];

    protected function maxUploadFileSizeBytes(): int
    {
        return defined('APP_UPLOAD_MAX_FILE_SIZE_BYTES') ? (int) APP_UPLOAD_MAX_FILE_SIZE_BYTES : (3 * 1024 * 1024);
    }

    protected function maxUploadFileSizeLabel(): string
    {
        if (defined('APP_UPLOAD_MAX_FILE_SIZE_MB')) {
            return (int) APP_UPLOAD_MAX_FILE_SIZE_MB . 'MB';
        }

        return '3MB';
    }

    protected function getUploadedFileClientName(?UploadedFile $file): string
    {
        if ($file === null) {
            return '';
        }

        return trim((string) $file->getClientName());
    }

    protected function isUploadedFileTooLarge(?UploadedFile $file): bool
    {
        if ($file === null) {
            return false;
        }

        $error = (int) $file->getError();
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return true;
        }

        return (int) $file->getSize() > $this->maxUploadFileSizeBytes();
    }

    protected function buildUploadedFileTooLargeMessage(?UploadedFile $file = null): string
    {
        $name  = $this->getUploadedFileClientName($file);
        $limit = $this->maxUploadFileSizeLabel();

        return $name !== ''
            ? 'Il file "' . $name . '" e troppo grosso. Il limite massimo e ' . $limit . '.'
            : 'Il file e troppo grosso. Il limite massimo e ' . $limit . '.';
    }

    protected function buildUploadedFileInvalidMessage(?UploadedFile $file = null): string
    {
        $name = $this->getUploadedFileClientName($file);

        return $name !== ''
            ? 'Errore durante il caricamento del file "' . $name . '".'
            : 'Errore durante il caricamento del file.';
    }

    protected function validateUploadedFilesMaxSize(array $files): void
    {
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            if ((int) $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($this->isUploadedFileTooLarge($file)) {
                throw new \RuntimeException($this->buildUploadedFileTooLargeMessage($file));
            }

            if (!$file->isValid() || $file->hasMoved()) {
                throw new \RuntimeException($this->buildUploadedFileInvalidMessage($file));
            }
        }
    }

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        // 🔹 Inizializza la connessione al database
        $this->db = \Config\Database::connect(); // Assegna alla proprietà della classe
        $this->dbConfig = new DatabaseConfig();
        $this->dbConfig->setEncryptionConfig($this->db);
       
    }
}
