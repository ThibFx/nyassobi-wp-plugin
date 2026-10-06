<?php
/**
 * Plugin Name: Bac à sable des adhésions
 * Description: Simule Discord et la messagerie pour tester le circuit d'adhésion sans rien envoyer.
 *
 * À ne jamais installer sur le vrai WordPress : il intercepte tous les appels à
 * Discord et tous les e-mails.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const BAC_MESSAGES = 'bac_discord_messages';
const BAC_MAILS = 'bac_mails';
const BAC_FLASH = 'bac_flash';
const BAC_ROLE_CA = '424242';
const BAC_MEMBRES = ['ca-1' => 'Membre du CA 1', 'ca-2' => 'Membre du CA 2', 'ca-3' => 'Membre du CA 3', 'ca-4' => 'Membre du CA 4', 'ca-5' => 'Membre du CA 5', 'ca-6' => 'Membre du CA 6', 'invite' => 'Membre du serveur (hors CA)'];

const BAC_PAIEMENTS = 'bac_paiements';
const BAC_ROLES = 'bac_roles';

// Sur le bac à sable, on doit pouvoir envoyer autant de demandes qu'on veut.
add_filter('nyassobi_membership_submissions_per_hour', static fn (): int => 1000);

/** Hôte tapé dans le navigateur, sans port : le bac à sable marche en local comme par Tailscale. */
function bac_hote(): string
{
    $hote = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');

    return (string) preg_replace('/:\d+$/', '', $hote);
}

// Les e-mails renvoient vers la copie du site du bac à sable.
add_filter('nyassobi_membership_site_url', static fn (): string => 'http://' . bac_hote() . ':8505');

