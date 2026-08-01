# AGENTS.md — SimpleAPI (core)

Guía para agentes de IA (Devin, Claude Code, Cursor, etc.) que trabajen dentro de este submódulo.

> **Alto nivel:** Este directorio es un **submódulo git** que apunta a
> https://github.com/gcachuo/SimpleAPI.git. Es un **framework PHP reutilizable** consumido por
> varios proyectos (p. ej. `Pagina/` del Colegio La Salle Peñitas). **No pertenece a un proyecto
> concreto**: los cambios aquí afectan a todos los consumidores. Editar con cuidado y preferir
> hacer los cambios en este repo antes que parchearlos desde los proyectos que lo consumen.

## Descripción del Proyecto

**SimpleAPI** es un framework / runtime PHP 8.0 que provee:

- Un **sistema central** (`System.php`) con utilidades estáticas: sesiones, JWT, cURL, email,
  PDF, logs, i18n, manejo de archivos, tokens, validaciones, etc.
- Un **enrutador web** que renderiza módulos PHP sobre una plantilla HTML (tema) usando
  `DOMDocument` para componer el documento final (`init_web()` + `formatDocument()`).
- Un **enrutador API** basado en `Controller` + `REQUEST_METHOD` + `ENDPOINT`.
- Clases auxiliares: `MySQL` (PDO/mysqli), `JsonResponse`, `CoreException`,
  `HTTPStatusCodes`, `Webhook`, `WebSocket` (Ratchet), `TableColumn`, `ColumnTypes`,
  `Stopwatch`.
- Un **bootstrap** (`web/`) con `index.php`, `config.json`, `settings.json`, `manifest.json`,
  `service-worker.js` y un módulo `dashboard` de ejemplo.
- Herramientas CLI / scripts en `scripts/` (`init.php`, `socket.php`, `web.sh`,
  `wkhtmltopdf.sh`).
- Tests PHPUnit en `files/Tests/`.

- **Lenguaje principal:** PHP 8.0
- **Servidor de referencia:** Apache (`php:8.0-apache`) vía Docker Compose
- **Dependencias PHP:** Composer (`composer.json` en la raíz del submódulo)
- **Dependencias JS:** Yarn (en `web/assets/src/`, NO en la raíz)
- **Stack frontend del bootstrap:** jQuery 3, Bootstrap 4, FontAwesome 5, DataTables, Select2,
  Toastr, Moment, Numeral (Webpack 5 + ts-loader + sass-loader)

## Estructura del Repositorio

```
core/
├── System.php              # Clase principal del framework (~96 KB, ~2.4k líneas)
├── classes/                # Clases del núcleo
│   ├── Controller.php      # Despachador de endpoints (REST por método HTTP)
│   ├── MySQL.php           # Wrapper PDO/mysqli (namespace Model)
│   ├── CoreException.php   # Excepción del framework (log + datos extra)
│   ├── HTTPStatusCodes.php # Constantes de códigos HTTP
│   ├── JsonResponse.php    # Respuesta JSON normalizada a UTF-8
│   ├── Webhook.php         # Dispatcher de webhooks por plataforma
│   ├── WebSocket.php       # Servidor WebSocket abstracto (Ratchet)
│   ├── TableColumn.php     # Definición de columnas de tablas
│   ├── ColumnTypes.php     # Tipos de columna
│   └── Stopwatch.php       # Medición de tiempos
├── web/                    # Bootstrap / plantilla web por defecto
│   ├── index.php           # Entry point de ejemplo (incluye core/System.php)
│   ├── config.json         # Configuración del sitio (módulos, rutas, tema)
│   ├── settings.json       # Configuración de ambiente (apiUrl)
│   ├── manifest.json       # PWA manifest
│   ├── service-worker.js   # SW base
│   ├── modules/            # Módulo dashboard de ejemplo
│   └── assets/src/         # Assets fuente (Webpack, package.json aquí)
├── files/
│   ├── index.php           # Entry point minimalista (define VERSION, init System)
│   ├── composer.json       # Vacío ({}) — placeholder
│   └── Tests/Config/       # PHPUnit (autoload.php, phpunit.xml)
├── scripts/
│   ├── init.php            # CLI: System::startup() (scaffolding inicial)
│   ├── socket.php          # Arranca el servidor WebSocket
│   ├── web.sh              # Scaffolding de un proyecto web nuevo (copia web/)
│   └── wkhtmltopdf.sh      # Instala binario wkhtmltopdf (para generatePDF)
├── composer.json           # Dependencias PHP del framework
├── composer.lock
├── docker-compose.yml      # db (mysql:8.0.21) + web (php:8.0-apache)
├── Dockerfile              # php:8.0-apache + mysqli/pdo + mod_rewrite + xdebug
└── .gitignore              # ignora /vendor/ y /.idea/
```

