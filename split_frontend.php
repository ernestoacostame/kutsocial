<?php
$frontendHtmlPath = __DIR__ . '/src/views/frontend.html';
if (!file_exists($frontendHtmlPath)) {
    die("frontend.html no existe.\n");
}
$content = file_get_contents($frontendHtmlPath);

// 1. Extraer CSS
$styleStart = strpos($content, '<style>');
$styleEnd = strpos($content, '</style>');
if ($styleStart === false || $styleEnd === false) {
    die("No se encontró el bloque <style> en frontend.html\n");
}
$styleContent = substr($content, $styleStart + 7, $styleEnd - $styleStart - 7);
file_put_contents(__DIR__ . '/src/views/frontend/frontend.css', trim($styleContent));
echo "Extraído frontend.css (" . strlen($styleContent) . " bytes)\n";

// 2. Extraer JS
$scriptStart = strpos($content, '<script>', $styleEnd);
$scriptEnd = strrpos($content, '</script>');
if ($scriptStart === false || $scriptEnd === false) {
    die("No se encontró el bloque <script> principal en frontend.html\n");
}
$scriptContent = substr($content, $scriptStart + 8, $scriptEnd - $scriptStart - 8);
file_put_contents(__DIR__ . '/src/views/frontend/frontend.js', trim($scriptContent));
echo "Extraído frontend.js (" . strlen($scriptContent) . " bytes)\n";

// 3. Crear el nuevo frontend.html limpio
$newContent = substr($content, 0, $styleStart) . 
              "<style>\n    <?php include __DIR__ . '/frontend/frontend.css'; ?>\n    </style>" .
              substr($content, $styleEnd + 8, $scriptStart - ($styleEnd + 8)) .
              "<script>\n    <?php include __DIR__ . '/frontend/frontend.js'; ?>\n    </script>" .
              substr($content, $scriptEnd + 9);

file_put_contents($frontendHtmlPath, $newContent);
echo "Actualizado frontend.html (" . strlen($newContent) . " bytes)\n";
