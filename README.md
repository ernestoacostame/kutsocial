<img width="2249" height="1371" alt="kutsocial_front" src="https://github.com/user-attachments/assets/d3d1f66f-6d69-4711-affe-e6d3a05ea5db" />
<img width="2249" height="1371" alt="kutsocial_admin" src="https://github.com/user-attachments/assets/28a32e98-a165-4326-a87b-31a515c95355" />


# 🚀 KutSocial

<p align="center">
  <strong>🇬🇧 <a href="#english">English</a></strong> · <strong>🇪🇸 <a href="#español">Español</a></strong>
</p>

<p align="center">
  <em>A lightweight, self-hosted, federated microblogging platform and web client for the Fediverse with native ActivityPub and Mastodon API compatibility.</em>
</p>

---

<a name="english"></a>
## 🇬🇧 English

**KutSocial** is a lightweight, self-hosted social networking and microblogging server and web client designed for the Fediverse. Built with simplicity, speed, and privacy in mind, it allows anyone to run their own federated social instance without needing heavyweight databases or complex container orchestrations.

It is fully compatible with the **ActivityPub protocol** and the **Mastodon client API**, enabling seamless bidirectional interaction (following, mentions, boosts, favorites, and threads) with users on Mastodon, Pleroma, Misskey, Pixelfed, Firefish, and any other Fediverse platform.

### ✨ Key Features

* **Native ActivityPub Federation**: Standards-compliant implementation supporting WebFinger, HTTP Signatures, Shared Inboxes, Outboxes, Follows, Boosts, Likes, and Replies.
* **Mastodon API Compatibility**: Works out of the box with popular third-party Mastodon clients on desktop and mobile (Ivory, Mona, Tusky, Fedilab, IceCubes, etc.).
* **Zero-Configuration SQLite Engine**: Uses SQLite for storage, completely eliminating the need to set up or maintain MySQL, MariaDB, or PostgreSQL services.
* **Modern SPA Web Client**: Ultra-fast, responsive Single Page Application (SPA) frontend built with semantic HTML5, modern CSS, and Vanilla JavaScript with no npm build steps.
* **Centralized Admin Dashboard**: Manage users, domain federation blocks, ActivityPub relays, security settings, automatic storage cleanups, and system updates from `/admin/dashboard`.
* **Advanced Account Security**: Native Two-Factor Authentication (2FA / TOTP), secure password hashing (Argon2id/Bcrypt), and JWT authentication tokens.
* **Asynchronous Queue Worker**: Built-in background delivery engine (`cron.php`) that delivers and ingests federated posts efficiently without blocking the web UI.
* **Web Push Notifications**: Real-time browser push notifications powered by the Web Push / VAPID standard.
* **Bilingual UI**: Instant language toggle between English and Spanish in both the public app and the administration panel.

### 🛠️ System Requirements

* **PHP**: Version `8.3.0` or higher.
* **Required PHP Extensions**:
  * `pdo` & `pdo_sqlite` (SQLite database engine)
  * `openssl` (required for ActivityPub HTTP signatures and key generation)
  * `curl` (for federated server-to-server communication)
  * `zip` (for one-click system updates)
  * `mbstring` (multibyte text processing)
* **Web Server**: Nginx (Recommended) or Apache.
* **Write Permissions**: The web server process user (e.g. `www-data` or `nginx`) needs read and write access to the application directory to generate the SQLite database and store uploaded media.

### 📦 Installation Guide

KutSocial includes a unified installer that can be executed via a graphical web wizard or through the command line (CLI).

#### Step 1: Clone the Repository
Clone the repository into your web server's document root:
```bash
git clone https://github.com/ernestoacostame/kutsocial.git /var/www/html/kutsocial
cd /var/www/html/kutsocial
```

#### Step 2: Configure File Permissions
Set ownership and permissions so the web server can create the `data/` folder and `config.php`:
```bash
sudo chown -R www-data:www-data /var/www/html/kutsocial
sudo chmod -R 755 /var/www/html/kutsocial
```

#### Step 3: Run the Installer

##### Option A: Web-Based Wizard
1. Open your browser and navigate to: `https://your-domain.com/install.php`
2. Fill in the required fields:
   * **Admin Username**: The local username for the node owner.
   * **Email**: Administrator email address for notifications and recovery.
   * **Password**: A strong administrator password.
   * **Domain**: Your public domain name (e.g. `social.yourdomain.com`).
3. Click **Install KutSocial**.

##### Option B: Command-Line (CLI)
Execute the installation script with the required flags:
```bash
php install.php --username=admin --email=admin@yourdomain.com --password=YourSecurePassword123 --domain=social.yourdomain.com
```

> [!IMPORTANT]
> Once setup finishes, the installer automatically generates `config.php` and the secure `data/` directory housing the `kutsocial.db` database and media storage.

### ⚙️ Web Server Configuration (Nginx)

Ready-to-use configuration templates are provided inside the `nginx/` directory:

