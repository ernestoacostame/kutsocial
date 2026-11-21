#!/usr/bin/env bash
# ============================================================================
# KutSocial · Asistente Interactivo de Migración de Base de Datos
# Soporta todas las direcciones entre: SQLite <-> PostgreSQL <-> MariaDB/MySQL
# ============================================================================

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
CONFIG_FILE="${ROOT_DIR}/config.php"
DATA_MIGRATOR="${SCRIPT_DIR}/migrate-db-data.php"

TITLE="KutSocial · Migrador de Base de Datos"

# ----------------------------------------------------------------------------
# 1. Verificar privilegios de root
# ----------------------------------------------------------------------------
if [[ "${EUID}" -ne 0 ]]; then
    if command -v sudo >/dev/null 2>&1; then
        echo "Se requieren permisos de administrador (root) para instalar paquetes y gestionar servicios."
        exec sudo -E bash "$0" "$@"
    else
        echo "Error: Ejecuta este asistente como root (por ejemplo: su -c '$0')." >&2
        exit 1
    fi
fi

# ----------------------------------------------------------------------------
# 2. Detectar backend de interfaz gráfica en terminal (whiptail / dialog / ANSI)
# ----------------------------------------------------------------------------
UI_BACKEND="ansi"
if command -v whiptail >/dev/null 2>&1; then
    UI_BACKEND="whiptail"
elif command -v dialog >/dev/null 2>&1; then
    UI_BACKEND="dialog"
fi

ui_msg() {
    local text="$1"
    if [[ "${UI_BACKEND}" == "whiptail" ]]; then
        whiptail --title "${TITLE}" --msgbox "${text}" 16 72
    elif [[ "${UI_BACKEND}" == "dialog" ]]; then
        dialog --title "${TITLE}" --msgbox "${text}" 16 72
        clear
    else
        echo ""
        echo "=================================================================="
        echo "  ${TITLE}"
        echo "=================================================================="
        echo -e "${text}"
        echo "------------------------------------------------------------------"
        read -rp "Presiona [Enter] para continuar..." _
    fi
}

ui_yesno() {
    local text="$1"
    if [[ "${UI_BACKEND}" == "whiptail" ]]; then
        whiptail --title "${TITLE}" --yesno "${text}" 14 72
        return $?
    elif [[ "${UI_BACKEND}" == "dialog" ]]; then
        dialog --title "${TITLE}" --yesno "${text}" 14 72
        local rc=$?
        clear
        return $rc
    else
        echo ""
        echo -e "${text}"
        read -rp "¿Continuar? [S/n]: " ans
        [[ -z "${ans}" || "${ans}" =~ ^[SsYy]$ ]]
    fi
}

