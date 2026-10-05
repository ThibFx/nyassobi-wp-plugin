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

// Sur le bac à sable, on doit pouvoir envoyer autant de demandes qu'on veut.
add_filter('nyassobi_membership_submissions_per_hour', static fn (): int => 1000);

/* Discord simulé : les messages postés ou modifiés par le plugin sont gardés ici. */
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (0 !== strpos((string) $url, 'https://discord.com/api/')) {
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
    array_unshift($mails, ['to' => is_array($atts['to']) ? implode(', ', $atts['to']) : (string) $atts['to'], 'subject' => (string) $atts['subject'], 'message' => (string) $atts['message'], 'date' => current_time('mysql')]);
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

function bac_reinitialiser(): void
{
    foreach (get_posts(['post_type' => 'nyassobi_adhesion', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $id) {
        wp_delete_post((int) $id, true);
    }
    update_option(BAC_MESSAGES, [], false);
    update_option(BAC_MAILS, [], false);
    set_transient(BAC_FLASH, 'Bac à sable remis à zéro.', 60);
}

add_action('template_redirect', static function (): void {
    $chemin = trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ('bac-a-sable' !== $chemin) {
        return;
    }

    if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
        check_admin_referer('bac_a_sable');
        if (isset($_POST['reinitialiser'])) {
            bac_reinitialiser();
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
            <div class="embed" style="border-color: <?php echo esc_attr($couleur); ?>">
              <b class="titre"><?php echo esc_html((string) ($embed['title'] ?? '')); ?></b>
              <div><?php echo bac_markdown((string) ($embed['description'] ?? '')); // phpcs:ignore ?></div>
              <?php if (! empty($embed['footer']['text'])) : ?><div class="pied"><?php echo esc_html((string) $embed['footer']['text']); ?></div><?php endif; ?>
            </div>
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
          <div class="entete">À <?php echo esc_html($mail['to']); ?> · <?php echo esc_html($mail['date']); ?></div>
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
