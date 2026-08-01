# SimpleAPI

Framework PHP 8.0 minimalista para construir **sitios web** y **APIs REST** reutilizables.
Provee un sistema central (`System`) con **dos modos de operación**:

- **Modo web** — enrutado por módulos + temas HTML, render con `DOMDocument`
  (`System::init_web()` + `System::formatDocument()`).
- **Modo API** — enrutado REST por `Controller` + método HTTP + `ENDPOINT`
  (`System::init()` + `Controller::call()`).

Más utilidades listas para usar: JWT, sesiones con permisos por módulo, cURL, email
(PHPMailer), PDF (wkhtmltopdf), WebSockets (Ratchet), webhooks, logs, i18n, manejo de
archivos y validaciones.

> Repo: https://github.com/gcachuo/SimpleAPI.git
> Mantenedor: Memo Cachu — gcachu.o@gmail.com

> **Nombre del submódulo:** el directorio puede llamarse `core/`, `Lib/` u otro nombre
> según el consumidor. En este documento se usa `core/` como referencia, pero la ruta
> dentro del proyecto consumidor es la que se haya configurado en `.gitmodules`.

## Características

- **Runtime único** para web y API en el mismo proyecto (mismo `System.php`).
- **Modo web**: `DOMDocument` compone un módulo PHP dentro de un tema HTML.
- **Modo API**: REST por convención — `REQUEST_METHOD` + `ENDPOINT` → `Controller::call()`.
- **Sesiones con permisos** por módulo y submódulo (`sessionSet` / `sessionCheck`) — solo
  modo web.
- **JWT** para tokens de sesión (web) y API (`encode_token` / `decode_token`).
- **MySQL** vía PDO (con fallback `mysqli`), prepared statements.
- **Email** con PHPMailer, **PDF** con wkhtmltopdf, **WebSockets** con Ratchet.
- **Bootstrap web** (`web/`) con Webpack 5, jQuery 3, Bootstrap 4, FontAwesome 5,
  DataTables, Select2, Toastr, Moment, Numeral — listo para scaffold de proyectos web.
- **Tests** con PHPUnit.

## Requisitos

- PHP 8.0
- Composer
- (Opcional pero recomendado) Docker Desktop
- Node.js >= 14 + Yarn (para assets del bootstrap)
- `wkhtmltopdf` si se usa `System::generatePDF`

## Instalación

### Como submódulo en un proyecto existente
```bash
# El nombre del submódulo (core, Lib, ...) es elección del consumidor:
git submodule add https://github.com/gcachuo/SimpleAPI.git core   # o Lib
git submodule update --init --recursive
git config --global submodule.recurse true
```

### Como proyecto standalone
```bash
git clone https://github.com/gcachuo/SimpleAPI.git
cd SimpleAPI
composer install
docker compose up -d --build
```

El sitio web de ejemplo (bootstrap `web/`) queda en http://localhost.

## Estructura

### Del framework (este repo)
```
core/                       # o Lib/, según el consumidor
├── System.php              # Clase principal del framework
├── classes/                # Controller, MySQL, CoreException, JsonResponse,
│                           # HTTPStatusCodes, Webhook, WebSocket, TableColumn,
│                           # ColumnTypes, Stopwatch
├── web/                    # Bootstrap web de ejemplo (modo web)
│   ├── index.php           # Entry point web: System::init_web()
│   ├── config.json         # Configuración del sitio (módulos, rutas, tema)
│   ├── settings.json       # Configuración de ambiente (apiUrl)
│   ├── manifest.json       # PWA manifest
│   ├── service-worker.js
│   ├── modules/            # Módulo dashboard de ejemplo
│   └── assets/src/         # Assets fuente (Webpack, package.json aquí)
├── files/
│   ├── index.php           # Entry point API minimalista: System::init()
│   └── Tests/Config/       # PHPUnit
├── scripts/
│   ├── init.php            # CLI: System::startup()
│   ├── socket.php          # Servidor WebSocket
│   ├── web.sh              # Scaffolding de un proyecto web nuevo
│   └── wkhtmltopdf.sh      # Instala binario wkhtmltopdf
├── composer.json
├── docker-compose.yml      # db (mysql:8.0.21) + web (php:8.0-apache)
└── Dockerfile              # php:8.0-apache + mysqli/pdo + mod_rewrite + xdebug
```

### Estructura típica de un consumidor WEB
```
mi-sitio/
├── core/                   # este submódulo
├── modules/                # módulos PHP del sitio (dashboard.php, quienes-somos.php, ...)
├── themes/
│   └── mi-tema/            # tema HTML (plantilla index.html, error.html)
├── assets/
│   ├── src/                # JS/TS/SCSS fuente (Webpack)
│   └── dist/               # salida del build (no editar)
├── config.json             # módulos, rutas, contacto, redes
├── settings.json           # apiUrl de producción
├── settings.dev.json       # apiUrl de desarrollo
└── index.php               # System::init_web(['WEBDIR' => __DIR__])
```

### Estructura típica de un consumidor API
```
mi-api/
├── Lib/                    # este submódulo (mismo repo, distinto nombre)
├── Controller/             # clases que extienden Controller (REST por método HTTP)
│   ├── Users.php
│   ├── Dashboard.php
│   └── ...
├── Model/                  # modelos que usan MySQL + TableColumn + ColumnTypes
│   ├── Users.php
│   └── ...
├── Config/                 # configuración por proyecto (database, email)
│   └── mi-proyecto.json
├── Logs/                   # salida de System::log / log_error
├── composer.json           # (vacío en proyectos reales; las deps viven en Lib/)
├── docker-compose.yml
├── Dockerfile
└── index.php               # System::init(['DIR' => __DIR__])
```