1. **`nginx_local.conf`**: For local server environments behind PHP-FPM, including caching optimizations for media uploads and static assets.
2. **`nginx_proxy.conf`**: For public reverse proxies with SSL/TLS termination, passing traffic securely to your instance.

Restart Nginx after activating the site:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### ⏰ Background Worker Setup (Cron)

To deliver federated activities smoothly without delaying user requests, set up a cron job to run the worker every minute:

1. Open crontab:
   ```bash
   crontab -e
   ```
2. Add the following line:
   ```bash
   * * * * * php /var/www/html/kutsocial/cron.php >/dev/null 2>&1
   ```

### 📂 Directory Structure

* `assets/`: Public site assets (favicon, logos, default avatars, CSS/JS).
* `data/`: *(Generated on install)* Protected directory holding the SQLite database (`kutsocial.db`), remote image cache (`cache/`), and local uploads (`uploads/`).
* `nginx/`: Server configuration templates.
* `src/`:
  * `Controllers/`: Handlers for Mastodon API, ActivityPub federation, and the admin panel.
  * `views/`: SPA frontend templates and dashboard views.
  * `Database.php`: SQLite connection manager and schema migrations.
  * `Router.php`: Lightweight REST router.
  * `Queue.php`: Task queue for asynchronous federation.
* `index.php`: Front controller and main application router.
* `install.php`: Web and CLI setup script.
* `cron.php`: Entrypoint for queue worker processing.
* `release.sh`: Release packaging script.

---

<a name="español"></a>
## 🇪🇸 Español

**KutSocial** es un servidor y cliente web de microblogging ligero y federado para el Fediverso. Está diseñado con la simplicidad, la velocidad y la privacidad en mente, permitiendo que cualquier persona tenga su propia instancia federada de forma rápida sin depender de bases de datos pesadas ni de configuraciones complejas.

Es compatible con la **API de Mastodon** y el protocolo **ActivityPub**, lo que permite interactuar, seguir y ser seguido por usuarios en plataformas como Mastodon, Pleroma, Pixelfed, Firefish y cualquier otro nodo del Fediverso.

### ✨ Características Principales

* **Federación Nativa:** Implementación completa del protocolo ActivityPub (Webfinger, HTTP Signatures, Inbox, Outbox, Seguidores, Impulsos/Boosts y Respuestas).
* **API Mastodon Compatible:** Funciona de forma excelente con clientes de terceros compatibles con la API de Mastodon (Ivory, Mona, Tusky, Fedilab, IceCubes, etc.).
* **Base de Datos Ligera en SQLite:** Utiliza SQLite como motor de base de datos, eliminando la necesidad de instalar o mantener servicios pesados como MySQL o PostgreSQL.
* **Interfaz Web SPA:** Cliente web de página única (Single Page Application) moderno, responsivo y ultra rápido construido con HTML5, CSS moderno y Vanilla JavaScript sin compilación previa.
* **Panel de Administración Centralizado:** Gestión de usuarios, moderación de dominios, relays de ActivityPub, ajustes de seguridad, limpieza automática de disco y control de actualizaciones desde `/admin/dashboard`.
* **Seguridad Avanzada:** Soporte nativo para autenticación en dos factores (2FA / TOTP), contraseñas robustas (Argon2id/Bcrypt) y tokens JWT.
* **Procesador de Cola Eficiente:** Worker integrado (`cron.php`) para la entrega y recepción de publicaciones en segundo plano sin ralentizar la interfaz web.
* **Notificaciones Push:** Soporte para notificaciones web en tiempo real en navegadores compatibles (Web Push / VAPID).
* **Selector Bilingüe:** Interfaz disponible en español e inglés tanto en la aplicación pública como en el panel de administración.

### 🛠️ Requisitos del Sistema

* **PHP:** Versión `8.3.0` o superior.
* **Extensiones de PHP requeridas:**
  * `pdo` & `pdo_sqlite` (almacenamiento en SQLite)
  * `openssl` (fundamental para la firma de peticiones ActivityPub y cifrado de claves)
  * `curl` (para interactuar con otras instancias)
  * `zip` (para actualizaciones automáticas del sistema)
  * `mbstring` (procesamiento de caracteres multibyte)
* **Servidor Web:** Nginx (Recomendado) o Apache.
* **Permisos:** El usuario de ejecución del servidor web (ej. `www-data`) debe tener permisos de lectura y escritura en el directorio raíz para generar la base de datos local y guardar los archivos subidos.

### 📦 Instrucciones de Instalación

KutSocial incluye un instalador unificado que puede ejecutarse de manera gráfica (Web) o a través de la consola (CLI).

#### Paso 1: Clonar el Repositorio
Clona el código en el directorio raíz de tu servidor web:
```bash
git clone https://github.com/ernestoacostame/kutsocial.git /var/www/html/kutsocial
cd /var/www/html/kutsocial
```

#### Paso 2: Configurar Permisos de Escritura
Asigna los permisos necesarios al servidor web sobre el directorio para permitir que cree la carpeta `data/` y el archivo `config.php`:
```bash
sudo chown -R www-data:www-data /var/www/html/kutsocial
sudo chmod -R 755 /var/www/html/kutsocial
```