## Configuración del Entorno

### Requisitos
- Docker Desktop (con WSL2 en Windows)
- PHP 8.0 (solo si se trabaja fuera de Docker)
- Composer
- Node.js >= 14 + Yarn (para assets del bootstrap)
- Git (submódulos habilitados en el repo padre)

### Puesta en marcha (desarrollo aislado del framework)
```bash
docker compose up -d --build
docker exec -it core-web-1 bash
composer install                 # dentro del contenedor, en /var/www/html/
```

> El `docker-compose.yml` de este submódulo expone **80:80** y **3306:3306**. Si se levanta
> junto al proyecto padre (`Pagina/`), habrá conflicto de puertos — usar solo uno a la vez.

### Composer
Las dependencias viven en `composer.json` (raíz del submódulo):
```bash
composer install
composer update <paquete>        # sólo cuando sea necesario
```

Dependencias notables:
- `firebase/php-jwt` v5.0.0 — tokens JWT (`encode_token` / `decode_token`).
- `phpmailer/phpmailer` ^6.1 — envío de email (`System::sendEmail`).
- `mikehaertl/phpwkhtmltopdf` ^2.4 — generación de PDF (`System::generatePDF`); requiere
  binario `wkhtmltopdf` instalado (ver `scripts/wkhtmltopdf.sh`).
- `neitanod/forceutf8` ^2.0 — normalización UTF-8.
- `cboden/ratchet` ^0.4.3 + `textalk/websocket` ^1.4 — WebSockets.
- `camcima/php-mysql-diff` ^1.2 — diff de esquemas MySQL.
- Dev: `phpunit/phpunit` ^8.

## Comandos de Desarrollo

### Docker
```bash
docker compose up -d --build
docker compose logs -f web
docker compose restart web
docker exec -it core-web-1 bash
docker compose down
```

### Tests (PHPUnit)
```bash
# Desde la raíz del submódulo (o dentro del contenedor)
./vendor/bin/phpunit -c files/Tests/Config/phpunit.xml
```
El bootstrap (`files/Tests/Config/autoload.php`) incluye `files/index.php`, que a su vez
inicializa `System`. Los tests actualmente son mínimos — al añadir funcionalidad, añadir
tests bajo `files/Tests/`.

### Assets del bootstrap (Webpack) — ejecutar desde `web/assets/src/`
```bash
yarn install
yarn webpack:watch          # modo desarrollo con watch
yarn webpack:build:dev      # build de desarrollo (restaura settings.dev.json)
yarn webpack:build:prod     # build de producción (limpia settings.dev.json)
yarn clean                  # limpia web/assets/dist/
yarn cypress:open:dev       # E2E con Cypress
yarn cypress:run:dev
```

> `package.json` está en `web/assets/src/`, **NO** en la raíz. Los scripts de Webpack deben
> ejecutarse desde ese directorio.

### Scaffolding de un nuevo proyecto basado en SimpleAPI
```bash
bash scripts/web.sh         # copia web/ a ../.. y crea estructura de proyecto
php scripts/init.php        # inicializa System (CLI)
php scripts/socket.php      # arranca servidor WebSocket
```

## Arquitectura

### Flujo web
```
index.php (del proyecto consumidor)
  └─ define('SESSIONCHECK', ...) etc.
  └─ System::init_web(['WEBDIR' => __DIR__])
        └─ carga config.json → define WEBCONFIG, MODULES, THEME, etc.
        └─ System::formatDocument($file, $module_file)
              └─ DOMDocument: inserta módulo renderizado dentro del tema
              └─ System::load_module($file) → ejecuta el PHP del módulo
  └─ System::print_page()
```

### Flujo API
```
index.php (del proyecto consumidor)
  └─ System::init(['DIR' => __DIR__])
        └─ REQUEST_METHOD + ENDPOINT → Controller::call($action, $args)
              └─ Controller\* (clases del proyecto) implementan los métodos
              └─ JsonResponse::sendResponse(...) al finalizar
              └─ CoreException en caso de error (log + datos)
```

### `System.php` — puntos de entrada clave
- `System::init($config)` — arranque genérico (CLI / API).
- `System::init_web(array $constants)` — arranque para sitios web (define constantes web,
  sesiones, módulos).
