<?php
/**
 * Plugin Name: Pré-production des adhésions
 * Description: Envoie les vrais e-mails par un compte SMTP, mais seulement vers des adresses autorisées.
 *
 * À ne jamais installer sur le vrai WordPress.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const PREPROD_JOURNAL = 'preprod_mails_bloques';

/*
 * Le Funnel public ne laisse passer que ces deux chemins, mais WordPress lit
 * aussi ?rest_route= (ou un champ de formulaire du même nom) pour choisir une
 * autre route : sans ce filtre, toute l'API REST de la pré-production était
 * joignable depuis Internet par ces deux adresses. Discord et HelloAsso n'y
 * envoient que du JSON en POST, sans paramètre.
 */
(static function (): void {
    $chemin = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    foreach (['/wp-json/nyassobi/v1/discord', '/wp-json/nyassobi/v1/helloasso'] as $public) {
        if (0 !== strpos($chemin, $public)) {
            continue;
        }
        $exact = $chemin === $public && '' === (string) ($_SERVER['QUERY_STRING'] ?? '') && [] === $_POST && 'POST' === ($_SERVER['REQUEST_METHOD'] ?? '');
        if (! $exact) {
            http_response_code(404);
            exit;
        }
    }
})();

// Assez de demandes par heure pour enchaîner les essais.
add_filter('nyassobi_membership_submissions_per_hour', static fn (): int => 30);

// Les liens des e-mails renvoient vers la copie du site de pré-production.
add_filter('nyassobi_membership_site_url', static fn (): string => 'https://' . getenv('PREPROD_HOTE'));

/** Les e-mails partent par le compte SMTP renseigné dans .env sur la Pi. */
add_action('phpmailer_init', static function ($mailer): void {
    $user = (string) getenv('SMTP_UTILISATEUR');
    if ('' === $user) {
        return;
    }
    $mailer->isSMTP();
    $mailer->Host = (string) (getenv('SMTP_SERVEUR') ?: 'smtp.gmail.com');
    $mailer->Port = 587;
    $mailer->SMTPAuth = true;
    $mailer->SMTPSecure = 'tls';
    $mailer->Username = $user;
    $mailer->Password = (string) getenv('SMTP_MOT_DE_PASSE');
    // Gmail réécrit de toute façon l'expéditeur avec le compte connecté.
    $mailer->setFrom($user, 'Nyassobi (pré-prod)', false);
    $mailer->Subject = '[PRÉ-PROD] ' . $mailer->Subject;
});

/**
 * Garde-fou : un e-mail vers une adresse non autorisée (une vraie personne
 * tapée par erreur) n'est pas envoyé, seulement noté dans le journal.
 * DESTINATAIRES_AUTORISES accepte des motifs : ton.adresse+*@gmail.com
 */
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $patterns = array_filter(array_map('trim', explode(',', (string) getenv('DESTINATAIRES_AUTORISES'))));
    $to = is_array($atts['to']) ? $atts['to'] : array_map('trim', explode(',', (string) $atts['to']));
    foreach ($to as $address) {
        $address = strtolower((string) preg_replace('/.*<([^>]+)>.*/', '$1', (string) $address));
        $allowed = false;
        foreach ($patterns as $pattern) {
            if (fnmatch(strtolower($pattern), $address)) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            $journal = (array) get_option(PREPROD_JOURNAL, []);
            array_unshift($journal, ['date' => current_time('mysql'), 'a' => $address, 'objet' => (string) $atts['subject']]);
            update_option(PREPROD_JOURNAL, array_slice($journal, 0, 50), false);
            return true;
        }
    }

    return $null;
}, 10, 2);

/** Les e-mails bloqués sont signalés dans l'administration. */
add_action('admin_notices', static function (): void {
    $journal = (array) get_option(PREPROD_JOURNAL, []);
    if (! $journal || ! current_user_can('manage_options')) {
        return;
    }
    $lignes = '';
    foreach (array_slice($journal, 0, 5) as $entree) {
        $lignes .= sprintf('<li>%s · %s · %s</li>', esc_html($entree['date']), esc_html($entree['a']), esc_html($entree['objet']));
    }
    printf(
        '<div class="notice notice-warning"><p><strong>Pré-production :</strong> %d e-mail(s) bloqué(s), destinataire non autorisé (voir DESTINATAIRES_AUTORISES dans .env).</p><ul>%s</ul></div>',
        count($journal),
        $lignes // phpcs:ignore WordPress.Security.EscapeOutput -- échappé ci-dessus.
    );
});
