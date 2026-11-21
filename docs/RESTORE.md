# Восстановление FileHub

Комплект состоит из независимых архивов:

- `filehub-service-*.tar.gz` — исходники, конфиги, fixtures, тесты и документация;
- `filehub-git-*.bundle` — полная git-история для clone/fetch;
- `filehub-secrets-*.tar.gz` — `.env`, TLS key/cert; хранить только приватно;
- `filehub-state-*.tar.gz` — `state/ops10` и `state/upload_tmp`;
- `filehub-jti-*.tar.gz` — содержимое volume `filehub-unified-rc02_filehub_jti`.

SQL-базы у FileHub нет: перечисленные state-каталоги и JTI-volume являются
источником истины для восстановления.

## Порядок

1. Развернуть исходники:

   ```bash
   sudo mkdir -p /opt/filehub-unified-rc02
   sudo tar -xzf filehub-service-*.tar.gz -C /opt/filehub-unified-rc02 --strip-components=1
   ```

2. Восстановить секреты и права:

   ```bash
   sudo tar -xzf filehub-secrets-*.tar.gz -C /opt/filehub-unified-rc02 --numeric-owner
   sudo chmod 600 /opt/filehub-unified-rc02/.env
   sudo chmod 600 /opt/filehub-unified-rc02/pki/server.key
   sudo chmod 644 /opt/filehub-unified-rc02/pki/server.crt
   ```

3. Восстановить файловое состояние с сохранением UID/GID:

   ```bash
   sudo tar -xzf filehub-state-*.tar.gz -C /opt/filehub-unified-rc02 --numeric-owner
   ```

   Не меняйте владельца `state/ops10` и `state/upload_tmp` вручную: на хосте
   используется uid/gid контейнера.

4. Восстановить JTI-volume:

   ```bash
   docker volume create filehub-unified-rc02_filehub_jti
   sudo tar -xzf filehub-jti-*.tar.gz \
     -C /var/lib/docker/volumes/filehub-unified-rc02_filehub_jti/_data --numeric-owner
   ```

5. Проверить конфиг и запустить:

   ```bash
   cd /opt/filehub-unified-rc02
   docker compose config >/dev/null
   docker compose up -d
   curl -fsS http://127.0.0.1:18444/_health
   ```

6. Установить alert timer и проверить виджет:

   ```bash
   sudo APPLY=1 bash /opt/filehub-unified-rc02/deploy/install-filehub-alerts.sh
   ```

   Затем установить `dist/FileHub_Widget_2.8.21.zip` через amoCRM и полностью
   перезагрузить открытые карточки.

## Проверка архивов

Рядом с архивами лежит `SHA256SUMS`. Проверка:

```bash
cd artifacts
sha256sum -c SHA256SUMS
```

