<?php
namespace KutSocial;

use Exception;
use ZipArchive;
use PDO;
use Throwable;

class UpdaterService {
    private string $repo = 'ernestoacostame/kutsocial';
    private string $subdir = 'kutsocial';

    /**
     * Devuelve y asegura la existencia del directorio de actualizaciones en data/updates/
     */
    public function getUpdateDir(): string {
        $dir = dirname(Database::getDbPath()) . '/updates';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Obtiene el token de acceso de GitHub desde las opciones de la base de datos.
     */
    private function getGithubToken(): string {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("SELECT value FROM options WHERE key = 'update_github_token' LIMIT 1");
            $stmt->execute();
            return trim($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Guarda el estado del check en la caché de opciones
     */
    public function saveCache(array $data): void {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("INSERT INTO options (key, value, updated_at) VALUES ('update_cache', ?, datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at");
            $stmt->execute([json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
            $stmtTime = $db->prepare("INSERT INTO options (key, value, updated_at) VALUES ('update_cache_at', ?, datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at");
            $stmtTime->execute([(string)time()]);
        } catch (Throwable $e) {
            // Ignorar errores menores de cache
        }
    }

    /**
     * Consulta la API de GitHub para verificar si hay una nueva versión disponible.
     */
    public function checkVersion(): array {
        $token = $this->getGithubToken();
        if (!$token) {
            return ['error' => 'No se ha configurado el Token de GitHub. Ve a Ajustes Generales para añadirlo.'];
        }

        $url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_USERAGENT => 'KutSocial-Updater/' . \KutSocial\Database::getVersion(),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
            CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($http === 401 || $http === 403) {
            return ['error' => 'Token de GitHub inválido o sin permisos (HTTP ' . $http . ').'];
        }
        if ($http !== 200 || !$resp) {
            return ['error' => "Error al conectar con GitHub (HTTP $http). $err"];
        }

        $release = json_decode($resp, true);
        if (!$release || empty($release['tag_name'])) {
            return ['error' => 'No se encontraron releases en el repositorio.'];
        }

        $remoteVersion = ltrim($release['tag_name'], 'vV');
        $localVersion = \KutSocial\Database::getVersion();

        if (version_compare($remoteVersion, $localVersion, '<=')) {
            $result = ['up_to_date' => true, 'version' => $localVersion];
            $this->saveCache($result);
            return $result;
        }

        // Buscar un ZIP específico de kutsocial en los assets del release
        $zipUrl = '';
        $zipIsAsset = false;
        if (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (preg_match('/^kutsocial.*\.zip$/i', $asset['name'])) {
                    $zipUrl = $asset['url'];
                    $zipIsAsset = true;
                    break;
                }
            }
            if (!$zipUrl) {
                foreach ($release['assets'] as $asset) {
                    if (preg_match('/\.zip$/i', $asset['name'])) {
                        $zipUrl = $asset['url'];
                        $zipIsAsset = true;
                        break;
                    }
                }
            }
        }

        if (!$zipUrl) {
            $zipUrl = $release['zipball_url'] ?? '';
        }

        $result = [
            'available' => true,
            'current_version' => $localVersion,
            'new_version' => $remoteVersion,
            'tag' => $release['tag_name'],
            'name' => $release['name'] ?? $release['tag_name'],
            'changelog' => $release['body'] ?? 'Sin descripción de cambios.',
            'published_at' => $release['published_at'] ?? '',
            'zip_url' => $zipUrl,
            'zip_is_asset' => $zipIsAsset,
            'html_url' => $release['html_url'] ?? '',
        ];

        $this->saveCache($result);
        return $result;
    }

    /**
     * Descarga el archivo ZIP de la release.
     */
    public function download(string $zipUrl): array {
        $token = $this->getGithubToken();
        if (!$token) {
            return ['error' => 'Token de GitHub no configurado.'];
        }
        if (!$zipUrl) {
            return ['error' => 'URL de descarga ZIP no provista.'];
        }

        $destDir = $this->getUpdateDir();
        $destPath = $destDir . '/update-' . date('Ymd_His') . '.zip';

        $fp = fopen($destPath, 'w');
        if (!$fp) {
            return ['error' => 'No se puede escribir en la carpeta de actualizaciones.'];
        }

        $ch = curl_init($zipUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $token",
                'Accept: application/octet-stream',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
            CURLOPT_USERAGENT => 'KutSocial-Updater/' . \KutSocial\Database::getVersion(),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => \KutSocial\Database::verifySsl(),
            CURLOPT_SSL_VERIFYHOST => \KutSocial\Database::verifySsl() ? 2 : 0
        ]);
        curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($http >= 400 || !file_exists($destPath) || filesize($destPath) < 1024) {
            @unlink($destPath);
            return ['error' => "Error al descargar el archivo ZIP (HTTP $http). $err"];
        }

        return ['path' => $destPath, 'size' => filesize($destPath)];
    }

    /**
     * Crea un backup en formato tar.gz de las fuentes actuales.
     */
    public function backup(): array {
        $rootDir = realpath(__DIR__ . '/..');
        $destDir = $this->getUpdateDir();
        $currentVersion = \KutSocial\Database::getVersion();
        $backupName = 'rollback-' . $currentVersion . '-' . date('Ymd_His') . '.tar.gz';
        $backupPath = $destDir . '/' . $backupName;

        // Directorios a excluir (datos dinámicos de usuario)
        $excludes = ['data', '.git', 'scratch'];
        $excludeArgs = '';
        foreach ($excludes as $ex) {
            $excludeArgs .= ' --exclude=' . escapeshellarg('./' . $ex);
        }

        $cmd = sprintf(
            'tar -czf %s -C %s %s .',
            escapeshellarg($backupPath),
            escapeshellarg($rootDir),
            $excludeArgs
        );

        exec($cmd . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($backupPath)) {
            return ['error' => 'Error al crear la copia de seguridad de las fuentes: ' . implode("\n", $output)];
        }

        // Registrar referencias de rollback en la tabla de opciones
        try {
            $db = Database::connect();
            $stmt1 = $db->prepare("INSERT INTO options (key, value, updated_at) VALUES ('update_rollback_path', ?, datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at");
            $stmt1->execute([$backupPath]);
            $stmt2 = $db->prepare("INSERT INTO options (key, value, updated_at) VALUES ('update_rollback_version', ?, datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at");
            $stmt2->execute([$currentVersion]);
        } catch (Throwable $e) {
            // Ignorar fallos de registro de metadatos del backup
        }

        return ['path' => $backupPath, 'size' => filesize($backupPath)];
    }

    /**
     * Aplica la actualización desde el ZIP descargado.
     */
    public function apply(string $zipPath, string $newVersion): array {
        if (!file_exists($zipPath)) {
            return ['error' => 'El archivo ZIP de actualización no existe.'];
        }
        if (!class_exists('ZipArchive')) {
            return ['error' => 'La extensión ZipArchive de PHP no está instalada/habilitada.'];
        }

        $rootDir = realpath(__DIR__ . '/..');
        $currentVersion = \KutSocial\Database::getVersion();

        // 1. Crear backup
        $backup = $this->backup();
        if (!empty($backup['error'])) {
            return $backup;
        }

        // 2. Extraer ZIP a una carpeta temporal
        $tmpDir = $this->getUpdateDir() . '/tmp-' . uniqid();
        @mkdir($tmpDir, 0755, true);

        $zip = new ZipArchive();
        $res = $zip->open($zipPath);
        if ($res !== true) {
            $this->cleanupTmp($tmpDir);
            return ['error' => 'No se pudo abrir el ZIP descargado (código: ' . $res . ')'];
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        // 3. Localizar el directorio raíz de la app dentro del ZIP
        $sourceDir = $this->findAppDir($tmpDir);
        if (!$sourceDir) {
            $this->cleanupTmp($tmpDir);
            return ['error' => 'No se encontró la carpeta raíz de KutSocial ni version.php dentro de la carpeta extraída.'];
        }

        // 4. Carpetas y archivos protegidos (nunca sobrescribir)
        $protected = ['data', '.htaccess', '.git', 'scratch'];

        // 5. Copiar recursivamente
        $copied = 0;
        $errors = [];
        try {
            $copied = $this->copyRecursive($sourceDir, $rootDir, $protected, $errors);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if (!empty($errors)) {
            // Intentar restauración automática desde el backup recién creado
            $this->rollbackFromPath($backup['path']);
            $this->cleanupTmp($tmpDir);
            return ['error' => 'Fallo al copiar archivos. Se restauró el respaldo automáticamente. Errores: ' . implode('; ', array_slice($errors, 0, 5))];
        }

        // 6. Actualizar version.php a nivel de archivos
        $versionContent = "<?php\ndefine('KUTSOCIAL_VERSION', '$newVersion');\n";
        file_put_contents($rootDir . '/version.php', $versionContent);

        // 7. Registrar en el historial de base de datos
        try {
            $db = Database::connect();
            $ins = $db->prepare("INSERT INTO updates (from_version, to_version, status, backup_path) VALUES (?, ?, 'applied', ?)");
            $ins->execute([$currentVersion, $newVersion, $backup['path']]);
        } catch (Throwable $e) {
            // Ignorar si la tabla de updates no se ha migrado aún (se migra a continuación)
        }

        // 8. Limpiar archivos temporales y zip
        $this->cleanupTmp($tmpDir);
        @unlink($zipPath);

        // 9. Ejecutar migraciones
        try {
            Database::runMigrations();
        } catch (Throwable $e) {
            return ['error' => 'Actualización aplicada pero falló la ejecución de las migraciones de base de datos: ' . $e->getMessage()];
        }

        // 10. Actualizar caché para marcar como al día
        $this->saveCache(['up_to_date' => true, 'version' => $newVersion]);

        return ['success' => true, 'from' => $currentVersion, 'to' => $newVersion, 'files_updated' => $copied];
    }

    /**
     * Revierte a una versión anterior usando el backup seleccionado.
     */
    public function rollback(?string $specificFilename = null): array {
        $db = Database::connect();
        if ($specificFilename !== null) {
            if (!preg_match('/^rollback-(.*?)-(\d{8}_\d{6})\.tar\.gz$/', $specificFilename, $matches)) {
                return ['error' => 'Nombre de archivo de backup no válido.'];
            }
            $backupPath = $this->getUpdateDir() . '/' . $specificFilename;
            if (!file_exists($backupPath)) {
                return ['error' => 'El archivo de copia de seguridad seleccionado no existe.'];
            }
            $rollbackVersion = $matches[1];
        } else {
            $stmtPath = $db->prepare("SELECT value FROM options WHERE key = 'update_rollback_path' LIMIT 1");
            $stmtPath->execute();
            $backupPath = $stmtPath->fetchColumn() ?: '';

            $stmtVer = $db->prepare("SELECT value FROM options WHERE key = 'update_rollback_version' LIMIT 1");
            $stmtVer->execute();
            $rollbackVersion = $stmtVer->fetchColumn() ?: '';
        }

        if (!$backupPath || !file_exists($backupPath)) {
            return ['error' => 'No hay copias de seguridad de restauración (rollback) disponibles.'];
        }

        $result = $this->rollbackFromPath($backupPath);
        if (!empty($result['error'])) {
            return $result;
        }

        // Restablecer version.php
        if ($rollbackVersion) {
            $rootDir = realpath(__DIR__ . '/..');
            $versionContent = "<?php\ndefine('KUTSOCIAL_VERSION', '$rollbackVersion');\n";
            file_put_contents($rootDir . '/version.php', $versionContent);
        }

        // Actualizar estado en historial
        try {
            $db->exec("UPDATE updates SET status = 'rolled_back', rolled_back_at = datetime('now') WHERE id = (SELECT id FROM updates ORDER BY id DESC LIMIT 1)");
        } catch (Throwable $e) {}

        // Limpiar referencias en opciones
        try {
            $stmtClear1 = $db->prepare("INSERT INTO options (key, value, updated_at) VALUES ('update_rollback_path', '', datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
            $stmtClear1->execute();
            $stmtClear2 = $db->prepare("INSERT INTO options (key, value, updated_at) VALUES ('update_rollback_version', '', datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
            $stmtClear2->execute();
        } catch (Throwable $e) {}

        // Limpiar cache de verificación
        $this->saveCache(['up_to_date' => true, 'version' => $rollbackVersion]);

        return ['success' => true, 'restored_version' => $rollbackVersion];
    }

    /**
     * Desempaqueta un respaldo tar.gz.
     */
    private function rollbackFromPath(string $backupPath): array {
        if (!file_exists($backupPath)) {
            return ['error' => 'Respaldo no encontrado en disco.'];
        }

        $rootDir = realpath(__DIR__ . '/..');
        $cmd = sprintf(
            'tar -xzf %s -C %s --exclude=./data --exclude=./.git --exclude=./scratch 2>&1',
            escapeshellarg($backupPath),
            escapeshellarg($rootDir)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return ['error' => 'Error de comando al desempaquetar respaldo: ' . implode("\n", $output)];
        }

        return ['success' => true];
    }

    /**
     * Obtiene la lista de rollbacks disponibles en disco.
     */
    public function getAvailableRollbacks(): array {
        $dir = $this->getUpdateDir();
        $files = glob($dir . '/rollback-*.tar.gz');
        if (!$files) {
            return [];
        }

        $rollbacks = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/^rollback-(.*?)-(\d{8}_\d{6})\.tar\.gz$/', $filename, $matches)) {
                $version = $matches[1];
                $datetimeStr = $matches[2];

                $year = substr($datetimeStr, 0, 4);
                $month = substr($datetimeStr, 4, 2);
                $day = substr($datetimeStr, 6, 2);
                $hour = substr($datetimeStr, 9, 2);
                $minute = substr($datetimeStr, 11, 2);
                $second = substr($datetimeStr, 13, 2);
                $formattedDate = "$day/$month/$year $hour:$minute:$second";

                $rollbacks[] = [
                    'filename' => $filename,
                    'path' => $file,
                    'version' => $version,
                    'date' => $formattedDate,
                    'timestamp' => strtotime("$year-$month-$day $hour:$minute:$second"),
                    'size' => filesize($file)
                ];
            }
        }

        usort($rollbacks, function($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        return $rollbacks;
    }

    /**
     * Busca la raíz del código de KutSocial dentro del directorio extraído temporalmente.
     */
    private function findAppDir(string $tmpDir): ?string {
        if (file_exists("$tmpDir/version.php")) {
            return $tmpDir;
        }

        $entries = @scandir($tmpDir);
        if (!$entries) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$tmpDir/$entry";
            if (!is_dir($path)) {
                continue;
            }

            if (file_exists("$path/version.php")) {
                return $path;
            }

            $subdir = $this->subdir;
            if (is_dir("$path/$subdir") && file_exists("$path/$subdir/version.php")) {
                return "$path/$subdir";
            }
        }

        return null;
    }

    /**
     * Copia de forma recursiva los archivos desde el origen al destino, respetando las rutas protegidas.
     */
    private function copyRecursive(string $src, string $dst, array $protected, array &$errors, string $relPath = ''): int {
        $copied = 0;
        $entries = @scandir($src);
        if (!$entries) {
            return 0;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $currentRel = $relPath ? "$relPath/$entry" : $entry;

            if (!$relPath && in_array($entry, $protected, true)) {
                continue;
            }

            $srcPath = "$src/$entry";
            $dstPath = "$dst/$entry";

            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) {
                    if (!@mkdir($dstPath, 0755, true)) {
                        $errors[] = "No se pudo crear el directorio destino: $currentRel";
                        continue;
                    }
                }
                $copied += $this->copyRecursive($srcPath, $dstPath, $protected, $errors, $currentRel);
            } else {
                $ok = @copy($srcPath, $dstPath);
                if ($ok) {
                    @chmod($dstPath, 0644);
                    $copied++;
                } else {
                    $reason = '';
                    if (!is_readable($srcPath)) {
                        $reason = 'origen no legible';
                    } elseif (file_exists($dstPath) && !is_writable($dstPath)) {
                        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($dstPath))['name'] ?? '?') : fileowner($dstPath);
                        $reason = "destino no escribible (propietario: $owner, permisos: " . decoct(fileperms($dstPath) & 0777) . ")";
                    } elseif (!is_writable(dirname($dstPath))) {
                        $reason = 'directorio contenedor del destino no escribible';
                    } else {
                        $err = error_get_last();
                        $reason = $err['message'] ?? 'error no especificado';
                    }
                    $errors[] = "Error al copiar '$currentRel': $reason";
                }
            }
        }

        return $copied;
    }

    /**
     * Limpieza recursiva de un directorio temporal.
     */
    private function cleanupTmp(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $entries = @scandir($dir);
        if (!$entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            if (is_dir($path)) {
                $this->cleanupTmp($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
