# BitEnv (Bitrix Environment)

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
- Два режима публикации сайта и mailpit, переключаются одной переменной: через общий прокси Traefik — несколько проектов одновременно на 80/443, каждый на своём домене; или напрямую на порты хоста, как обычный автономный стек.

## Структура

```
.
├── docker/
│   ├── .env.example                  ← шаблон настроек окружения
│   ├── .env                          ← настройки проекта (не в git)
│   ├── docker-compose.yml            ← все сервисы, без публикации сайта и mailpit наружу
│   ├── docker-compose.traefik.yml    ← режим «Traefik»: сеть bitenv-proxy и labels
│   ├── docker-compose.ports.yml      ← режим «без Traefik»: порты хоста
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

Все команды ниже выполняются из корня проекта. Compose ищет файлы из `COMPOSE_FILE` относительно текущей папки, поэтому `docker compose` запускается в подоболочке `(cd docker && docker compose …)` — текущая папка терминала при этом не меняется. Домен в командах берётся из `SERVER_NAME` в `docker/.env`, так что их можно копировать без правок.

## Быстрый старт

### 0. Требования

- Docker с Compose v2
- [mkcert](https://github.com/FiloSottile/mkcert) (`brew install mkcert` на macOS) и однократно выполненный `mkcert -install`
- Только для режима «Traefik»: установленный отдельным проектом и запущенный [bitenv-proxy](https://github.com/panitum/bitenv-proxy) — см. [Режим «Traefik»](#режим-traefik). Режим по умолчанию (без Traefik) ничего дополнительно не требует

### 1. Создать `.env`

```bash
cp docker/.env.example docker/.env
```

Заполнить значения:

| Переменная | Описание |
|---|---|
| `COMPOSE_PROJECT_NAME` | Префикс имён контейнеров и сети. Уникальный для каждого проекта на машине |
| `SERVER_NAME` | Домен сайта, например `bitrix-dev.local` |
| `TZ` | Часовой пояс для PHP, MariaDB и nginx |
| `COMPOSE_FILE` | Режим публикации — см. [Режимы публикации](#режимы-публикации) |
| `MYSQL_PORT` | Порт MariaDB на `127.0.0.1` хоста (по умолчанию `3306`) |
| `HTTP_PORT`, `HTTPS_PORT`, `MAILPIT_PORT` | Порты сайта и mailpit на хосте, только в режиме без Traefik (по умолчанию `80`, `443`, `8025`) |
| `PHP_VERSION` | Тег образа `php:<версия>-fpm-alpine` |
| `HOST_UID`, `HOST_GID` | Результат `id -u` и `id -g` на хосте |
| `INSTALL_XDEBUG` | `true` — встроить Xdebug в образ (по умолчанию `false`) |
| `XDEBUG_MODE` | `off` / `debug` / `profile` (по умолчанию `off`) |
| `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD` | База и пользователь создаются при первом запуске MariaDB |

### 2. Прописать домен в hosts

```bash
SERVER_NAME=$(sed -n 's/^SERVER_NAME=//p' docker/.env)
echo "127.0.0.1 $SERVER_NAME mail.$SERVER_NAME" | sudo tee -a /etc/hosts
```

Поддомен `mail.` нужен только в режиме «Traefik».

### 3. Выпустить сертификат и скопировать rootCA.pem

Сертификат должен называться по домену из `SERVER_NAME` — без него nginx не стартует. `rootCA.pem` нужен контейнерам PHP, чтобы доверять сайту.

```bash
SERVER_NAME=$(sed -n 's/^SERVER_NAME=//p' docker/.env)
mkdir -p docker/private/ssl
mkcert -cert-file "docker/private/ssl/$SERVER_NAME.pem" -key-file "docker/private/ssl/$SERVER_NAME-key.pem" "$SERVER_NAME"
cp "$(mkcert -CAROOT)/rootCA.pem" docker/private/ssl/
```

В итоге в `docker/private/ssl/` должны лежать:

```
<SERVER_NAME>.pem
<SERVER_NAME>-key.pem
rootCA.pem
```

### 4. Подготовить корень сайта

```bash
mkdir -p src/www
```

Положить в `src/www/`:

- `bitrixsetup.php` — для новой установки, или
- `restore.php` и архив — для переноса существующего проекта.

### 5. Запустить

```bash
(cd docker && docker compose up -d --build)
```

Первый старт MariaDB занимает до минуты: `php` и `cron` ждут, пока база станет `healthy` (`docker compose ps`).

### 6. Установить Битрикс

Открыть `https://<SERVER_NAME>/bitrixsetup.php` (или `restore.php`). Параметры подключения к БД:

- сервер: `mariadb`
- база, пользователь, пароль — из `.env`

Для перенесённого проекта то же самое прописать в `bitrix/.settings.php` (и `bitrix/php_interface/dbconn.php`, если он используется).

### 7. После установки

**Кеш в Redis.** Скопировать шаблон в корень сайта:

```bash
mkdir -p src/www/local
cp docker/bitrix/settings_extra.php src/www/local/.settings_extra.php
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

## Режимы публикации

Базовый `docker-compose.yml` не публикует сайт и mailpit наружу — это делает один из двух overlay-файлов. Какие файлы читать, Compose берёт из переменной `COMPOSE_FILE` в `.env`:

| Режим | `COMPOSE_FILE` | Сайт | Mailpit |
|---|---|---|---|
| Без Traefik (по умолчанию) | `docker-compose.yml:docker-compose.ports.yml` | `https://<SERVER_NAME>:<HTTPS_PORT>` (порт не пишется, если `443`) | `http://localhost:<MAILPIT_PORT>` |
| Traefik | `docker-compose.yml:docker-compose.traefik.yml` | `https://<SERVER_NAME>` | `http://mail.<SERVER_NAME>` |

Переключение — поменять `COMPOSE_FILE` и выполнить `docker compose up -d`: Compose сам пересоздаст nginx и mailpit. Остальные контейнеры не перезапускаются.

- Режим без Traefik (по умолчанию в `.env.example`) — автономный стек, ничего дополнительно ставить не нужно. Порты 80/443 в нём занимает nginx проекта, поэтому одновременно с Traefik он запустится только на других `HTTP_PORT`/`HTTPS_PORT` — или остановить прокси: `docker compose stop` в папке проекта bitenv-proxy.
- Режим «Traefik» — для нескольких проектов одновременно: прокси принимает 80/443 и раздаёт запросы по домену.
- Без `COMPOSE_FILE` Compose прочитает только `docker-compose.yml`: всё запустится, но сайт будет недоступен с хоста.

### Режим «Traefik»

Traefik не входит в это окружение: он ставится **отдельным проектом** из репозитория [bitenv-proxy](https://github.com/panitum/bitenv-proxy) — один на машину для всех проектов. Клонировать его нужно в отдельную папку, вне этого проекта. Инструкция по установке и настройке (включая создание общей сети `bitenv-proxy`) и описание маршрутизации находятся внутри репозитория.

Когда Traefik запущен, в `docker/.env` указать `COMPOSE_FILE=docker-compose.yml:docker-compose.traefik.yml` и выполнить `docker compose up -d`. Если сети `bitenv-proxy` нет, Compose завершится ошибкой `network bitenv-proxy declared as external, but could not be found`.

## Адреса и доступы

| Что | Где |
|---|---|
| Сайт и mailpit | см. [Режимы публикации](#режимы-публикации) |
| MariaDB с хоста | `127.0.0.1:<MYSQL_PORT>`, учётные данные из `.env` |
| MariaDB / Redis из контейнеров | хосты `mariadb:3306` / `redis:6379` |

При одновременном запуске нескольких проектов у каждого должны быть свои `COMPOSE_PROJECT_NAME` (в режиме «Traefik» из него строятся имена маршрутов), `SERVER_NAME` и `MYSQL_PORT`, а в режиме без Traefik — ещё и `HTTP_PORT`, `HTTPS_PORT`, `MAILPIT_PORT`.

## Xdebug

1. В `docker/.env`: `INSTALL_XDEBUG=true`, затем `docker compose up -d --build`.
2. Для включения: `XDEBUG_MODE=debug` (или `profile`), затем `docker compose up -d` — без пересборки.
3. Отладка запускается по триггеру (`start_with_request = trigger`): расширение Xdebug Helper в браузере, IDE key `PHPSTORM`, порт `9003`.
4. В PhpStorm: *Settings → PHP → Servers* — Host = `SERVER_NAME`, порт `443` (или `HTTPS_PORT` в режиме без Traefik), включить path mappings: `src/www` → `/var/www/html`.

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
