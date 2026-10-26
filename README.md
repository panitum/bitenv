# Bitrix Environment

Универсальное docker-окружение для локальной разработки на 1С-Битрикс. Всё, что отличается от проекта к проекту (домен, порты, версия PHP, пароли БД), вынесено в `docker/.env` — конфиги сервисов при этом не меняются.

## Возможности

| Сервис    | Образ                       | Назначение |
|-----------|-----------------------------|------------|
| `nginx`   | `nginx:1.31-alpine`         | HTTPS (HTTP/2), редирект с HTTP, отдача статики с кешем на 30 дней, ЧПУ через `/bitrix/urlrewrite.php`, запрет доступа к служебным путям Битрикса. Запросы на неизвестный домен или IP отклоняются (444) |
| `php`     | сборка из `docker/php`      | PHP-FPM (версия задаётся в `.env`) с расширениями `mysqli`, `pdo_mysql`, `gd`, `zip`, `intl`, `opcache`, `bcmath`, `exif`, `sockets`, `redis`; опционально Xdebug. Настройки PHP и пула FPM под требования «Проверки системы» |
| `cron`    | тот же образ, что и `php`   | Раз в минуту запускает агенты и почтовые события (`cron_events.php`) |
| `mariadb` | `mariadb:10.11.19`          | База данных с параметрами под Битрикс (`utf8mb4`, `READ-COMMITTED`, пустой `sql_mode` и др.) |
| `redis`   | `redis:7.4.11-alpine`       | Кеш Битрикса (без сохранения на диск, LRU, 256 МБ) |
| `mailpit` | `axllent/mailpit`           | Перехват всей исходящей почты сайта (`mail()` → `msmtp` → mailpit) с веб-интерфейсом |

Дополнительно:

- Пользователь `www-data` в контейнерах PHP получает UID/GID хоста — файлы, созданные Битриксом, остаются доступными на хосте без `chown`.
- Корневой сертификат mkcert добавляется в доверенные внутри `php` и `cron`, а домен сайта доступен из контейнеров как сетевой алиас `nginx` — поэтому запросы PHP к собственному сайту по HTTPS работают (пункт «Работа с сокетами» в «Проверке системы»).
- Xdebug встраивается в образ по флагу и включается/выключается без пересборки.

## Структура

```
.
├── docker/
│   ├── .env.example                  ← шаблон настроек окружения
│   ├── .env                          ← настройки проекта (не в git)
│   ├── docker-compose.yml
│   ├── bitrix/
│   │   └── settings_extra.php        ← шаблон подключения кеша Битрикса к Redis
│   ├── mariadb/
│   │   └── bitrix.cnf                ← параметры MariaDB под Битрикс
│   ├── nginx/
│   │   ├── nginx.conf                ← общие настройки (gzip, лимиты, логи)
│   │   ├── templates/
│   │   │   └── s1.conf.template      ← конфиг сайта; ${SERVER_NAME} и ${HTTPS_PORT} подставляются из .env
│   │   ├── snippets/
│   │   │   ├── bitrix-deny.conf      ← запрет служебных путей и файлов
│   │   │   ├── fastcgi-bitrix.conf   ← параметры передачи запроса в PHP-FPM
│   │   │   └── security-headers.conf
│   │   └── conf.d/                   ← генерируется nginx из templates при старте (не в git)
│   ├── php/
│   │   ├── Dockerfile
│   │   ├── conf.d/bitrix.ini         ← php.ini под Битрикс (+ opcache)
│   │   ├── conf.d/xdebug.ini         ← настройки Xdebug
│   │   ├── php-fpm.d/bitrix.conf     ← пул процессов PHP-FPM
│   │   ├── crontab                   ← задания cron для www-data
│   │   └── msmtprc                   ← отправка почты в mailpit
│   └── private/                      ← не в git
│       ├── ssl/                      ← сертификаты сайта и rootCA.pem (mkcert)
│       └── mariadb_data/             ← файлы базы данных
└── src/                              ← не в git
    └── www/                          ← корень сайта (bitrix/, local/, upload/, index.php)
```

Все команды `docker compose` выполняются из папки `docker/`.

## Быстрый старт

### 0. Требования