## Uso rápido

### Modo web
```php
<?php
// mi-sitio/index.php
include_once __DIR__ . "/core/System.php";

define('SESSIONCHECK', false);
System::init_web(['WEBDIR' => __DIR__]);
```

`config.json` define los módulos y el tema; cada módulo (`modules/*.php`) es un PHP que se
renderiza dentro del tema HTML vía `DOMDocument`. El framework compone el documento final y
lo imprime con `System::print_page()`.

### Modo API
```php
<?php
// mi-api/index.php
define('VERSION', '1.0.1');
include_once __DIR__ . "/Lib/System.php";

$system = new System();
$system->init(['DIR' => __DIR__]);
```

`System::init()` define `REQUEST_METHOD` y `ENDPOINT` (de `$_GET['endpoint']` o la ruta),
instancia el `Controller\<Nombre>` correspondiente y despacha la acción mapeada al método
HTTP. La respuesta se envía con `JsonResponse::sendResponse()`.

Ejemplo de controlador (`Controller/Users.php`):
```php
namespace Controller;

class Users extends \Controller
{
    public function __construct()
    {
        parent::__construct([
            'GET'    => ['list' => 'list', 'get' => 'get'],
            'POST'   => ['create' => 'create'],
            'DELETE' => ['delete' => 'delete'],
        ]);
    }

    public function list() { /* ... */ }
    public function get($id) { /* ... */ }
    public function create($data) { /* ... */ }
    public function delete($id) { /* ... */ }
}
```

Llamada: `GET /index.php?endpoint=users/list` → `Controller\Users::list()`.

Ejemplo de modelo (`Model/Users.php`) usando `MySQL` + `TableColumn`:
```php
namespace Model;

class Users
{
    public function __construct()
    {
        $mysql = new MySQL();
        $mysql->create_table('users', [
            new TableColumn('id', ColumnTypes::BIGINT, 20, true, null, true, true),
            new TableColumn('email', ColumnTypes::VARCHAR, 100, true),
        ]);
    }

    public function selectUser(string $email): array
    {
        $sql = 'SELECT * FROM users WHERE email=:email;';
        return (new MySQL())->prepare2($sql, [':email' => $email])->fetch() ?: [];
    }
}
```

### Configuración por proyecto (modo API)
El API carga `Config/<proyecto>.json` (donde `<proyecto>` viene del `config.json` del
framework) con la conexión a BD y email:
```json
{
  "project": {"name": "default", "code": "mi-proyecto"},
  "database": {"host": "db", "username": "root", "passwd": "secret", "dbname": "myapp"},
  "email": {"name": "", "username": "", "password": "", "host": "smtp.gmail.com", "port": "465"}
}
```

### Utilidades comunes de `System`
```php
System::curl(['url' => 'https://...'], 'config');   // HTTP saliente
System::encode_token(['user_id' => 1]);              // JWT
System::decode_token($jwt);                          // valida + decodifica
System::sendEmail(['to@example.com'], '<p>...</p>', ['subject' => 'Hola']);
System::generatePDF(['<h1>Hola</h1>'], '/tmp/out.pdf');
System::log('mensaje');
System::check_value_empty($input, ['email', 'pass'], 'Missing data', 400);
System::redirect('dashboard');
```

## Comandos

### Docker
```bash
docker compose up -d --build
docker compose logs -f web
docker compose restart web
docker exec -it core-web-1 bash
docker compose down
```

### Composer
```bash
composer install
composer dump-autoload
```

### Tests
```bash
./vendor/bin/phpunit -c files/Tests/Config/phpunit.xml
```

### Assets del bootstrap (desde `web/assets/src/`)
```bash
yarn install
yarn webpack:watch          # desarrollo con watch
yarn webpack:build:dev      # build de desarrollo
yarn webpack:build:prod     # build de producción
yarn clean
yarn cypress:open:dev       # E2E
```

> `package.json` está en `web/assets/src/`, **no** en la raíz.

### Scaffolding de un proyecto nuevo basado en SimpleAPI
```bash
bash scripts/web.sh         # copia web/ a ../.. y crea la estructura
php scripts/init.php        # inicializa System (CLI)
php scripts/socket.php      # arranca el servidor WebSocket
```

## Configuración

### Modo web
- `web/config.json` — plantilla por defecto del sitio (módulos, rutas, tema, error). Cada
  proyecto consumidor la sobreescribe con su propia `config.json`.
- `web/settings.json` — `apiUrl` vacío por defecto; cada proyecto lo sobreescribe.
- `web/manifest.json` — PWA manifest base.

### Modo API
- `Config/<proyecto>.json` — por proyecto: `database` (host, user, pass, dbname) y `email`
  (SMTP). El `code` del proyecto debe coincidir con el del `config.json` del framework.
- `Logs/` — directorio de salida para `System::log()` y `System::log_error()`.

## Dependencias principales

| Paquete | Uso |
|---|---|
| `firebase/php-jwt` v5.0.0 | Tokens JWT |
| `phpmailer/phpmailer` ^6.1 | Envío de email |
| `mikehaertl/phpwkhtmltopdf` ^2.4 | Generación de PDF |
| `neitanod/forceutf8` ^2.0 | Normalización UTF-8 |
| `cboden/ratchet` ^0.4.3 | Servidor WebSocket |
| `textalk/websocket` ^1.4 | Cliente WebSocket |
| `camcima/php-mysql-diff` ^1.2 | Diff de esquemas MySQL |
| `phpunit/phpunit` ^8 (dev) | Tests |

## Licencia

Ver archivo `LICENSE` si existe. Proyecto mantenido por Memo Cachu.