ui_menu() {
    local prompt="$1"
    shift
    local -a items=("$@")
    if [[ "${UI_BACKEND}" == "whiptail" ]]; then
        whiptail --title "${TITLE}" --menu "${prompt}" 16 72 6 "${items[@]}" 3>&1 1>&2 2>&3
        return $?
    elif [[ "${UI_BACKEND}" == "dialog" ]]; then
        local choice
        choice=$(dialog --title "${TITLE}" --menu "${prompt}" 16 72 6 "${items[@]}" 3>&1 1>&2 2>&3)
        local rc=$?
        clear
        printf '%s' "${choice}"
        return $rc
    else
        echo "" >&2
        echo "${prompt}" >&2
        local i=0
        local -a keys=()
        while [[ $i -lt ${#items[@]} ]]; do
            local k="${items[$i]}"
            local v="${items[$((i+1))]}"
            keys+=("$k")
            echo "  $(( ${#keys[@]} ))) [${k}] ${v}" >&2
            i=$((i+2))
        done
        local sel
        while true; do
            read -rp "Elige una opción (1-${#keys[@]}): " sel >&2
            if [[ "${sel}" =~ ^[0-9]+$ ]] && (( sel >= 1 && sel <= ${#keys[@]} )); then
                printf '%s' "${keys[$((sel-1))]}"
                return 0
            fi
            echo "Opción inválida." >&2
        done
    fi
}

ui_input() {
    local prompt="$1"
    local default_val="$2"
    if [[ "${UI_BACKEND}" == "whiptail" ]]; then
        whiptail --title "${TITLE}" --inputbox "${prompt}" 11 72 "${default_val}" 3>&1 1>&2 2>&3
        return $?
    elif [[ "${UI_BACKEND}" == "dialog" ]]; then
        local out
        out=$(dialog --title "${TITLE}" --inputbox "${prompt}" 11 72 "${default_val}" 3>&1 1>&2 2>&3)
        local rc=$?
        clear
        printf '%s' "${out}"
        return $rc
    else
        local out
        read -rp "${prompt} [${default_val}]: " out >&2
        printf '%s' "${out:-$default_val}"
    fi
}

ui_password() {
    local prompt="$1"
    local default_val="$2"
    if [[ "${UI_BACKEND}" == "whiptail" ]]; then
        whiptail --title "${TITLE}" --inputbox "${prompt}" 11 72 "${default_val}" 3>&1 1>&2 2>&3
        return $?
    elif [[ "${UI_BACKEND}" == "dialog" ]]; then
        local out
        out=$(dialog --title "${TITLE}" --inputbox "${prompt}" 11 72 "${default_val}" 3>&1 1>&2 2>&3)
        local rc=$?
        clear
        printf '%s' "${out}"
        return $rc
    else
        local out
        read -rp "${prompt} [${default_val}]: " out >&2
        printf '%s' "${out:-$default_val}"
    fi
}

driver_label() {
    case "$1" in
        sqlite) echo "SQLite" ;;
        pgsql)  echo "PostgreSQL" ;;
        mysql)  echo "MariaDB / MySQL" ;;
        *)      echo "$1" ;;
    esac
}

# ----------------------------------------------------------------------------
# 3. Verificaciones iniciales (PHP, config.php, migrador PHP)
# ----------------------------------------------------------------------------
if ! command -v php >/dev/null 2>&1; then
    ui_msg "Error: PHP CLI no está instalado o no se encuentra en el PATH."
    exit 1
fi

if [[ ! -f "${CONFIG_FILE}" ]]; then
    ui_msg "Error: No se encontró config.php en:\n${CONFIG_FILE}\n\nAsegúrate de haber instalado KutSocial antes de migrar la base de datos."
    exit 1
fi

if [[ ! -f "${DATA_MIGRATOR}" ]]; then
    ui_msg "Error: No se encontró el script migrador:\n${DATA_MIGRATOR}"
    exit 1
fi

# Leer configuración actual de config.php usando PHP
read_current_config() {
    php -r '
        $cfgFile = $argv[1];
        require_once $cfgFile;
        $driver = defined("KUTSOCIAL_DB_DRIVER") && KUTSOCIAL_DB_DRIVER !== "" ? strtolower(trim((string)KUTSOCIAL_DB_DRIVER)) : "sqlite";
        if ($driver === "postgres" || $driver === "postgresql") $driver = "pgsql";
        if ($driver === "mariadb") $driver = "mysql";
        $data = [
            "driver" => $driver,
            "path"   => defined("KUTSOCIAL_DB_PATH") ? (string)KUTSOCIAL_DB_PATH : (dirname($cfgFile) . "/data/kutsocial.db"),
            "host"   => defined("KUTSOCIAL_DB_HOST") ? (string)KUTSOCIAL_DB_HOST : "127.0.0.1",
            "port"   => defined("KUTSOCIAL_DB_PORT") ? (string)KUTSOCIAL_DB_PORT : ($driver === "pgsql" ? "5432" : ($driver === "mysql" ? "3306" : "")),
            "name"   => defined("KUTSOCIAL_DB_NAME") ? (string)KUTSOCIAL_DB_NAME : "kutsocial",
            "user"   => defined("KUTSOCIAL_DB_USER") ? (string)KUTSOCIAL_DB_USER : "kutsocial",
            "pass"   => defined("KUTSOCIAL_DB_PASS") ? (string)KUTSOCIAL_DB_PASS : "",
        ];
        foreach ($data as $k => $v) {
            echo $k . "=" . base64_encode($v) . "\n";
        }
    ' "${CONFIG_FILE}"
}

declare -A SRC_CFG
while IFS='=' read -r k b64; do
    [[ -z "$k" ]] && continue
    SRC_CFG["$k"]="$(printf '%s' "$b64" | base64 -d)"
done < <(read_current_config)

SRC_DRIVER="${SRC_CFG[driver]}"
SRC_LABEL="$(driver_label "${SRC_DRIVER}")"

if [[ "${SRC_DRIVER}" == "sqlite" ]]; then
    SRC_INFO="Archivo: ${SRC_CFG[path]}"
else
    SRC_INFO="Host: ${SRC_CFG[host]}:${SRC_CFG[port]} | BD: ${SRC_CFG[name]} | Usuario: ${SRC_CFG[user]}"
fi

# ----------------------------------------------------------------------------
# 4. Seleccionar motor de destino (las otras 2 opciones)
# ----------------------------------------------------------------------------
MENU_OPTS=()
if [[ "${SRC_DRIVER}" != "pgsql" ]]; then
    MENU_OPTS+=("pgsql" "PostgreSQL (recomendado para producción)")
fi
if [[ "${SRC_DRIVER}" != "mysql" ]]; then
    MENU_OPTS+=("mysql" "MariaDB / MySQL")
fi
if [[ "${SRC_DRIVER}" != "sqlite" ]]; then
    MENU_OPTS+=("sqlite" "SQLite (archivo local sin servidor)")
fi

DST_DRIVER=$(ui_menu "Motor actual detectado: ${SRC_LABEL}\n(${SRC_INFO})\n\nSelecciona el motor de destino:" "${MENU_OPTS[@]}") || exit 0
DST_LABEL="$(driver_label "${DST_DRIVER}")"

# ----------------------------------------------------------------------------
# 5. Detección de distribución Linux y paquetes necesarios
# ----------------------------------------------------------------------------
DISTRO_FAMILY="unknown"
if [[ -f /etc/os-release ]]; then
    # shellcheck disable=SC1091
    . /etc/os-release
    OS_CHECK="${ID:-} ${ID_LIKE:-}"
    if [[ "${OS_CHECK}" =~ (debian|ubuntu|linuxmint|pop) ]]; then
        DISTRO_FAMILY="debian"
    elif [[ "${OS_CHECK}" =~ (fedora|rhel|rocky|almalinux|centos) ]]; then
        DISTRO_FAMILY="rhel"
    elif [[ "${OS_CHECK}" =~ (arch|manjaro|endeavouros) ]]; then
        DISTRO_FAMILY="arch"
    elif [[ "${OS_CHECK}" =~ alpine ]]; then
        DISTRO_FAMILY="alpine"
    elif [[ "${OS_CHECK}" =~ (suse|opensuse) ]]; then
        DISTRO_FAMILY="suse"
    fi
fi

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_VER_NODOT="${PHP_VER//./}"

has_pdo_driver() {
    local drv="$1"
    php -m 2>/dev/null | grep -qiE "^pdo_${drv}$"
}

need_install_packages() {
    local need=0
    has_pdo_driver "${SRC_DRIVER}" || need=1
    has_pdo_driver "${DST_DRIVER}" || need=1
    if [[ "${DST_DRIVER}" == "pgsql" ]] && ! command -v psql >/dev/null 2>&1; then
        need=1
    elif [[ "${DST_DRIVER}" == "mysql" ]] && ! command -v mariadb >/dev/null 2>&1 && ! command -v mysql >/dev/null 2>&1; then
        need=1
    elif [[ "${DST_DRIVER}" == "sqlite" ]] && ! command -v sqlite3 >/dev/null 2>&1; then
        need=1
    fi
    return $need
}

install_packages() {
    local -a pkgs=()

    add_php_pdo_pkg() {
        local drv="$1"
        case "${DISTRO_FAMILY}" in
            debian)
                if [[ "$drv" == "sqlite" ]]; then
                    pkgs+=("php${PHP_VER}-sqlite3")
                else
                    pkgs+=("php${PHP_VER}-${drv}")
                fi
                ;;
            rhel)
                if [[ "$drv" == "mysql" ]]; then
                    pkgs+=("php-mysqlnd")
                elif [[ "$drv" == "pgsql" ]]; then
                    pkgs+=("php-pgsql")
                else
                    pkgs+=("php-pdo")
                fi
                ;;
            arch)
                if [[ "$drv" == "pgsql" ]]; then
                    pkgs+=("php-pgsql")
                elif [[ "$drv" == "sqlite" ]]; then
                    pkgs+=("php-sqlite")
                fi
                ;;
            alpine)
                pkgs+=("php${PHP_VER_NODOT}-pdo_${drv}")
                ;;
            suse)
                if [[ "$drv" == "sqlite" ]]; then
                    pkgs+=("php8-sqlite")
                else
                    pkgs+=("php8-${drv}")
                fi
                ;;
        esac
    }

    has_pdo_driver "${SRC_DRIVER}" || add_php_pdo_pkg "${SRC_DRIVER}"
    has_pdo_driver "${DST_DRIVER}" || add_php_pdo_pkg "${DST_DRIVER}"

    case "${DST_DRIVER}" in
        pgsql)
            case "${DISTRO_FAMILY}" in
                debian) pkgs+=("postgresql" "postgresql-client") ;;
                rhel)   pkgs+=("postgresql-server" "postgresql") ;;
                arch)   pkgs+=("postgresql") ;;
                alpine) pkgs+=("postgresql" "postgresql-contrib") ;;
                suse)   pkgs+=("postgresql-server" "postgresql") ;;
            esac
            ;;
        mysql)
            case "${DISTRO_FAMILY}" in
                debian) pkgs+=("mariadb-server" "mariadb-client") ;;
                rhel)   pkgs+=("mariadb-server" "mariadb") ;;
                arch)   pkgs+=("mariadb") ;;
                alpine) pkgs+=("mariadb" "mariadb-client") ;;
                suse)   pkgs+=("mariadb") ;;
            esac
            ;;
        sqlite)
            case "${DISTRO_FAMILY}" in
                debian|suse) pkgs+=("sqlite3") ;;
                rhel|arch|alpine) pkgs+=("sqlite") ;;
            esac
            ;;
    esac

    if [[ ${#pkgs[@]} -eq 0 ]]; then
        return 0
    fi

    echo ""
    echo "-> Instalando paquetes necesarios (${DISTRO_FAMILY}): ${pkgs[*]}"

    case "${DISTRO_FAMILY}" in
        debian)
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -qq && apt-get install -y "${pkgs[@]}"
            ;;
        rhel)
            dnf install -y "${pkgs[@]}"
            ;;
        arch)
            pacman -Sy --noconfirm --needed "${pkgs[@]}"
            ;;
        alpine)
            apk add --no-cache "${pkgs[@]}"
            ;;
        suse)
            zypper --non-interactive install "${pkgs[@]}"
            ;;
        *)
            ui_msg "No se pudo identificar el gestor de paquetes automáticamente.\nInstala manualmente el servidor ${DST_LABEL} y la extensión PHP PDO correspondiente (${pkgs[*]})."
            return 1
            ;;
    esac
}