/** Réponse HTTP simulée. */
function bac_reponse(int $code, $corps): array
{
    return ['headers' => [], 'body' => is_string($corps) ? $corps : wp_json_encode($corps), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
}

/* HelloAsso, PayPal et les rôles Discord simulés. */
add_filter('pre_http_request', static function ($pre, $args, $url) {
    $url = (string) $url;
    $methode = strtoupper((string) ($args['method'] ?? 'GET'));
    $corps = is_string($args['body'] ?? null) ? (json_decode($args['body'], true) ?: []) : [];
    $paiements = get_option(BAC_PAIEMENTS, []);

    if (preg_match('#^https://api\.helloasso(-sandbox)?\.com/(.*)$#', $url, $m)) {
        $chemin = $m[2];
        if ('oauth2/token' === $chemin) {
            return bac_reponse(200, ['access_token' => 'ha-test', 'refresh_token' => 'ha-refresh', 'expires_in' => 1800]);
        }
        if ('POST' === $methode && preg_match('#checkout-intents$#', $chemin)) {
            $id = (string) wp_rand(100000, 999999);
            $paiements['ha-' . $id] = ['fournisseur' => 'HelloAsso', 'id' => $id, 'montant' => (int) $corps['totalAmount'], 'objet' => $corps['itemName'], 'retour' => $corps['returnUrl'], 'annulation' => $corps['backUrl'], 'metadata' => $corps['metadata'] ?? [], 'paye' => false];
            update_option(BAC_PAIEMENTS, $paiements, false);
            return bac_reponse(200, ['id' => (int) $id, 'redirectUrl' => 'http://' . bac_hote() . ':8504/bac-a-sable/paiement/?ref=ha-' . $id]);
        }
        if (preg_match('#checkout-intents/(\d+)$#', $chemin, $i)) {
            $paiement = $paiements['ha-' . $i[1]] ?? null;
            return bac_reponse(null === $paiement ? 404 : 200, ['id' => (int) $i[1]] + (! empty($paiement['paye']) ? ['order' => ['id' => (int) $i[1] + 1]] : []));
        }
        return bac_reponse(404, []);
    }

    if (preg_match('#^https://api-m\.(sandbox\.)?paypal\.com/(.*)$#', $url, $m)) {
        $chemin = $m[2];
        if ('v1/oauth2/token' === $chemin) {
            return bac_reponse(200, ['access_token' => 'pp-test', 'expires_in' => 3600]);
        }
        if ('POST' === $methode && 'v2/checkout/orders' === $chemin) {
            $id = 'PP' . strtoupper(wp_generate_password(10, false));
            $contexte = $corps['payment_source']['paypal']['experience_context'] ?? [];
            $unite = $corps['purchase_units'][0] ?? [];
            $paiements['pp-' . $id] = ['fournisseur' => 'PayPal', 'id' => $id, 'montant' => (int) round(((float) ($unite['amount']['value'] ?? 0)) * 100), 'objet' => $unite['description'] ?? '', 'reference' => $unite['reference_id'] ?? '', 'retour' => $contexte['return_url'] ?? '', 'annulation' => $contexte['cancel_url'] ?? '', 'approuve' => false, 'capture' => false];
            update_option(BAC_PAIEMENTS, $paiements, false);
            return bac_reponse(200, ['id' => $id, 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'http://' . bac_hote() . ':8504/bac-a-sable/paiement/?ref=pp-' . $id]]]);
        }
        if (preg_match('#^v2/checkout/orders/([A-Z0-9]+)(/capture)?$#', $chemin, $o)) {
            $cle = 'pp-' . $o[1];
            $paiement = $paiements[$cle] ?? null;
            if (null === $paiement) {
                return bac_reponse(404, []);
            }
            $statut = ['status' => $paiement['capture'] ? 'COMPLETED' : 'APPROVED', 'purchase_units' => [['reference_id' => $paiement['reference']]]];
            if (! empty($o[2])) {
                if (! $paiement['approuve']) {
                    return bac_reponse(422, ['name' => 'UNPROCESSABLE_ENTITY']);
                }
                if ($paiement['capture']) {
                    return bac_reponse(422, ['name' => 'ORDER_ALREADY_CAPTURED']);
                }
                $paiements[$cle]['capture'] = true;
                update_option(BAC_PAIEMENTS, $paiements, false);
                $statut['status'] = 'COMPLETED';
            }
            return bac_reponse(200, $statut);
        }
        return bac_reponse(404, []);
    }

    if (preg_match('#^https://discord\.com/api/v10/guilds/[^/]+/members/search\?(.*)$#', $url, $m)) {
        parse_str($m[1], $q);
        $pseudo = strtolower((string) ($q['query'] ?? ''));
        // « absent » dans le pseudo simule quelqu'un qui n'est pas sur le serveur.
        return bac_reponse(200, false !== strpos($pseudo, 'absent') ? [] : [['user' => ['id' => (string) crc32($pseudo), 'username' => $pseudo]]]);
    }
    if ('PUT' === $methode && preg_match('#^https://discord\.com/api/v10/guilds/[^/]+/members/([^/]+)/roles/([^/]+)$#', $url, $m)) {
        $roles = get_option(BAC_ROLES, []);
        array_unshift($roles, ['membre' => $m[1], 'date' => current_time('mysql')]);
        update_option(BAC_ROLES, array_slice($roles, 0, 30), false);
        return bac_reponse(204, '');
    }

    return $pre;
}, 5, 3);

