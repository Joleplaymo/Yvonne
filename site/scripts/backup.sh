#!/bin/sh
# Sauvegarde à chaud de la base des commandes dans ./backups/ (à lancer depuis site/).
# Exemple cron quotidien : 0 3 * * * cd /opt/vanessagandino/site && ./scripts/backup.sh
set -eu
mkdir -p backups
stamp=$(date +%Y-%m-%d_%H%M)
docker compose exec -T app node -e "
  const { DatabaseSync } = require('node:sqlite');
  require('node:fs').rmSync('/data/backup.sqlite', { force: true });
  new DatabaseSync('/data/commandes.sqlite').exec(\"VACUUM INTO '/data/backup.sqlite'\");
"
docker compose cp app:/data/backup.sqlite "backups/commandes-$stamp.sqlite"
# Garde les 30 dernières sauvegardes.
ls -1t backups/commandes-*.sqlite | tail -n +31 | xargs -r rm --
echo "OK : backups/commandes-$stamp.sqlite"