ensure_db_service() {
    if [[ "${DST_DRIVER}" == "sqlite" ]]; then
        return 0
    fi

    if [[ "${DST_DRIVER}" == "pgsql" ]]; then
        # Inicializar cluster si es necesario (RHEL/Fedora o Arch)
        if [[ "${DISTRO_FAMILY}" == "rhel" ]] && [[ ! -f /var/lib/pgsql/data/PG_VERSION ]]; then
            postgresql-setup --initdb || true
        elif [[ "${DISTRO_FAMILY}" == "arch" ]] && [[ ! -f /var/lib/postgres/data/PG_VERSION ]]; then
            su - postgres -c "initdb -D /var/lib/postgres/data" || true
        fi

        if command -v systemctl >/dev/null 2>&1; then
            systemctl enable --now postgresql
        elif command -v rc-service >/dev/null 2>&1; then
            rc-update add postgresql default 2>/dev/null || true
            rc-service postgresql start
        elif command -v service >/dev/null 2>&1; then
            service postgresql start
        fi
    elif [[ "${DST_DRIVER}" == "mysql" ]]; then
        if [[ "${DISTRO_FAMILY}" == "arch" ]] && [[ ! -d /var/lib/mysql/mysql ]]; then
            mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql || true
        elif [[ "${DISTRO_FAMILY}" == "alpine" ]] && [[ ! -d /var/lib/mysql/mysql ]]; then
            /etc/init.d/mariadb setup || true
        fi

        if command -v systemctl >/dev/null 2>&1; then
            systemctl enable --now mariadb 2>/dev/null || systemctl enable --now mysql
        elif command -v rc-service >/dev/null 2>&1; then
            rc-update add mariadb default 2>/dev/null || true
            rc-service mariadb start
        elif command -v service >/dev/null 2>&1; then
            service mariadb start 2>/dev/null || service mysql start
        fi
    fi
}

