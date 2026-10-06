#!/bin/bash
# Installe ou met à jour la pré-production sur la Raspberry Pi.
#
# Lancé par scripts/preprod.sh (ou .ps1), qui a déjà copié ici le plugin et ce
# dossier. Sans danger à relancer : WordPress n'est installé qu'une fois, et
# les réglages saisis dans l'administration (Discord, HelloAsso, PayPal) sont
# conservés.
set -euo pipefail

cd "$(dirname "$0")"
mkdir -p www

# Le nom Tailscale de la Pi n'est écrit nulle part dans le dépôt : on le lit ici.
if [ ! -f .env ] || ! grep -q "^PREPROD_HOTE=" .env; then
  HOTE="$(tailscale status --json | python3 -c 'import json,sys; print(json.load(sys.stdin)["Self"]["DNSName"].rstrip("."))')"
  {
    echo "# Réglages de la pré-production, propres à cette Pi. Jamais dans Git."
    echo "PREPROD_HOTE=$HOTE"
    echo "# Compte qui envoie les e-mails (Gmail : un « mot de passe d'application »)."
    echo "SMTP_UTILISATEUR="
    echo "SMTP_MOT_DE_PASSE="
    echo "# Seules ces adresses reçoivent des e-mails (motifs séparés par des virgules)."
    echo "DESTINATAIRES_AUTORISES="
  } >> .env
  chmod 600 .env
fi
set -a; . ./.env; set +a

echo "### Conteneurs"
docker compose up -d 2>&1 | grep -vE "^\s*$" | tail -6

wp() { docker compose exec -T cli wp "$@"; }

echo "### Attente de la base"
for i in $(seq 1 60); do
  if wp db query "SELECT 1" >/dev/null 2>&1; then break; fi
  sleep 3
done

if ! wp core is-installed >/dev/null 2>&1; then
  echo "### Installation de WordPress"
  MDP="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)"
  wp core install --url="https://$PREPROD_HOTE:8443" --title="Pré-production Nyassobi" \
    --admin_user=bureau --admin_password="$MDP" --admin_email=bureau@preprod.invalid --skip-email >/dev/null
  wp language core install fr_FR --activate >/dev/null 2>&1 || true
  printf 'Administration : https://%s:8443/wp-admin/\nIdentifiant : bureau\nMot de passe : %s\n' "$PREPROD_HOTE" "$MDP" > IDENTIFIANTS.txt
  chmod 600 IDENTIFIANTS.txt
  wp rewrite structure "/%postname%/" >/dev/null
fi

echo "### Extensions"
wp plugin is-installed wp-graphql || wp plugin install wp-graphql >/dev/null
wp plugin activate wp-graphql nyassobi-wp-plugin >/dev/null 2>&1 || true

echo "### Bureau et mot de passe des données"
if ! grep -q "Mot de passe du bureau" IDENTIFIANTS.txt 2>/dev/null; then
  COFFRE="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"
  wp eval "Nyassobi_Vault::setup('$COFFRE');"
  printf 'Mot de passe du bureau (données chiffrées) : %s\n' "$COFFRE" >> IDENTIFIANTS.txt
fi
wp eval 'update_option("nyassobi_bureau_users", [get_user_by("login", "bureau")->ID], false);'

echo "### Réglages de départ"
# Seulement les valeurs encore vides : ce qui a été saisi dans l'administration est gardé.
wp eval '
$defauts = [
    "board_size" => "1",
    "fee_normal" => "20",
    "fee_reduced" => "15",
    "helloasso_org_slug" => "",
    "helloasso_sandbox" => "1",
    "paypal_sandbox" => "1",
    "reminder_days" => "7",
    "expiry_days" => "30",
    "finalize_reminder_days" => "15",
    "paid_retention_days" => "30",
    "renewal_reminder_date" => "15/08",
];
$actuels = get_option("nyassobi_membership", []);
$actuels = is_array($actuels) ? $actuels : [];
foreach ($defauts as $cle => $valeur) {
    if (! isset($actuels[$cle]) || "" === $actuels[$cle]) {
        $actuels[$cle] = $valeur;
    }
}
$actuels["bureau_email"] = getenv("SMTP_UTILISATEUR") ?: ($actuels["bureau_email"] ?? "");
update_option("nyassobi_membership", $actuels);
$principal = get_option("nyassobi_wp_plugin", []);
$principal["contact_email"] = getenv("SMTP_UTILISATEUR") ?: ($principal["contact_email"] ?? "");
update_option("nyassobi_wp_plugin", $principal);
'

OUVERT="$(curl -s -X POST http://127.0.0.1:8506/index.php?graphql -H 'content-type: application/json' -d '{"query":"{ nyassobiMembershipOpen }"}' | grep -o '"nyassobiMembershipOpen":[a-z]*' || true)"
echo "  circuit : ${OUVERT:-?} (false tant que Discord n'est pas renseigné dans l'administration)"
[ -n "${SMTP_UTILISATEUR:-}" ] || echo "  e-mails : SMTP_UTILISATEUR vide dans .env, aucun e-mail ne partira"
[ -n "${DESTINATAIRES_AUTORISES:-}" ] || echo "  e-mails : DESTINATAIRES_AUTORISES vide dans .env, tous les e-mails seront bloqués"
echo "### Pré-production prête"