#### Paso 3: Ejecutar el Instalador

##### Opción A: Instalación Gráfica (Web)
1. Navega en tu navegador a: `https://tu-dominio.com/install.php`
2. Rellena los datos requeridos:
   * **Usuario Administrador:** Nombre de usuario local (el propietario del nodo).
   * **Correo Electrónico:** Correo del administrador para notificaciones y recuperación.
   * **Contraseña:** Clave de acceso segura.
   * **Dominio:** Tu dominio público (ej. `social.tudominio.com`).
3. Haz clic en **Instalar KutSocial**.

##### Opción B: Instalación por Consola (CLI)
Ejecuta el script de instalación CLI pasando los parámetros requeridos:
```bash
php install.php --username=admin --email=admin@tudominio.com --password=MiClaveSegura123 --domain=social.tudominio.com
```

> [!IMPORTANT]
> Una vez finalizada la instalación, el instalador creará el archivo `config.php` y creará la carpeta segura `data/` con la base de datos `kutsocial.db` y subdirectorios de almacenamiento.

### ⚙️ Configuración del Servidor Web (Nginx)

Se incluyen plantillas de configuración listas para usar dentro del directorio `nginx/`:

1. **`nginx_local.conf`**: Para el servidor local detrás de tu entorno o proxy interno de PHP-FPM, incluyendo optimizaciones de caché para multimedia y assets estáticos.
2. **`nginx_proxy.conf`**: Para configurar un proxy inverso público con HTTPS/SSL en un VPS o servidor exterior.

Reinicia Nginx tras aplicar la configuración:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

### ⏰ Configuración del Procesador de Cola (Worker)

Para que las publicaciones federadas se envíen y reciban correctamente sin demoras, configura una tarea programada (Cron) en tu sistema:

1. Abre el editor de cron del sistema:
   ```bash
   crontab -e
   ```
2. Añade la siguiente línea para ejecutar el worker cada minuto:
   ```bash
   * * * * * php /var/www/html/kutsocial/cron.php >/dev/null 2>&1
   ```

### 📂 Estructura del Proyecto

* `assets/`: Recursos visuales públicos del sitio (favicon, logos, avatares por defecto).
* `data/`: *(Generado tras la instalación)* Carpeta protegida donde se ubica la base de datos SQLite (`kutsocial.db`), caché de imágenes externas (`cache/`) y archivos multimedia locales (`uploads/`).
* `nginx/`: Plantillas y archivos de configuración para el servidor Nginx.
* `src/`:
  * `Controllers/`: Lógica para procesar la API Mastodon, flujos de ActivityPub e interfaz de administración.
  * `views/`: Plantilla HTML central y sus respectivas sub-vistas PHP.
  * `Database.php`: Conexión de SQLite y motor de migraciones.
  * `Router.php`: Enrutador REST ligero.
  * `Queue.php`: Sistema de cola para federación asíncrona.
* `index.php`: Enrutador frontal y punto de entrada general de la aplicación.
* `install.php`: Script de instalación web y por CLI.
* `cron.php`: Punto de entrada para el procesamiento del worker de cola de tareas.
* `release.sh`: Script bash automatizado para crear nuevos lanzamientos o empaquetar versiones.

---

## 💖 Support & Donations / Soporte y Donaciones

If you find this project useful and want to support its ongoing development, consider making a donation. Every contribution helps keep the project active, independent, and constantly improving!

Si este proyecto te resulta útil y deseas apoyar su desarrollo continuo, considera hacer una donación. ¡Cada aporte ayuda a mantener el proyecto activo, independiente y en constante evolución!

* ☕ **Buy Me a Coffee**: [buymeacoffee.com/ernestoacostame](https://www.buymeacoffee.com/ernestoacostame)
* 💖 **Ko-fi**: [ko-fi.com/ernestoacostame](https://ko-fi.com/ernestoacostame)
* 🅿️ **PayPal**: [paypal.com/paypalme/elav](https://www.paypal.com/paypalme/elav)

<p align="center">
  <a href="https://www.buymeacoffee.com/ernestoacostame" target="_blank">
    <img src="https://img.shields.io/badge/Buy%20Me%20a%20Coffee-Invítame%20un%20café-FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black" alt="Buy Me a Coffee">
  </a>
  &nbsp;&nbsp;
  <a href="https://ko-fi.com/ernestoacostame" target="_blank">
    <img src="https://img.shields.io/badge/Ko--fi-Apóyame-FF5E5B?style=for-the-badge&logo=ko-fi&logoColor=white" alt="Ko-fi">
  </a>
  &nbsp;&nbsp;
  <a href="https://www.paypal.com/paypalme/elav" target="_blank">
    <img src="https://img.shields.io/badge/PayPal-Donar%20%2F%20Donate-00457C?style=for-the-badge&logo=paypal&logoColor=white" alt="PayPal">
  </a>
</p>