if need_install_packages; then
    if ui_yesno "Se detectó Linux (${DISTRO_FAMILY}) con PHP ${PHP_VER}.\n\nEs necesario instalar los paquetes del servidor ${DST_LABEL} y los drivers PHP PDO faltantes.\n\n¿Deseas instalarlos automáticamente ahora?"; then
        if ! install_packages; then
            ui_msg "Error al instalar los paquetes. Revisa la salida en la terminal."
            exit 1
        fi
    else
        ui_msg "No se puede continuar sin los paquetes y drivers PDO necesarios."
        exit 1
    fi
fi

ensure_db_service

# Verificar nuevamente drivers PDO después de la instalación
if ! has_pdo_driver "${SRC_DRIVER}" || ! has_pdo_driver "${DST_DRIVER}"; then
    ui_msg "Error: Faltan extensiones PHP PDO activas.\nVerifica que pdo_${SRC_DRIVER} y pdo_${DST_DRIVER} estén habilitadas en PHP CLI."
    exit 1
fi

# ----------------------------------------------------------------------------
# 6. Configurar credenciales y crear BD/usuario de destino
# ----------------------------------------------------------------------------
DST_HOST="127.0.0.1"
DST_PORT=""
DST_NAME="kutsocial"
DST_USER="kutsocial"
DST_PASS=""
DST_PATH=""

