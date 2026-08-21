<?php

declare(strict_types=1);

namespace PHPAML\Http;

use RuntimeException;

final class UploadedFile
{
    /** @param array{name?:string,tmp_name?:string,error?:int,size?:int,type?:string} $file */
    public function __construct(private array $file) {}

    /** @param list<string> $allowedMimeTypes */
    public function store(string $directory, array $allowedMimeTypes, int $maximumBytes = 5242880): string
    {
        $error = (int) ($this->file['error'] ?? UPLOAD_ERR_NO_FILE);
        $temporary = (string) ($this->file['tmp_name'] ?? '');
        $size = (int) ($this->file['size'] ?? 0);
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_file($temporary)) throw new RuntimeException('Téléversement invalide.');
        if ($size < 1 || $size > $maximumBytes) throw new RuntimeException('Taille de fichier non autorisée.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: 'application/octet-stream';
        if (!in_array($mime, $allowedMimeTypes, true)) throw new RuntimeException('Type de fichier non autorisé.');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) throw new RuntimeException('Dossier de stockage inaccessible.');
        $extension = $this->safeExtension($mime);
        $name = bin2hex(random_bytes(20)) . ($extension === '' ? '' : '.' . $extension);
        $destination = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        $moved = is_uploaded_file($temporary) ? move_uploaded_file($temporary, $destination) : rename($temporary, $destination);
        if (!$moved) throw new RuntimeException('Impossible de stocker le fichier.');
        chmod($destination, 0644);
        return $name;
    }

    private function safeExtension(string $mime): string
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf', 'text/plain' => 'txt'][$mime] ?? '';
    }
}
