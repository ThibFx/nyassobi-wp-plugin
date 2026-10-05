#!/bin/bash
# Envoie le plugin dans le bac à sable de la Raspberry Pi et l'(ré)installe.
# Pendant de scripts/bac-a-sable.ps1 (Windows).
#
#   ./scripts/bac-a-sable.sh
#   HOTE=autre-alias ./scripts/bac-a-sable.sh
#
# La copie du site branchée sur ce bac à sable se déploie depuis le dépôt du
# site : ./scripts/deployer.sh --bac-a-sable
set -euo pipefail

HOTE="${HOTE:-nv-pi}"
DOSSIER="nyassobi-bac-a-sable"
cd "$(dirname "$0")/.."

echo "### Envoi du plugin et du bac à sable vers $HOTE"
ARCHIVE="$(mktemp -d)/bac-a-sable.tgz"
COPYFILE_DISABLE=1 tar --no-xattrs -czf "$ARCHIVE" nyassobi-wp-plugin.php includes bac-a-sable 2>/dev/null \
  || tar -czf "$ARCHIVE" nyassobi-wp-plugin.php includes bac-a-sable
scp -q "$ARCHIVE" "$HOTE:/tmp/bac-a-sable.tgz"
rm -f "$ARCHIVE"

# Le plugin est remplacé en entier, pour qu'un fichier supprimé ici le soit aussi là-bas.
ssh "$HOTE" "set -e
  mkdir -p ~/$DOSSIER && cd ~/$DOSSIER
  rm -rf .arrivee && mkdir .arrivee && tar -xzf /tmp/bac-a-sable.tgz -C .arrivee && rm -f /tmp/bac-a-sable.tgz
  rm -rf plugin mu && mkdir plugin
  mv .arrivee/nyassobi-wp-plugin.php .arrivee/includes plugin/
  cp -r .arrivee/bac-a-sable/. . && rm -rf .arrivee
  bash installer.sh"

printf '\n\033[32mBac à sable : http://nv-pi:8504/bac-a-sable/ (Tailscale : http://nv-pi:8504/bac-a-sable/)\033[0m\n'
