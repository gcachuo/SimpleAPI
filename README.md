# SimpleAPI

Framework PHP 8.0 minimalista para construir sitios web y APIs REST reutilizables. Provee un
sistema central (`System`), enrutado web por módulos + temas HTML, enrutado API por
`Controller` + método HTTP, y utilidades listas para usar: JWT, sesiones con permisos por
módulo, cURL, email (PHPMailer), PDF (wkhtmltopdf), WebSockets (Ratchet), webhooks, logs,
i18n, manejo de archivos y validaciones.

> Repo: https://github.com/gcachuo/SimpleAPI.git
> Mantenedor: Memo Cachu — gcachu.o@gmail.com

## Características

- **Runtime único** para web y API en el mismo proyecto.
- **Render web** basado en `DOMDocument`: compone un módulo PHP dentro de un tema HTML.
- **API REST** por convención: `REQUEST_METHOD` + `ENDPOINT` → `Controller::call()`.
- **Sesiones con permisos** por módulo y submódulo (`sessionSet` / `sessionCheck`).
- **JWT** para tokens de sesión y API (`encode_token` / `decode_token`).
- **MySQL** vía PDO (con fallback `mysqli`), prepared statements.
- **Email** con PHPMailer, **PDF** con wkhtmltopdf, **WebSockets** con Ratchet.
- **Bootstrap** (`web/`) con Webpack 5, jQuery 3, Bootstrap 4, FontAwesome 5, DataTables,
  Select2, Toastr, Moment, Numeral — listo para scaffold de proyectos nuevos.
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
git submodule add https://github.com/gcachuo/SimpleAPI.git core
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

El sitio de ejemplo queda en http://localhost.

## Estructura

```
core/
├── System.php              # Clase principal del framework
├── classes/                # Controller, MySQL, CoreException, JsonResponse,
│                           # HTTPStatusCodes, Webhook, WebSocket, TableColumn,
│                           # ColumnTypes, Stopwatch
├── web/                    # Bootstrap web de ejemplo
│   ├── index.php           # Entry point
│   ├── config.json         # Configuración del sitio (módulos, rutas, tema)
│   ├── settings.json       # Configuración de ambiente (apiUrl)
│   ├── manifest.json       # PWA manifest
│   ├── service-worker.js
│   ├── modules/            # Módulo dashboard de ejemplo
│   └── assets/src/         # Assets fuente (Webpack, package.json aquí)
├── files/
│   ├── index.php           # Entry point minimalista
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

## Uso rápido

### Sitio web
```php
<?php
// index.php del proyecto consumidor
include_once __DIR__ . "/core/System.php";

define('SESSIONCHECK', false);
System::init_web(['WEBDIR' => __DIR__]);
```

`config.json` define los módulos y el tema; cada módulo es un PHP que se renderiza dentro
del tema vía `DOMDocument`.

### API REST
```php
<?php
// index.php del proyecto consumidor
include_once __DIR__ . "/core/System.php";
System::init(['DIR' => __DIR__]);

// Las clases Controller\<Nombre> implementan los endpoints.
// El dispatch se hace por REQUEST_METHOD + ENDPOINT.
```

Ejemplo de controlador:
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

- `web/config.json` — plantilla por defecto del sitio (módulos, rutas, tema, error). Cada
  proyecto consumidor la sobreescribe con su propia `config.json`.
- `web/settings.json` — `apiUrl` vacío por defecto; cada proyecto lo sobreescribe.
- `web/manifest.json` — PWA manifest base.

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