- Docker с Compose v2
- [mkcert](https://github.com/FiloSottile/mkcert) (`brew install mkcert` на macOS) и однократно выполненный `mkcert -install`

### 1. Создать `.env`

```bash
cd docker
cp .env.example .env
```

Заполнить значения:

| Переменная | Описание |
|---|---|
| `COMPOSE_PROJECT_NAME` | Префикс имён контейнеров и сети. Уникальный для каждого проекта на машине |
| `SERVER_NAME` | Домен сайта, например `bitrix-dev.local` |
| `TZ` | Часовой пояс для PHP, MariaDB и nginx |
| `HTTP_PORT`, `HTTPS_PORT` | Порты сайта на хосте |
| `MYSQL_PORT`, `MAILPIT_PORT` | Порты MariaDB и веб-интерфейса mailpit на `127.0.0.1` хоста (по умолчанию `3306` и `8025`) |
| `PHP_VERSION` | Тег образа `php:<версия>-fpm-alpine` |
| `HOST_UID`, `HOST_GID` | Результат `id -u` и `id -g` на хосте |
| `INSTALL_XDEBUG` | `true` — встроить Xdebug в образ (по умолчанию `false`) |
| `XDEBUG_MODE` | `off` / `debug` / `profile` (по умолчанию `off`) |
| `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | База и пользователь создаются при первом запуске MariaDB |

### 2. Прописать домен в hosts

```bash
echo "127.0.0.1 bitrix-dev.local" | sudo tee -a /etc/hosts
```

### 3. Выпустить сертификат и скопировать rootCA.pem

Сертификат должен называться по домену из `SERVER_NAME` — без него nginx не стартует. `rootCA.pem` нужен контейнерам PHP, чтобы доверять сайту.

```bash
mkdir -p private/ssl
mkcert -cert-file private/ssl/bitrix-dev.local.pem -key-file private/ssl/bitrix-dev.local-key.pem bitrix-dev.local
cp "$(mkcert -CAROOT)/rootCA.pem" private/ssl/
```

В итоге в `docker/private/ssl/` должны лежать:

```
bitrix-dev.local.pem
bitrix-dev.local-key.pem
rootCA.pem
```

### 4. Подготовить корень сайта

```bash
mkdir -p ../src/www
```

Положить в `src/www/`:

- `bitrixsetup.php` — для новой установки, или
- `restore.php` и архив — для переноса существующего проекта.

### 5. Запустить

```bash
docker compose up -d --build
```

Первый старт MariaDB занимает до минуты: `php` и `cron` ждут, пока база станет `healthy` (`docker compose ps`).

### 6. Установить Битрикс

Открыть `https://bitrix-dev.local/bitrixsetup.php` (или `restore.php`). Параметры подключения к БД:

- сервер: `mariadb`
- база, пользователь, пароль — из `.env`

Для перенесённого проекта то же самое прописать в `bitrix/.settings.php` (и `bitrix/php_interface/dbconn.php`, если он используется).

### 7. После установки

**Кеш в Redis.** Скопировать шаблон в корень сайта:

```bash
cp bitrix/settings_extra.php ../src/www/local/.settings_extra.php
```

**Агенты на cron.** Контейнер `cron` уже запускает `cron_events.php` каждую минуту; чтобы агенты перестали выполняться на хитах:

1. В `bitrix/php_interface/dbconn.php` добавить `define("BX_CRONTAB_SUPPORT", true);`
2. В админке → *Настройки → Инструменты → Командная PHP-строка* выполнить:

   ```php
   COption::SetOptionString("main", "agents_use_crontab", "N");
   COption::SetOptionString("main", "check_agents", "N");
   ```

**Почта.** Отправить тестовое письмо и проверить его в mailpit.

**Проверка системы.** Запустить в админке и пройтись по замечаниям.

## Адреса и доступы

| Что | Где |
|---|---|
| Сайт | `https://<SERVER_NAME>` (HTTP редиректит на HTTPS) |
| Mailpit | `http://localhost:<MAILPIT_PORT>` |
| MariaDB с хоста | `127.0.0.1:<MYSQL_PORT>`, учётные данные из `.env` |
| MariaDB / Redis из контейнеров | хосты `mariadb:3306` / `redis:6379` |

При одновременном запуске нескольких проектов у каждого должны быть свои `COMPOSE_PROJECT_NAME`, `HTTP_PORT`, `HTTPS_PORT`, `MYSQL_PORT` и `MAILPIT_PORT`.

## Xdebug

1. В `.env`: `INSTALL_XDEBUG=true`, затем `docker compose up -d --build`.
2. Для включения: `XDEBUG_MODE=debug` (или `profile`), затем `docker compose up -d` — без пересборки.
3. Отладка запускается по триггеру (`start_with_request = trigger`): расширение Xdebug Helper в браузере, IDE key `PHPSTORM`, порт `9003`.
4. В PhpStorm: *Settings → PHP → Servers* — Host = `SERVER_NAME`, порт `443`, включить path mappings: `src/www` → `/var/www/html`.

Профили (`profile`) сохраняются в `/tmp` контейнера: `docker compose cp php:/tmp/cachegrind.out.<pid> .`

## Полезные команды

```bash
docker compose ps                          # статус сервисов
docker compose logs -f nginx php           # логи в реальном времени
docker compose exec php sh                 # консоль в контейнере PHP (сразу в корне сайта)
docker compose exec nginx nginx -T         # итоговый конфиг nginx с подставленным доменом
docker compose restart nginx               # применить изменения в nginx/templates
docker compose restart php cron            # применить изменения ini / fpm / msmtp / crontab
docker compose build php cron && docker compose up -d   # после изменения Dockerfile или PHP_VERSION
docker compose exec mariadb sh -c 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > dump.sql
```

## Замечания

- Переменные `MYSQL_*` применяются только при первой инициализации базы. Данные хранятся в `docker/private/mariadb_data` (bind mount), поэтому `docker compose down -v` их **не** удаляет — для пересоздания базы нужно очистить эту папку.
- Файл `crontab` должен заканчиваться переводом строки, иначе последнее задание игнорируется.
- Для прода: сертификат Let's Encrypt, HSTS (`security-headers.conf`), убрать mailpit и проброс порта MariaDB, прописать реальный SMTP в `msmtprc`.
