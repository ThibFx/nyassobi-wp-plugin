#!/bin/bash
# Installe ou met à jour le bac à sable sur la Raspberry Pi.
#
# Lancé par scripts/bac-a-sable.sh (ou .ps1), qui a déjà copié ici le plugin et
# ce dossier. Sans danger à relancer : WordPress n'est installé qu'une fois.
set -euo pipefail

cd "$(dirname "$0")"
mkdir -p www

echo "### Conteneurs"
docker compose up -d 2>&1 | grep -vE "^\s*$" | tail -6

wp() { docker compose exec -T cli wp "$@"; }

echo "### Attente de la base"
# wp-config.php est écrit par le conteneur WordPress à son premier démarrage.
for i in $(seq 1 60); do
  if wp db query "SELECT 1" >/dev/null 2>&1; then break; fi
  sleep 3
done

if ! wp core is-installed >/dev/null 2>&1; then
  echo "### Installation de WordPress"
  MDP="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)"
  wp core install --url=http://localhost:8504 --title="Bac à sable Nyassobi" \
    --admin_user=bureau --admin_password="$MDP" --admin_email=bureau@bac-a-sable.test --skip-email >/dev/null
  wp language core install fr_FR --activate >/dev/null 2>&1 || true
  printf 'Vue du bureau : http://<pi>:8504/wp-admin/\nIdentifiant : bureau\nMot de passe : %s\n' "$MDP" > IDENTIFIANTS.txt
  chmod 600 IDENTIFIANTS.txt
  wp rewrite structure "/%postname%/" >/dev/null
fi

echo "### Extensions"
wp plugin is-installed wp-graphql || wp plugin install wp-graphql >/dev/null
wp plugin activate wp-graphql nyassobi-wp-plugin >/dev/null

echo "### Réglages de test"
# Une paire de clés Ed25519 propre au bac à sable : la page « Discord simulé »
# signe les clics comme le ferait Discord.
wp eval '
if (! get_option("bac_cle_secrete")) {
    $paire = sodium_crypto_sign_keypair();
    update_option("bac_cle_secrete", bin2hex(sodium_crypto_sign_secretkey($paire)), false);
    update_option("bac_cle_publique", bin2hex(sodium_crypto_sign_publickey($paire)), false);
}
update_option("nyassobi_membership", [
    "discord_application_id" => "1",
    "discord_public_key" => get_option("bac_cle_publique"),
    "discord_bot_token" => "jeton-du-bac-a-sable",
    "discord_channel_id" => "2",
    "discord_board_role_id" => "424242",
    "board_size" => "6",
    "bureau_email" => "bureau@bac-a-sable.test",
    "fee_normal" => "20",
    "fee_reduced" => "15",
    "helloasso_client_id" => "client-du-bac-a-sable",
    "helloasso_client_secret" => "secret-du-bac-a-sable",
    "helloasso_org_slug" => "nyassobi",
    "helloasso_sandbox" => "1",
    "paypal_client_id" => "client-du-bac-a-sable",
    "paypal_client_secret" => "secret-du-bac-a-sable",
    "paypal_sandbox" => "1",
    "reminder_days" => "7",
    "expiry_days" => "30",
    "finalize_reminder_days" => "15",
    "paid_retention_days" => "30",
    "discord_guild_id" => "3",
    "discord_member_role_id" => "5",
    "discord_invite_url" => "https://discord.gg/exemple-bac-a-sable",
    "sender_email" => "adhesion@nyassobi.fr",
    "sender_name" => "Nyassobi",
]);
$principal = get_option("nyassobi_wp_plugin", []);
$principal["contact_email"] = "contact@bac-a-sable.test";
update_option("nyassobi_wp_plugin", $principal);
'

OUVERT="$(curl -s -X POST http://localhost:8504/index.php?graphql -H 'content-type: application/json' -d '{"query":"{ nyassobiMembershipOpen }"}')"
echo "  $OUVERT"
echo "### Bac à sable prêt"