/* Discord simulé : les messages postés ou modifiés par le plugin sont gardés ici. */
add_filter('pre_http_request', static function ($pre, $args, $url) {
    // Déjà simulé plus haut (rôles et membres du serveur).
    if (false !== $pre || 0 !== strpos((string) $url, 'https://discord.com/api/')) {
        return $pre;
    }
    $messages = get_option(BAC_MESSAGES, []);
    $body = json_decode((string) ($args['body'] ?? ''), true) ?: [];
    $method = strtoupper((string) ($args['method'] ?? 'GET'));
    $id = '';
    if ('POST' === $method && preg_match('#/channels/[^/]+/messages$#', $url)) {
        $id = (string) (time() . wp_rand(100, 999));
        $messages[$id] = ['body' => $body, 'date' => current_time('mysql')];
    } elseif ('PATCH' === $method && preg_match('#/messages/([^/]+)$#', $url, $m)) {
        $id = $m[1];
        if (isset($messages[$id])) {
            $messages[$id]['body'] = $body;
        }
    }
    update_option(BAC_MESSAGES, $messages, false);

    return ['headers' => [], 'body' => wp_json_encode(['id' => $id]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);

/* Messagerie simulée : les e-mails sont gardés au lieu de partir. */
add_filter('pre_wp_mail', static function ($null, $atts) {
    $mails = get_option(BAC_MAILS, []);
    $de = 'WordPress <wordpress@' . bac_hote() . '>';
    foreach ((array) ($atts['headers'] ?? []) as $entete) {
        if (0 === stripos((string) $entete, 'From:')) {
            $de = trim(substr((string) $entete, 5));
        }
    }
    array_unshift($mails, ['de' => $de, 'to' => is_array($atts['to']) ? implode(', ', $atts['to']) : (string) $atts['to'], 'subject' => (string) $atts['subject'], 'message' => (string) $atts['message'], 'date' => current_time('mysql')]);
    update_option(BAC_MAILS, array_slice($mails, 0, 60), false);

    return true;
}, 10, 2);

/** Fait ce que ferait Discord au clic : signe l'interaction et l'envoie au plugin. */
function bac_voter(string $message_id, string $custom_id, string $membre): void
{
    $secret = (string) get_option('bac_cle_secrete');
    $payload = wp_json_encode([
        'type' => 3,
        'data' => ['custom_id' => $custom_id],
        'member' => ['user' => ['id' => $membre], 'roles' => 'invite' === $membre ? ['1'] : [BAC_ROLE_CA]],
    ]);
    $horodatage = (string) time();
    $signature = bin2hex(sodium_crypto_sign_detached($horodatage . $payload, hex2bin($secret)));

    $requete = new WP_REST_Request('POST', '/nyassobi/v1/discord');
    $requete->set_body((string) $payload);
    $requete->set_header('content-type', 'application/json');
    $requete->set_header('x-signature-ed25519', $signature);
    $requete->set_header('x-signature-timestamp', $horodatage);
    $reponse = rest_do_request($requete);
    $data = $reponse->get_data();

    if (7 === (int) ($data['type'] ?? 0)) {
        $messages = get_option(BAC_MESSAGES, []);
        if (isset($messages[$message_id])) {
            $messages[$message_id]['body'] = $data['data'];
            update_option(BAC_MESSAGES, $messages, false);
        }
        set_transient(BAC_FLASH, BAC_MEMBRES[$membre] . ' a voté.', 60);
    } elseif (4 === (int) ($data['type'] ?? 0)) {
        set_transient(BAC_FLASH, 'Réponse visible du seul votant : « ' . ($data['data']['content'] ?? '') . ' »', 60);
    } else {
        set_transient(BAC_FLASH, 'Réponse inattendue (' . $reponse->get_status() . ').', 60);
    }

    // Le vrai WordPress envoie les e-mails de décision par WP-Cron ; ici on
    // les déclenche tout de suite pour voir le résultat.
    foreach ((array) _get_cron_array() as $moment => $taches) {
        foreach ((array) ($taches['nyassobi_membership_decided'] ?? []) as $tache) {
            wp_unschedule_event((int) $moment, 'nyassobi_membership_decided', $tache['args']);
            do_action_ref_array('nyassobi_membership_decided', $tache['args']);
        }
    }
}

/**
 * Fait comme si la demande avait été acceptée N jours plus tôt, puis lance la
 * tâche quotidienne : de quoi voir la relance et l'expiration sans attendre.
 */
function bac_avancer(int $demande, int $jours): void
{
    $accepte = (int) get_post_meta($demande, '_nyassobi_accepted_at', true);
    if ($accepte) {
        update_post_meta($demande, '_nyassobi_accepted_at', (string) ($accepte - $jours * DAY_IN_SECONDS));
        $paye = (int) get_post_meta($demande, '_nyassobi_paid_at', true);
        if ($paye) {
            update_post_meta($demande, '_nyassobi_paid_at', (string) ($paye - $jours * DAY_IN_SECONDS));
        }
        do_action(Nyassobi_Membership::PURGE_HOOK);
        set_transient(BAC_FLASH, sprintf('Demande n°%d : %d jours plus tard, tâche quotidienne lancée.', $demande, $jours), 60);
    }
}

/** Page de paiement factice, à la place de HelloAsso ou de PayPal. */
function bac_page_paiement(): void
{
    $ref = sanitize_text_field((string) ($_REQUEST['ref'] ?? ''));
    $paiements = get_option(BAC_PAIEMENTS, []);
    $paiement = $paiements[$ref] ?? null;
    if (null === $paiement) {
        wp_die('Paiement inconnu.');
    }
    $helloasso = 'HelloAsso' === $paiement['fournisseur'];

    if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
        check_admin_referer('bac_paiement');
        $choix = sanitize_key((string) ($_POST['choix'] ?? ''));
        if ('annuler' === $choix) {
            wp_redirect($paiement['annulation']);
            exit;
        }
        if ($helloasso) {
            $paiements[$ref]['paye'] = true;
            update_option(BAC_PAIEMENTS, $paiements, false);
            // Comme HelloAsso : une notification part vers WordPress dès le paiement.
            $notification = new WP_REST_Request('POST', '/nyassobi/v1/helloasso');
            $notification->set_body((string) wp_json_encode(['eventType' => 'Payment', 'data' => ['state' => 'Authorized'], 'metadata' => $paiement['metadata']]));
            rest_do_request($notification);
            if ('fermer' === $choix) {
                set_transient(BAC_FLASH, 'Paiement fait puis onglet fermé : seule la notification de HelloAsso a prévenu WordPress.', 60);
                wp_safe_redirect(home_url('/bac-a-sable/'));
                exit;
            }
            wp_redirect(add_query_arg(['checkoutIntentId' => $paiement['id'], 'code' => 'succeeded', 'orderId' => (int) $paiement['id'] + 1], $paiement['retour']));
            exit;
        }
        $paiements[$ref]['approuve'] = true;
        update_option(BAC_PAIEMENTS, $paiements, false);
        wp_redirect(add_query_arg(['token' => $paiement['id'], 'PayerID' => 'BACASABLE'], $paiement['retour']));
        exit;
    }

    $couleur = $helloasso ? '#2e2f5e' : '#003087';
    $accent = $helloasso ? '#4c40cf' : '#ffc439';
    $nonce = wp_create_nonce('bac_paiement');
    ?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($paiement['fournisseur']); ?> (simulé)</title>
<style>
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 16px/1.5 system-ui, sans-serif; background: #f4f5fa; color: #1d1d1f; }
  .carte { background: white; border-radius: 18px; padding: 28px; width: min(420px, 92vw); box-shadow: 0 20px 50px -20px rgb(0 0 0 / .3); }
  .bandeau { color: white; background: <?php echo esc_attr($couleur); ?>; margin: -28px -28px 20px; padding: 16px 28px; border-radius: 18px 18px 0 0; font-weight: 700; }
  .montant { font-size: 34px; font-weight: 800; margin: 4px 0 16px; }
  button { width: 100%; border: 0; border-radius: 999px; padding: 13px; font: inherit; font-weight: 700; cursor: pointer; margin-top: 10px; }
  .payer { background: <?php echo esc_attr($accent); ?>; color: <?php echo $helloasso ? 'white' : '#111'; ?>; }
  .autre { background: #eceef5; color: #333; }
  .note { font-size: 13px; color: #777; margin-top: 16px; }
</style></head><body>
<form method="post" class="carte">
  <div class="bandeau"><?php echo esc_html($paiement['fournisseur']); ?> · simulé par le bac à sable</div>
  <div><?php echo esc_html((string) $paiement['objet']); ?></div>
  <div class="montant"><?php echo esc_html(number_format_i18n($paiement['montant'] / 100, 2)); ?> €</div>
  <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
  <input type="hidden" name="ref" value="<?php echo esc_attr($ref); ?>">
  <button class="payer" name="choix" value="payer"><?php echo $helloasso ? 'Payer par carte' : 'Payer avec PayPal'; ?></button>
  <?php if ($helloasso) : ?><button class="autre" name="choix" value="fermer">Payer puis fermer l'onglet (sans revenir au site)</button><?php endif; ?>
  <button class="autre" name="choix" value="annuler">Annuler et revenir au site</button>
  <p class="note">Aucun argent ne circule : cette page remplace <?php echo esc_html($paiement['fournisseur']); ?> pour les tests.</p>
</form>
</body></html>
    <?php
}

function bac_reinitialiser(): void
{
    foreach (get_posts(['post_type' => 'nyassobi_adhesion', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $id) {
        wp_delete_post((int) $id, true);
    }
    update_option(BAC_MESSAGES, [], false);
    update_option(BAC_MAILS, [], false);
    update_option(BAC_PAIEMENTS, [], false);
    update_option(BAC_ROLES, [], false);
    delete_option('nyassobi_renewal_reminders_sent');
    set_transient(BAC_FLASH, 'Bac à sable remis à zéro.', 60);
}

add_action('template_redirect', static function (): void {
    $chemin = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ('bac-a-sable/paiement' === $chemin) {
        bac_page_paiement();
        exit;
    }
    if ('bac-a-sable' !== $chemin) {
        return;
    }

    if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
        check_admin_referer('bac_a_sable');
        if (isset($_POST['reinitialiser'])) {
            bac_reinitialiser();
        } elseif (isset($_POST['fin_de_saison'])) {
            Nyassobi_Membership_Payment::instance()->send_renewal_reminders(true);
            set_transient(BAC_FLASH, 'Date du rappel simulée : annonce Discord postée et bureau prévenu par e-mail.', 60);
        } elseif (isset($_POST['adresses'])) {
            $n = Nyassobi_Membership_Payment::instance()->send_renewal_to(wp_unslash((string) $_POST['adresses']));
            set_transient(BAC_FLASH, sprintf('Rappel envoyé à %d adresse(s), rien n\'a été enregistré.', $n), 60);
        } elseif (isset($_POST['avancer'])) {
            bac_avancer((int) $_POST['demande'], (int) $_POST['avancer']);
        } else {
            $membre = sanitize_key((string) ($_POST['membre'] ?? ''));
            if (isset(BAC_MEMBRES[$membre])) {
                bac_voter(sanitize_text_field((string) ($_POST['message'] ?? '')), sanitize_text_field((string) ($_POST['bouton'] ?? '')), $membre);
            }
            // Le choix du votant est gardé d'un clic à l'autre.
            setcookie('bac_membre', $membre, time() + DAY_IN_SECONDS, '/');
            $_COOKIE['bac_membre'] = $membre;
        }
        wp_safe_redirect(home_url('/bac-a-sable/'));
        exit;
    }

    status_header(200);
    nocache_headers();
    bac_afficher();
    exit;
});

function bac_markdown(string $texte): string
{
    $html = esc_html(preg_replace('/\\\\(.)/u', '$1', $texte) ?? '');

    return nl2br((string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html));
}

function bac_afficher(): void
{
    $messages = array_reverse(get_option(BAC_MESSAGES, []), true);
    $mails = get_option(BAC_MAILS, []);
    $roles = get_option(BAC_ROLES, []);
    $en_attente = get_posts(['post_type' => 'nyassobi_adhesion', 'post_status' => 'any', 'numberposts' => 20, 'meta_query' => [['key' => '_nyassobi_status', 'value' => ['accepted', 'paid'], 'compare' => 'IN']]]);
    $flash = get_transient(BAC_FLASH);
    delete_transient(BAC_FLASH);
    $membre = sanitize_key((string) ($_COOKIE['bac_membre'] ?? 'ca-1'));
    $hote = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $site = 'http://' . $hote . ':8505/adhesion';
    $nonce = wp_create_nonce('bac_a_sable');
    $couleurs = [3 => '#248046', 4 => '#da373c', 2 => '#4e5058'];
    ?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bac à sable · Adhésions Nyassobi</title>
<meta http-equiv="refresh" content="20">
<style>
  :root { color-scheme: light dark; --fond:#fff8f2; --encre:#34190f; --doux:#87685c; --orange:#e8622f; --discord:#313338; --discord-2:#2b2d31; }
  * { box-sizing: border-box; }
  body { margin:0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--fond); color: var(--encre); }
  header { padding: 22px 24px 8px; max-width: 1200px; margin: 0 auto; }
  h1 { margin: 0; font-size: 24px; }
  .avert { margin-top: 6px; color: var(--doux); }
  .liens a { color: var(--orange); font-weight: 600; margin-right: 18px; }
  .flash { max-width: 1200px; margin: 12px auto 0; padding: 0 24px; }
  .flash p { margin: 0; background: #e7f5f3; color: #0b766f; padding: 10px 14px; border-radius: 12px; }
  main { display: grid; gap: 24px; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); max-width: 1200px; margin: 0 auto; padding: 16px 24px 40px; }
  @media (max-width: 860px) { main { grid-template-columns: 1fr; } }
  h2 { font-size: 17px; margin: 0 0 10px; }
  .salon { background: var(--discord); color: #dbdee1; border-radius: 16px; padding: 14px; min-height: 200px; }
  .salon .nom { color: #949ba4; font-weight: 600; margin-bottom: 10px; }
  .msg { display: flex; gap: 12px; padding: 10px 6px; border-top: 1px solid #3f4147; }
  .msg:first-of-type { border-top: 0; }
  .avatar { width: 40px; height: 40px; border-radius: 50%; background: var(--orange); flex: none; display: grid; place-items: center; color: white; font-weight: 700; }
  .auteur { font-weight: 600; color: #f2f3f5; } .auteur small { color: #949ba4; font-weight: 400; margin-left: 6px; }
  .embed { margin-top: 6px; background: var(--discord-2); border-left: 4px solid; border-radius: 4px; padding: 10px 14px; max-width: 520px; }
  .embed b.titre { color: #f2f3f5; display: block; margin-bottom: 4px; }
  .embed .pied { color: #949ba4; font-size: 12px; margin-top: 8px; }
  .boutons { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
  .boutons button { border: 0; color: white; font-weight: 600; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; }
  .votant { background: #ffeee3; border-radius: 14px; padding: 12px 14px; margin-bottom: 12px; }
  .votant label { font-weight: 600; margin-right: 8px; }
  select { font: inherit; padding: 6px 8px; border-radius: 8px; }
  .boite { display: grid; gap: 10px; }
  .mail { background: white; border-radius: 14px; padding: 12px 14px; box-shadow: 0 1px 0 rgb(74 38 26 / .06), 0 8px 24px -16px rgb(170 72 30 / .4); }
  .mail .entete { color: var(--doux); font-size: 13px; }
  .mail b { display: block; margin: 2px 0 6px; }
  .mail pre { white-space: pre-wrap; font: inherit; margin: 0; }
  .vide { color: var(--doux); font-style: italic; }
  .raz { margin-top: 16px; }
  .raz button { background: none; border: 2px solid var(--orange); color: var(--orange); border-radius: 999px; padding: 8px 16px; font-weight: 600; cursor: pointer; }
  @media (prefers-color-scheme: dark) { :root { --fond:#1a1210; --encre:#fdf0e8; --doux:#ab9186; } .mail { background:#231916; } .votant { background:#221612; } }
</style>
</head>
<body>
<header>
  <h1>Bac à sable des adhésions</h1>
  <p class="avert">Rien ne sort d'ici : Discord et les e-mails sont simulés. N'entre pas de vraies informations, ce sont des données de test.</p>
  <p class="liens"><a href="<?php echo esc_url($site); ?>" target="_blank" rel="noopener">Remplir le formulaire du site</a><a href="<?php echo esc_url(admin_url('edit.php?post_type=nyassobi_adhesion')); ?>" target="_blank" rel="noopener">Vue du bureau (WordPress)</a></p>
</header>
<?php if ($flash) : ?><div class="flash"><p><?php echo esc_html((string) $flash); ?></p></div><?php endif; ?>
<main>
  <section>
    <h2>Discord : salon privé du CA</h2>
    <form method="post" class="votant" id="votant">
      <label for="membre">Tu cliques en tant que</label>
      <select id="membre" name="membre" form="votant" onchange="var v = this.value; document.cookie = 'bac_membre=' + v + '; path=/; max-age=86400'; document.querySelectorAll('input[name=membre]').forEach(function (i) { i.value = v; })">
        <?php foreach (BAC_MEMBRES as $cle => $nom) : ?>
          <option value="<?php echo esc_attr($cle); ?>" <?php selected($membre, $cle); ?>><?php echo esc_html($nom); ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <div class="salon">
      <div class="nom"># ca-adhesions</div>
      <?php if (! $messages) : ?><p class="vide">Aucune demande pour l'instant. Remplis le formulaire du site pour en voir arriver une.</p><?php endif; ?>
      <?php foreach ($messages as $id => $message) :
          $embed = $message['body']['embeds'][0] ?? [];
          $couleur = sprintf('#%06x', (int) ($embed['color'] ?? 0));
          ?>
        <div class="msg">
          <div class="avatar">N</div>
          <div>
            <div class="auteur">Nyassobi Adhésions <small><?php echo esc_html((string) $message['date']); ?></small></div>
            <?php if (! empty($message['body']['content'])) : ?><div style="margin-top:6px"><?php echo esc_html((string) $message['body']['content']); ?></div><?php endif; ?>
            <?php if ($embed) : ?>
            <div class="embed" style="border-color: <?php echo esc_attr($couleur); ?>">
              <b class="titre"><?php echo esc_html((string) ($embed['title'] ?? '')); ?></b>
              <div><?php echo bac_markdown((string) ($embed['description'] ?? '')); // phpcs:ignore ?></div>
              <?php if (! empty($embed['footer']['text'])) : ?><div class="pied"><?php echo esc_html((string) $embed['footer']['text']); ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php foreach ((array) ($message['body']['components'] ?? []) as $rangee) : ?>
              <div class="boutons">
                <?php foreach ((array) ($rangee['components'] ?? []) as $bouton) : ?>
                  <form method="post">
                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
                    <input type="hidden" name="message" value="<?php echo esc_attr((string) $id); ?>">
                    <input type="hidden" name="bouton" value="<?php echo esc_attr((string) $bouton['custom_id']); ?>">
                    <input type="hidden" name="membre" value="<?php echo esc_attr($membre); ?>">
                    <button type="submit" style="background: <?php echo esc_attr($couleurs[(int) $bouton['style']] ?? '#4e5058'); ?>"><?php echo esc_html((string) $bouton['label']); ?></button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <h2 style="margin-top:22px">Demandes acceptées (avancer le temps)</h2>
    <div class="votant">
      <?php if (! $en_attente) : ?><p class="vide" style="margin:0">Aucune demande acceptée pour l'instant.</p><?php endif; ?>
      <?php foreach ($en_attente as $demande) :
          $payee = 'paid' === get_post_meta($demande->ID, '_nyassobi_status', true);
          $depuis = (int) get_post_meta($demande->ID, $payee ? '_nyassobi_paid_at' : '_nyassobi_accepted_at', true);
          $jours = (int) floor((time() - $depuis) / DAY_IN_SECONDS);
          ?>
        <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:6px 0">
          <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
          <input type="hidden" name="demande" value="<?php echo esc_attr((string) $demande->ID); ?>">
          <span><b>n°<?php echo esc_html((string) $demande->ID); ?></b> (<?php echo esc_html((string) get_post_meta($demande->ID, '_nyassobi_pseudo', true)); ?>), <?php echo $payee ? 'payée' : 'acceptée'; ?> il y a <?php echo esc_html((string) $jours); ?> j</span>
          <?php if ($payee) : ?>
          <button type="submit" name="avancer" value="15" style="border:0;border-radius:8px;padding:6px 10px;cursor:pointer">+15 jours (rappel au bureau)</button>
          <button type="submit" name="avancer" value="30" style="border:0;border-radius:8px;padding:6px 10px;cursor:pointer">+30 jours (effacement)</button>
          <?php else : ?>
          <button type="submit" name="avancer" value="7" style="border:0;border-radius:8px;padding:6px 10px;cursor:pointer">+7 jours (relance)</button>
          <button type="submit" name="avancer" value="30" style="border:0;border-radius:8px;padding:6px 10px;cursor:pointer">+30 jours (expiration)</button>
          <?php endif; ?>
        </form>
      <?php endforeach; ?>
    </div>
    <h2 style="margin-top:22px">Fin de saison</h2>
    <form method="post" class="votant">
      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
      <button type="submit" name="fin_de_saison" value="1" style="border:0;border-radius:8px;padding:6px 10px;cursor:pointer">Simuler la date du rappel (15 août)</button>
    </form>
    <form method="post" class="votant">
      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
      <label for="adresses" style="display:block;margin-bottom:6px">Colonne « E-mail » copiée du registre Excel (comme sur la page « Rappel de fin de saison » de WordPress) :</label>
      <textarea id="adresses" name="adresses" rows="4" style="width:100%;font:inherit;border-radius:8px;padding:8px" placeholder="E-mail&#10;camille@exemple.test&#10;lou@exemple.test"></textarea>
      <button type="submit" style="border:0;border-radius:8px;padding:6px 10px;cursor:pointer;margin-top:6px">Envoyer le rappel</button>
    </form>
    <h2 style="margin-top:22px">Rôles « Adhérent » donnés sur Discord</h2>
    <div class="votant">
      <?php if (! $roles) : ?><p class="vide" style="margin:0">Aucun pour l'instant. Astuce : un pseudo Discord contenant « absent » simule quelqu'un qui n'est pas sur le serveur.</p><?php endif; ?>
      <?php foreach ($roles as $role) : ?>
        <p style="margin:4px 0">Membre Discord n°<?php echo esc_html((string) $role['membre']); ?> · <?php echo esc_html((string) $role['date']); ?></p>
      <?php endforeach; ?>
    </div>
    <form method="post" class="raz" onsubmit="return confirm('Effacer toutes les demandes, messages et e-mails de test ?');">
      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
      <button type="submit" name="reinitialiser" value="1">Tout remettre à zéro</button>
    </form>
  </section>
  <section>
    <h2>E-mails qui seraient partis</h2>
    <div class="boite">
      <?php if (! $mails) : ?><p class="vide">Aucun e-mail pour l'instant.</p><?php endif; ?>
      <?php foreach ($mails as $mail) : ?>
        <article class="mail">
          <div class="entete">De <?php echo esc_html($mail['de'] ?? ''); ?> · à <?php echo esc_html($mail['to']); ?> · <?php echo esc_html($mail['date']); ?></div>
          <b><?php echo esc_html($mail['subject']); ?></b>
          <pre><?php echo esc_html($mail['message']); ?></pre>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
</main>
</body>
</html>
    <?php
}
