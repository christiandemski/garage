<?php
declare(strict_types=1);

// Liefert eigene Uploads aus (Fotos, Dokumente) – nur für den angemeldeten Besitzer
$f = basename((string)($_GET['f'] ?? ''));
if (!preg_match('/^[a-f0-9]{24}\.[a-z]{2,5}$/', $f)) not_found();

$owns = val('SELECT 1 FROM vehicles WHERE user_id = ? AND photo = ?', [uid(), $f])
    ?: val('SELECT 1 FROM documents d JOIN vehicles v ON v.id = d.vehicle_id WHERE v.user_id = ? AND d.stored_name = ?', [uid(), $f]);
$path = upload_dir() . '/' . $f;
if (!$owns || !is_file($path)) not_found();

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
$name = (string)($_GET['name'] ?? $f);
$inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';
session_write_close();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);
exit;
