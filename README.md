# 🚀 KutSocial

KutSocial es un servidor y cliente web de microblogging ligero y federado para el Fediverso. Está diseñado con la simplicidad en mente, permitiendo que cualquier persona tenga su propia instancia federada de forma rápida sin depender de bases de datos pesadas ni de configuraciones complejas.

Es compatible con la **API de Mastodon** y el protocolo **ActivityPub**, lo que permite interactuar, seguir y ser seguido por usuarios en plataformas como Mastodon, Pleroma, Pixelfed, Firefish y más.

---

## ✨ Características Principales

*   **Federación Nativa:** Implementación parcial/completa de la especificación ActivityPub (Webfinger, Inbox, Outbox, Seguidores).
*   **API Mastodon Compatible:** Funciona de forma excelente con clientes de terceros compatibles con la API de Mastodon.
*   **Base de Datos Ligera:** Utiliza SQLite como motor de base de datos, lo que elimina la necesidad de instalar o configurar MySQL/PostgreSQL.
*   **Interfaz Web SPA:** Cliente web de página única (Single Page Application) moderno, responsivo y ultra rápido construido con HTML, Vanilla CSS y Javascript.
*   **Panel de Administración Centralizado:** Gestión de usuarios, moderación de dominios, ajustes de seguridad, limpieza automática de disco y control de actualizaciones desde `/admin/dashboard`.
*   **Seguridad Avanzada:** Soporte para autenticación en dos factores (2FA / TOTP) y tokens JWT.
*   **Procesador de Cola Eficiente:** Worker integrado (`cron.php`) para la entrega y recepción de publicaciones en segundo plano sin ralentizar la interfaz web.
*   **Notificaciones Push:** Soporte para notificaciones web en tiempo real (Web Push / VAPID).

---

## 🛠️ Requisitos del Sistema

Antes de iniciar la instalación, asegúrate de que tu servidor cumpla con lo siguiente:

*   **PHP:** Versión `8.3.0` o superior.
*   **Extensiones de PHP requeridas:**
    *   `pdo`
    *   `pdo_sqlite` (para el almacenamiento en SQLite)
    *   `openssl` (fundamental para la firma de peticiones ActivityPub)
    *   `curl` (para interactuar con otras instancias)
    *   `zip` (para actualizaciones del sistema)
*   **Servidor Web:** Nginx (Recomendado) o Apache.
*   **Permisos:** El usuario de ejecución del servidor web (ej. `www-data`) debe tener permisos de lectura y escritura en el directorio raíz de la aplicación para poder generar la base de datos local y guardar los archivos subidos.

---

## 📦 Instrucciones de Instalación

KutSocial incluye un instalador unificado que puede ejecutarse de manera gráfica (Web) o a través de la consola (CLI).

### Paso 1: Clonar el Repositorio
Clona el código en el directorio raíz de tu servidor web:
```bash
git clone https://github.com/ernestoacostame/kutsocial.git /var/www/html/kutsocial
cd /var/www/html/kutsocial
```

### Paso 2: Configurar Permisos de Escritura
Asigna los permisos necesarios al servidor web sobre el directorio raíz para permitir que cree la carpeta `data/` y el archivo `config.php`:
```bash
sudo chown -R www-data:www-data /var/www/html/kutsocial
sudo chmod -R 755 /var/www/html/kutsocial
```

### Paso 3: Ejecutar el Instalador
Puedes elegir cualquiera de los dos métodos descritos a continuación:

#### Opción A: Instalación Gráfica (Web)
1. Navega en tu navegador a: `http://tu-dominio.com/install.php`
2. Rellena los datos requeridos:
   * **Usuario Administrador:** Nombre de usuario local (el propietario del nodo).
   * **Correo Electrónico:** Correo del administrador para notificaciones y recuperación.
   * **Contraseña:** Clave de acceso segura.
   * **Dominio:** Tu dominio público (ej. `kutsocial.tudominio.com`).
3. Haz clic en **Instalar KutSocial**.

#### Opción B: Instalación por Consola (CLI)
Ejecuta el script de instalación CLI pasando los parámetros requeridos:
```bash
php install.php --username=admin --email=admin@tudominio.com --password=MiClaveSegura123 --domain=kutsocial.tudominio.com
```

> [!IMPORTANT]
> Una vez finalizada la instalación, el instalador creará el archivo `config.php` y creará la carpeta segura `data/` con la base de datos `kutsocial.db` y subdirectorios de almacenamiento.

---

## ⚙️ Configuración del Servidor Web (Nginx)

Se incluyen plantillas de configuración listas para usar dentro del directorio `nginx/`:

1.  **`nginx_local.conf`**: Para el servidor local detrás de tu entorno de desarrollo o tu proxy interno de PHP-FPM. Incluye optimizaciones de caché para los archivos multimedia subidos y los assets estáticos de la app.
2.  **`nginx_proxy.conf`**: Para configurar un proxy inverso público con HTTPS/SSL en un VPS o servidor exterior que redirija el tráfico seguro hacia tu servidor local mediante túneles (como WireGuard).

> [!TIP]
> Recuerda reiniciar Nginx tras aplicar la configuración:
> ```bash
> sudo nginx -t
> sudo systemctl reload nginx
> ```

---

## ⏰ Configuración del Procesador de Cola (Worker)

Para que las publicaciones federadas se envíen y reciban correctamente sin retrasos, debes configurar una tarea programada (Cron) en tu sistema para ejecutar el worker de KutSocial.

1. Abre el editor de cron del sistema:
   ```bash
   crontab -e
   ```
2. Añade la siguiente línea para ejecutar el worker cada minuto:
   ```bash
   * * * * * php /var/www/html/kutsocial/cron.php >/dev/null 2>&1
   ```

---

## 📂 Estructura del Proyecto

*   `assets/`: Contiene los recursos visuales públicos del sitio (favicon, logos, avatares por defecto).
*   `data/`: *(Generado tras la instalación)* Carpeta protegida donde se ubica la base de datos SQLite (`kutsocial.db`), caché de imágenes externas (`cache/`) y archivos multimedia locales (`uploads/`).
*   `nginx/`: Plantillas y archivos de configuración para el servidor Nginx.
*   `src/`:
    *   `Controllers/`: Lógica para procesar la API Mastodon, flujos de ActivityPub e interfaz de administración.
    *   `views/`: Plantilla HTML central (`frontend.html`) y sus respectivas sub-vistas PHP (`feed.php`, `profile.php`, etc.).
    *   `Database.php`: Conexión de SQLite y motor de migraciones.
    *   `Router.php`: Enrutador REST ligero.
    *   `Queue.php`: Sistema de cola para federación asíncrona.
*   `index.php`: Enrutador frontal y punto de entrada general de la aplicación.
*   `install.php`: Script de instalación web y por CLI.
*   `cron.php`: Punto de entrada para el procesamiento del worker de cola de tareas.
*   `release.sh`: Script bash automatizado para crear nuevos lanzamientos o empaquetar versiones.
