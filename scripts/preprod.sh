#!/bin/bash
# Envoie le plugin dans la pré-production de la Raspberry Pi et l'(ré)installe.
# Pendant de scripts/preprod.ps1 (Windows).
#
#   ./scripts/preprod.sh
#   HOTE=autre-alias ./scripts/preprod.sh
#
# La copie du site branchée sur cette pré-production se déploie depuis le
# dépôt du site : ./scripts/deployer.sh --preprod
set -euo pipefail

HOTE="${HOTE:-nv-pi}"
DOSSIER="nyassobi-preprod"
cd "$(dirname "$0")/.."

echo "### Envoi du plugin et de la pré-production vers $HOTE"
ARCHIVE="$(mktemp -d)/preprod.tgz"
COPYFILE_DISABLE=1 tar --no-xattrs -czf "$ARCHIVE" nyassobi-wp-plugin.php includes preprod 2>/dev/null \
  || tar -czf "$ARCHIVE" nyassobi-wp-plugin.php includes preprod
scp -q "$ARCHIVE" "$HOTE:/tmp/preprod.tgz"
rm -f "$ARCHIVE"

# Le contenu est remplacé, pas les dossiers (montés par les conteneurs), et
# .env, propre à la Pi, n'est jamais touché.
ssh "$HOTE" "set -e
  mkdir -p ~/$DOSSIER/plugin ~/$DOSSIER/mu && cd ~/$DOSSIER
  rm -rf .arrivee && mkdir .arrivee && tar -xzf /tmp/preprod.tgz -C .arrivee && rm -f /tmp/preprod.tgz
  find plugin mu -mindepth 1 -delete
  mv .arrivee/nyassobi-wp-plugin.php .arrivee/includes plugin/
  cp -r .arrivee/preprod/. . && rm -rf .arrivee
  bash installer.sh"