if [[ "${DST_DRIVER}" == "sqlite" ]]; then
    DEFAULT_SQLITE_PATH="${ROOT_DIR}/data/kutsocial.db"
    DST_PATH=$(ui_input "Ruta del archivo SQLite de destino:" "${DEFAULT_SQLITE_PATH}") || exit 0
    if [[ -f "${DST_PATH}" ]]; then
        BAK_SQLITE="${DST_PATH}.bak.$(date +%Y%m%d%H%M%S)"
        cp -a "${DST_PATH}" "${BAK_SQLITE}"
        echo "-> Respaldo de SQLite existente guardado en: ${BAK_SQLITE}"
    fi
else
    [[ "${DST_DRIVER}" == "pgsql" ]] && DST_PORT="5432" || DST_PORT="3306"
    DST_PASS="$(openssl rand -base64 18 2>/dev/null | tr -dc 'A-Za-z0-9' | head -c 20)"
    if [[ ${#DST_PASS} -lt 16 ]]; then
        DST_PASS="$(tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 20)"
    fi

    if ! ui_yesno "Configuración automática para ${DST_LABEL}:\n\n  Host:       ${DST_HOST}\n  Puerto:     ${DST_PORT}\n  Base datos: ${DST_NAME}\n  Usuario:    ${DST_USER}\n  Contraseña: ${DST_PASS}\n\n¿Aceptar estos valores recomendados?\n(Elige 'No' para personalizarlos)"; then
        DST_HOST=$(ui_input "Host de ${DST_LABEL}:" "${DST_HOST}") || exit 0
        DST_PORT=$(ui_input "Puerto de ${DST_LABEL}:" "${DST_PORT}") || exit 0
        DST_NAME=$(ui_input "Nombre de la base de datos:" "${DST_NAME}") || exit 0
        DST_USER=$(ui_input "Usuario de la base de datos:" "${DST_USER}") || exit 0
        DST_PASS=$(ui_password "Contraseña para '${DST_USER}':" "${DST_PASS}") || exit 0
    fi

    echo ""
    echo "-> Creando base de datos '${DST_NAME}' y usuario '${DST_USER}' en ${DST_LABEL}..."

    if [[ "${DST_DRIVER}" == "pgsql" ]]; then
        # Escapar comillas simples para SQL
        ESC_PASS="${DST_PASS//\'/\'\'}"
        ESC_USER="${DST_USER//\"/\"\"}"
        ESC_DB="${DST_NAME//\"/\"\"}"

        su - postgres -c "psql -v ON_ERROR_STOP=1" <<EOSQL
DO \$\$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '${DST_USER//\'/\'\'}') THEN
        CREATE ROLE "${ESC_USER}" WITH LOGIN PASSWORD '${ESC_PASS}';
    ELSE
        ALTER ROLE "${ESC_USER}" WITH LOGIN PASSWORD '${ESC_PASS}';
    END IF;
END
\$\$;
EOSQL
        if ! su - postgres -c "psql -tAc \"SELECT 1 FROM pg_database WHERE datname='${DST_NAME//\'/\'\'}'\"" | grep -q 1; then
            su - postgres -c "psql -v ON_ERROR_STOP=1 -c \"CREATE DATABASE \\\"${ESC_DB}\\\" OWNER \\\"${ESC_USER}\\\";\""
        fi
        su - postgres -c "psql -v ON_ERROR_STOP=1 -d \"${DST_NAME}\" -c \"GRANT ALL PRIVILEGES ON DATABASE \\\"${ESC_DB}\\\" TO \\\"${ESC_USER}\\\"; ALTER SCHEMA public OWNER TO \\\"${ESC_USER}\\\"; GRANT ALL ON SCHEMA public TO \\\"${ESC_USER}\\\";\""

    elif [[ "${DST_DRIVER}" == "mysql" ]]; then
        MYSQL_BIN="mariadb"
        command -v mariadb >/dev/null 2>&1 || MYSQL_BIN="mysql"

        ESC_PASS="${DST_PASS//\'/\'\'}"
        ${MYSQL_BIN} -u root <<EOSQL
CREATE DATABASE IF NOT EXISTS \`${DST_NAME//\`/\`\`}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DST_USER//\'/\'\'}'@'localhost' IDENTIFIED BY '${ESC_PASS}';
CREATE USER IF NOT EXISTS '${DST_USER//\'/\'\'}'@'127.0.0.1' IDENTIFIED BY '${ESC_PASS}';
ALTER USER '${DST_USER//\'/\'\'}'@'localhost' IDENTIFIED BY '${ESC_PASS}';
ALTER USER '${DST_USER//\'/\'\'}'@'127.0.0.1' IDENTIFIED BY '${ESC_PASS}';
GRANT ALL PRIVILEGES ON \`${DST_NAME//\`/\`\`}\`.* TO '${DST_USER//\'/\'\'}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DST_NAME//\`/\`\`}\`.* TO '${DST_USER//\'/\'\'}'@'127.0.0.1';
FLUSH PRIVILEGES;
EOSQL
    fi
fi

# ----------------------------------------------------------------------------
# 7. Ejecutar migración de datos y verificación
# ----------------------------------------------------------------------------
if ! ui_yesno "Todo está listo para migrar los datos:\n\n  Origen:  ${SRC_LABEL}\n  Destino: ${DST_LABEL}\n\nLa configuración actual (config.php) NO se modificará hasta que todos los datos se copien y verifiquen correctamente.\n\n¿Iniciar migración de datos?"; then
    echo "Migración cancelada por el usuario."
    exit 0
fi

echo ""
echo "=================================================================="
echo "  Ejecutando migración de datos (${SRC_LABEL} -> ${DST_LABEL})"
echo "=================================================================="

php "${DATA_MIGRATOR}" \
    --src-driver="${SRC_DRIVER}" \
    --src-path="${SRC_CFG[path]}" \
    --src-host="${SRC_CFG[host]}" \
    --src-port="${SRC_CFG[port]}" \
    --src-name="${SRC_CFG[name]}" \
    --src-user="${SRC_CFG[user]}" \
    --src-pass="${SRC_CFG[pass]}" \
    --dst-driver="${DST_DRIVER}" \
    --dst-path="${DST_PATH}" \
    --dst-host="${DST_HOST}" \
    --dst-port="${DST_PORT}" \
    --dst-name="${DST_NAME}" \
    --dst-user="${DST_USER}" \
    --dst-pass="${DST_PASS}"

MIGRATE_RC=$?
if [[ ${MIGRATE_RC} -ne 0 ]]; then
    ui_msg "ERROR: La migración de datos falló (código ${MIGRATE_RC}).\n\nTu config.php original NO fue modificado y tu base de datos de origen sigue intacta."
    exit ${MIGRATE_RC}
fi

# ----------------------------------------------------------------------------
# 8. Cambio atómico de config.php + permisos + recarga de PHP-FPM
# ----------------------------------------------------------------------------
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
BACKUP_CONFIG="${CONFIG_FILE}.bak.${TIMESTAMP}"
cp -a "${CONFIG_FILE}" "${BACKUP_CONFIG}"

ORIG_UID=$(stat -c '%u' "${CONFIG_FILE}")
ORIG_GID=$(stat -c '%g' "${CONFIG_FILE}")
ORIG_MODE=$(stat -c '%a' "${CONFIG_FILE}")

php -r '
    $cfgFile = $argv[1];
    $driver  = $argv[2];
    $path    = $argv[3];
    $host    = $argv[4];
    $port    = $argv[5];
    $name    = $argv[6];
    $user    = $argv[7];
    $pass    = $argv[8];

    require_once $cfgFile;
    $domain    = defined("KUTSOCIAL_DOMAIN") ? (string)KUTSOCIAL_DOMAIN : "";
    $verifySsl = defined("KUTSOCIAL_VERIFY_SSL") ? (bool)KUTSOCIAL_VERIFY_SSL : true;

    $exp = fn(string $s): string => var_export($s, true);

    $out = "<?php\n"
         . "// ============================================================================\n"
         . "// KutSocial · Archivo de Configuración de la Instancia\n"
         . "// Actualizado automáticamente por migrate-db.sh\n"
         . "// ============================================================================\n\n"
         . "define(\x27KUTSOCIAL_INSTALLED\x27, true);\n"
         . "require_once __DIR__ . \x27/version.php\x27;\n"
         . "define(\x27KUTSOCIAL_DOMAIN\x27, " . $exp($domain) . ");\n"
         . "define(\x27KUTSOCIAL_DB_DRIVER\x27, " . $exp($driver) . ");\n"
         . "define(\x27KUTSOCIAL_DB_PATH\x27, " . $exp($path) . ");\n"
         . "define(\x27KUTSOCIAL_DB_HOST\x27, " . $exp($host) . ");\n"
         . "define(\x27KUTSOCIAL_DB_PORT\x27, " . $exp($port) . ");\n"
         . "define(\x27KUTSOCIAL_DB_NAME\x27, " . $exp($name) . ");\n"
         . "define(\x27KUTSOCIAL_DB_USER\x27, " . $exp($user) . ");\n"
         . "define(\x27KUTSOCIAL_DB_PASS\x27, " . $exp($pass) . ");\n"
         . "define(\x27KUTSOCIAL_VERIFY_SSL\x27, " . ($verifySsl ? "true" : "false") . ");\n\n"
         . "// Autocargar clases del núcleo\n"
         . "spl_autoload_register(function (\$class) {\n"
         . "    \$prefix = \x27KutSocial\\\\\x27;\n"
         . "    \$base_dir = __DIR__ . \x27/src/\x27;\n"
         . "    \$len = strlen(\$prefix);\n"
         . "    if (strncmp(\$prefix, \$class, \$len) !== 0) {\n"
         . "        return;\n"
         . "    }\n"
         . "    \$relative_class = substr(\$class, \$len);\n"
         . "    \$file = \$base_dir . str_replace(\x27\\\\\x27, \x27/\x27, \$relative_class) . \x27.php\x27;\n"
         . "    if (file_exists(\$file)) {\n"
         . "        require_once \$file;\n"
         . "    }\n"
         . "});\n";

    $tmpFile = $cfgFile . ".tmp." . getmypid();
    if (file_put_contents($tmpFile, $out) === false) {
        fwrite(STDERR, "No se pudo escribir archivo temporal de configuración.\n");
        exit(1);
    }
    rename($tmpFile, $cfgFile);
' "${CONFIG_FILE}" "${DST_DRIVER}" "${DST_PATH}" "${DST_HOST}" "${DST_PORT}" "${DST_NAME}" "${DST_USER}" "${DST_PASS}"

chown "${ORIG_UID}:${ORIG_GID}" "${CONFIG_FILE}"
chmod "${ORIG_MODE}" "${CONFIG_FILE}"

if [[ "${DST_DRIVER}" == "sqlite" ]] && [[ -f "${DST_PATH}" ]]; then
    chown "${ORIG_UID}:${ORIG_GID}" "$(dirname "${DST_PATH}")" "${DST_PATH}"
    chmod 640 "${DST_PATH}"
fi

# Recargar php-fpm si está corriendo
if command -v systemctl >/dev/null 2>&1; then
    while IFS= read -r svc; do
        [[ -n "$svc" ]] && systemctl reload-or-restart "$svc" 2>/dev/null || true
    done < <(systemctl list-units --type=service --state=running --no-legend 'php*fpm*' 2>/dev/null | awk '{print $1}')
fi

# ----------------------------------------------------------------------------
# 9. Resumen final (en pantalla y stdout para copiar/pegar)
# ----------------------------------------------------------------------------
if [[ "${DST_DRIVER}" == "sqlite" ]]; then
    SUMMARY_TEXT="¡Migración completada con éxito!\n\n  Origen:  ${SRC_LABEL}\n  Destino: ${DST_LABEL}\n  Archivo: ${DST_PATH}\n\nRespaldo de config.php:\n  ${BACKUP_CONFIG}"
else
    SUMMARY_TEXT="¡Migración completada con éxito!\n\n  Origen:     ${SRC_LABEL}\n  Destino:    ${DST_LABEL}\n  Host:       ${DST_HOST}\n  Puerto:     ${DST_PORT}\n  Base datos: ${DST_NAME}\n  Usuario:    ${DST_USER}\n  Contraseña: ${DST_PASS}\n\nRespaldo de config.php:\n  ${BACKUP_CONFIG}"
fi

ui_msg "${SUMMARY_TEXT}"

echo ""
echo "=================================================================="
echo "  RESUMEN DE MIGRACIÓN - KUTSOCIAL"
echo "=================================================================="
echo -e "${SUMMARY_TEXT}"
echo "=================================================================="
