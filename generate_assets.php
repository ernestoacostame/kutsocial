<?php
// Generar imágenes PNG por defecto usando GD
$assetsDir = __DIR__ . '/assets';
if (!is_dir($assetsDir)) {
    mkdir($assetsDir, 0777, true);
}

// 1. Generar default-avatar.png (100x100, Indigo #6366f1)
$avatar = imagecreatetruecolor(100, 100);
$indigo = imagecolorallocate($avatar, 99, 102, 241);
imagefill($avatar, 0, 0, $indigo);
imagepng($avatar, $assetsDir . '/default-avatar.png');
imagedestroy($avatar);

// 2. Generar default-header.png (600x180, Slate #1e293b)
$header = imagecreatetruecolor(600, 180);
$slate = imagecolorallocate($header, 30, 41, 59);
imagefill($header, 0, 0, $slate);
imagepng($header, $assetsDir . '/default-header.png');
imagedestroy($header);

echo "Assets PNG generados correctamente.\n";