- `System::formatDocument($file, $module_file)` — composición del documento HTML final.
- `System::load_module($file)` — carga y renderiza un módulo PHP.
- `System::curl($options, $select)` — llamada HTTP saliente (usada para consumir APIs).
- `System::curlDecodeToken($token)` — valida y decodifica un JWT de sesión.
- `System::encode_token($data, $options)` / `System::decode_token($jwt)` — JWT.
- `System::sendEmail($to, $body, $options)` — envío por PHPMailer.
- `System::generatePDF($pages, $output)` — PDF con wkhtmltopdf.
- `System::log($message)` / `System::log_error($response)` / `System::query_log($sql)`.
- `System::sessionSet` / `System::sessionCheck` — sesiones con permisos por módulo.

## Reglas y Convenciones

### Submódulo
- Este directorio es un **submódulo git**. El repo padre (`Pagina/`) lo referencia; los
  cambios se commitean aquí y luego el padre actualiza la referencia del submódulo.
- **No editar archivos de este submódulo desde el repo padre** sin actualizar la referencia.
  Si un proyecto consumidor necesita un comportamiento distinto, preferir:
  1. Extender / configurar desde el proyecto consumidor (config.json, módulos propios).
  2. Si es un bug del framework: arreglarlo aquí, commitear, push, y luego actualizar la
     referencia en el padre.
- **Nunca** parchear `System.php` o `classes/*` directamente desde el proyecto consumidor.

### Código PHP
- PHP 8.0. Usar `declare(strict_types=1)` donde ya se use (p. ej. `MySQL.php`).
- Las clases del núcleo **no** viven en namespace (excepto `Model\MySQL`), se cargan vía
  Composer autoload (psr-4 + classmap). Al añadir clases, actualizar `composer.json` si
  hace falta y regenerar autoload.
- Manejo de errores: lanzar `CoreException` con `($message, $code, $data)`. `CoreException`
  ya loguea automáticamente — no duplicar logs.
- Respuestas API: usar `JsonResponse::sendResponse($response, $code, $data)`.
- Validaciones: `System::check_value_empty($array, $required, $message, $code)`.
- Sesiones: `System::sessionSet` / `System::sessionCheck` — `sessionCheck` sólo funciona en
  contexto web (`ENVIRONMENT === 'www'`).

### Configuración
- `web/config.json` es la **plantilla por defecto** que `scripts/web.sh` copia a los
  proyectos. Mantenerlo genérico; la configuración específica de cada proyecto vive en el
  proyecto consumidor, no aquí.
- `web/settings.json` tiene `apiUrl` vacío por defecto — cada proyecto lo sobreescribe.
- No commitear `settings.dev.json` ni secretos reales en este submódulo.

### Assets
- Editar solo archivos en `web/assets/src/`. **Nunca** editar `web/assets/dist/` (salida
  del build).
- Stack: jQuery 3, Bootstrap 4, FontAwesome 5, DataTables, Select2, Toastr, Moment,
  Numeral. TypeScript y SCSS soportados.

### Estilo de commits
- Mensajes concisos en inglés, enfocados en el "por qué".
- Prefijo convencional: `fix:`, `feat:`, `refactor:`, `docs:`, `chore:`.
- Husky está configurado en `web/assets/src/.husky` (git hooks).

## Verificación

Antes de considerar completa una tarea en este submódulo:

- [ ] Si se tocó PHP: `docker compose restart web` y verificar que el bootstrap
      (`web/index.php`) carga sin errores PHP.
- [ ] Si se tocaron assets: `cd web/assets/src && yarn webpack:build:dev` sin errores.
- [ ] Si se añadió una clase: `composer dump-autoload` y verificar que se carga.
- [ ] Si se tocó `System.php` o `classes/*`: ejecutar los tests existentes y añadir
      cobertura nueva:
      `./vendor/bin/phpunit -c files/Tests/Config/phpunit.xml`
- [ ] Revisar `docker compose logs web` en busca de warnings/errores PHP.
- [ ] No dejar `error_log` con entradas nuevas relacionadas al cambio.
- [ ] Si el cambio es relevante para los consumidores, documentarlo (changelog / commit
      message claro) para que los proyectos padre puedan actualizar la referencia a sabiendas.

## Notas de Seguridad

- No commitear secretos, credenciales ni URLs de API internas.
- `System::encrypt` / `System::decrypt` usan `openssl` — no hardcodear claves en el código;
  usar configuración del proyecto consumidor.
- `System::decode_token` valida firma JWT — no relajar la validación sin motivo justificado.
- `System::curl` debe usar HTTPS para datos sensibles; no desactivar verificación SSL salvo
  en entornos controlados y documentados.

## Contacto

- **Mantenedor:** Memo Cachu — gcachu.o@gmail.com
- **Repo:** https://github.com/gcachuo/SimpleAPI.git
