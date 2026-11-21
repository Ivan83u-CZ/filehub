# FileHub Unified RC02

Сервис файлов пациентов для карточки amoCRM: PHP-FPM API, nginx gateway,
thumbnail worker, подписанные ссылки Mail.ru Cloud и виджет `2.8.21`.

## Состав

- `backend/app` — PHP API и прикладная логика.
- `backend/nginx`, `backend/php` — gateway и пулы PHP-FPM.
- `backend/thumbnailer` — отдельный worker превью.
- `backend/fixtures` — синтетические файлы для тестов.
- `backend/tests`, `widget/*_test.js`, `tests/` — автоматические проверки.
- `widget/` — актуальный исходник виджета `2.8.21`.
- `deploy/` — apply/rollback, мониторинг, fail2ban и telemetry-инструменты.
- `dist/FileHub_Widget_2.8.21.zip` — готовый пакет для установщика amoCRM.
- `docs/` — runbook, восстановление и история remediation.

## Быстрая проверка

```bash
bash run-tests.sh
sha256sum -c dist/FileHub_Widget_2.8.21.zip.sha256
```

Тесты требуют `node`; PHP-тесты запускаются при наличии `php` или через
`validate/php-run.sh` на подготовленной машине.

## Данные и отсутствие SQL

У сервиса нет MySQL/PostgreSQL/SQLite. Состояние хранится в:

- `state/ops10` — telemetry, thumbnails, activity queue и прочие данные;
- `state/upload_tmp` — временные загрузки;
- Docker volume `filehub-unified-rc02_filehub_jti` — сессии и JTI.

Эти каталоги не входят в git. Для переноса/восстановления используйте архивы,
описанные в `docs/RESTORE.md`.

## Секреты

`.env`, `pki/server.key` и `pki/server.crt` в репозиторий не добавляются.
На сервере они должны иметь минимальные права; `.env.example` содержит только
имена переменных и безопасные заглушки.

## Развёртывание

Основной порядок описан в `docs/DEPLOYMENT-RUNBOOK.md`. Все apply-скрипты по
умолчанию работают в dry-run и требуют `APPLY=1`.

Виджет устанавливается отдельно через установщик amoCRM из
`dist/FileHub_Widget_2.8.21.zip`. Наличие файла в `dist/` не означает, что эта
версия уже активна в amoCRM — версию подтверждает поле `widget_version` в
телеметрии.

