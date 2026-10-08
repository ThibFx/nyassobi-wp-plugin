<?php
/**
 * What happens after the board accepts a membership request: the fee.
 *
 * The acceptance email links to a personal page on the public site (never
 * straight to HelloAsso, whose payment links only last 15 minutes). From
 * there the person pays by card through HelloAsso, or with PayPal when its
 * keys are filled in. The payment is detected on return and through
 * HelloAsso's notifications, both checked against the payment API rather
 * than trusted. Then the request becomes "paid", the person gets their
 * welcome email and, optionally, the "Adhérent" role on Discord.
 *
 * Unpaid requests get a reminder, then expire and are deleted.
 *
 * @package NyassobiWPPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Nyassobi_Membership_Payment
{
    private const META_TOKEN = '_nyassobi_pay_token';
    private const META_ACCEPTED_AT = '_nyassobi_accepted_at';
    private const META_REMINDED = '_nyassobi_reminded';
    private const META_PAID_AT = '_nyassobi_paid_at';
    private const META_PAID_VIA = '_nyassobi_paid_via';
    private const META_FINALIZE_REMINDED = '_nyassobi_finalize_reminded';
    /** Season the fee was paid for, fixed at payment time (see season()). */
    private const META_SEASON = '_nyassobi_season';
    private const META_HELLOASSO_INTENTS = '_nyassobi_helloasso_intents';
    private const META_PAYPAL_ORDERS = '_nyassobi_paypal_orders';

    /** Seasons whose end-of-season announcement already went out. */
    private const RENEWALS_SENT_OPTION = 'nyassobi_renewal_reminders_sent';

    private const VIA_LABELS = ['helloasso' => 'carte bancaire (HelloAsso)', 'paypal' => 'PayPal', 'manuel' => 'saisie du bureau'];

    /** @var self|null */
    private static $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('nyassobi_membership_accepted', [$this, 'on_accepted']);
        add_action('nyassobi_membership_mark_paid', [$this, 'mark_paid'], 10, 2);
        add_action('nyassobi_membership_metabox_rows', [$this, 'metabox_rows']);
        add_action('graphql_register_types', [$this, 'register_graphql']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action(Nyassobi_Membership::PURGE_HOOK, [$this, 'daily']);
        add_action('admin_post_nyassobi_membership_export', [$this, 'export_register']);
        add_action('restrict_manage_posts', [$this, 'export_button']);
        add_filter('bulk_actions-edit-' . Nyassobi_Membership::POST_TYPE, [$this, 'bulk_actions']);
        add_filter('handle_bulk_actions-edit-' . Nyassobi_Membership::POST_TYPE, [$this, 'handle_bulk_finalize'], 10, 3);
        add_action('admin_notices', [$this, 'finalized_notice']);
        add_action('admin_menu', [$this, 'register_renewal_page']);
        add_action('admin_post_nyassobi_renewal_send', [$this, 'send_renewal_from_register']);
    }

    private function membership(): Nyassobi_Membership
    {
        return Nyassobi_Membership::instance();
    }

    /**
     * @return array<string,string>
     */
    private function settings(): array
    {
        return Nyassobi_Membership::get_settings();
    }

    private function helloasso(): Nyassobi_HelloAsso
    {
        return new Nyassobi_HelloAsso($this->settings());
    }

    private function paypal(): Nyassobi_PayPal
    {
        return new Nyassobi_PayPal($this->settings());
    }

    private function meta(int $post_id, string $key): string
    {
        return (string) get_post_meta($post_id, $key, true);
    }

    private function fee_euros(int $post_id): int
    {
        $settings = $this->settings();

        return (int) ('1' === $this->meta($post_id, Nyassobi_Membership::META_REDUCED_RATE) ? $settings['fee_reduced'] : $settings['fee_normal']);
    }

    private function page_url(string $token, string $retour = ''): string
    {
        $url = $this->settings()['site_url'] . '/cotisation/' . $token;

        return '' !== $retour ? add_query_arg('retour', $retour, $url) : $url;
    }

    /**
     * Membership year, September to August, as written on the receipt.
     * Given a request, the season it was paid for once paid.
     */
    private function season(int $post_id = 0): string
    {
        $paid_for = $post_id ? $this->meta($post_id, self::META_SEASON) : '';
        if ('' !== $paid_for) {
            return $paid_for;
        }
        $now = new \DateTimeImmutable('now', wp_timezone());
        $start = (int) $now->format('n') >= 9 ? (int) $now->format('Y') : (int) $now->format('Y') - 1;

        return $start . '-' . ($start + 1);
    }

    private function find_by_token(string $token): int
    {
        if (! preg_match('/^[a-f0-9]{40}$/', $token)) {
            return 0;
        }
        $ids = get_posts(
            [
                'post_type' => Nyassobi_Membership::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => [['key' => self::META_TOKEN, 'value' => $token]],
            ]
        );

        return $ids ? (int) $ids[0] : 0;
    }

    /* ------------------------------------------------------------------
     * Acceptance and payment
     * ------------------------------------------------------------------ */

    public function on_accepted(int $post_id): void
    {
        $token = bin2hex(random_bytes(20));
        update_post_meta($post_id, self::META_TOKEN, $token);
        update_post_meta($post_id, self::META_ACCEPTED_AT, (string) time());

        $settings = $this->settings();
        $minor = '1' === $this->meta($post_id, Nyassobi_Membership::META_MINOR);
        $reduced = '1' === $this->meta($post_id, Nyassobi_Membership::META_REDUCED_RATE);
        $ways = $this->paypal()->is_configured() ? __('par carte bancaire ou avec PayPal', 'nyassobi-wp-plugin') : __('par carte bancaire', 'nyassobi-wp-plugin');

        $lines = [
            sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $this->meta($post_id, Nyassobi_Membership::META_PSEUDO)),
            '',
            __('Bonne nouvelle : le conseil d\'administration a accepté ta demande d\'adhésion à Nyassobi !', 'nyassobi-wp-plugin'),
            '',
            sprintf(
                /* translators: 1: membership year, 2: amount, 3: ", tarif réduit" or empty, 4: payment means */
                __('Pour la finaliser, il reste à régler ta cotisation %1$s (%2$d €%3$s), %4$s, sur ta page personnelle :', 'nyassobi-wp-plugin'),
                $this->season(),
                $this->fee_euros($post_id),
                $reduced ? __(', tarif réduit', 'nyassobi-wp-plugin') : '',
                $ways
            ),
            $this->page_url($token),
            '',
            sprintf(__('Ce lien est personnel. Sans paiement d\'ici %d jours, la demande expirera et tes informations seront effacées.', 'nyassobi-wp-plugin'), (int) $settings['expiry_days']),
        ];
        if ($minor) {
            $lines[] = '';
            $lines[] = __('Nous avons bien ton autorisation parentale : le bureau la vérifie au moment de finaliser ton adhésion.', 'nyassobi-wp-plugin');
        }
        array_push($lines, '', __('Bienvenue dans la bande,', 'nyassobi-wp-plugin'), __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'));
        $this->membership()->send_mail($this->meta($post_id, Nyassobi_Membership::META_EMAIL), __('Nyassobi : ta demande d\'adhésion est acceptée', 'nyassobi-wp-plugin'), $lines);
    }

    /**
     * Single entry point for a confirmed payment, whatever its origin. The
     * return page and HelloAsso's notification often arrive together: the
     * lock makes sure only one of them sends the emails.
     */
    public function mark_paid(int $post_id, string $via): void
    {
        $membership = $this->membership();
        if (! $membership->acquire_lock($post_id)) {
            return;
        }
        try {
            if (Nyassobi_Membership::STATUS_ACCEPTED !== $this->meta($post_id, Nyassobi_Membership::META_STATUS)) {
                return;
            }
            update_post_meta($post_id, Nyassobi_Membership::META_STATUS, Nyassobi_Membership::STATUS_PAID);
            update_post_meta($post_id, self::META_PAID_AT, (string) time());
            update_post_meta($post_id, self::META_PAID_VIA, $via);
            // Fixed now: an export made after 1 September must not move a
            // fee paid in August to the next season.
            update_post_meta($post_id, self::META_SEASON, $this->season());
        } finally {
            $membership->release_lock($post_id);
        }

        $role = $this->give_discord_role($post_id);
        $notes = [
            'given' => '🎉 Rôle Adhérent donné sur le serveur.',
            'absent' => '⚠️ Rôle Adhérent à donner à la main (pseudo Discord introuvable sur le serveur, voir la fiche).',
            'error' => '⚠️ Rôle Adhérent à donner à la main (Discord a refusé, voir la fiche).',
        ];
        if (isset($notes[$role])) {
            update_post_meta($post_id, Nyassobi_Membership::META_DISCORD_NOTE, $notes[$role]);
        }
        $membership->update_discord_message($post_id);

        $settings = $this->settings();
        $lines = [
            sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $this->meta($post_id, Nyassobi_Membership::META_PSEUDO)),
            '',
            sprintf(__('Nous avons bien reçu ta cotisation %s : tu fais maintenant partie de Nyassobi, bienvenue !', 'nyassobi-wp-plugin'), $this->season($post_id)),
        ];
        if ('given' === $role) {
            $lines[] = __('Ton rôle « Adhérent » t\'attend déjà sur notre serveur Discord.', 'nyassobi-wp-plugin');
        } elseif ('' !== ($settings['discord_invite_url'] ?? '')) {
            $lines[] = __('Rejoins-nous sur le serveur Discord de l\'association, le bureau t\'y donnera ton rôle « Adhérent » :', 'nyassobi-wp-plugin');
            $lines[] = $settings['discord_invite_url'];
        }
        array_push($lines, '', __('À très vite,', 'nyassobi-wp-plugin'), __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'));
        $membership->send_mail($this->meta($post_id, Nyassobi_Membership::META_EMAIL), __('Nyassobi : bienvenue, ta cotisation est réglée', 'nyassobi-wp-plugin'), $lines);

        if (is_email($settings['bureau_email'] ?? '')) {
            $membership->send_mail(
                $settings['bureau_email'],
                sprintf(__('Nyassobi : cotisation reçue, demande n°%d', 'nyassobi-wp-plugin'), $post_id),
                [
                    sprintf(__('La cotisation de la demande n°%1$d a été payée (%2$s).', 'nyassobi-wp-plugin'), $post_id, self::VIA_LABELS[$via] ?? $via),
                    sprintf(__('À ajouter au registre des membres : dans WordPress, Adhésions > « Exporter pour le registre (Excel) », puis cocher la demande et choisir l\'action « Finaliser ». Sans finalisation, ses données seront effacées automatiquement dans %d jours :', 'nyassobi-wp-plugin'), (int) $settings['paid_retention_days']),
                    admin_url('post.php?post=' . $post_id . '&action=edit'),
                ],
                false
            );
        }
    }

    /**
     * @return string given | absent | error | none
     */
    private function give_discord_role(int $post_id): string
    {
        $settings = $this->settings();
        $username = $this->meta($post_id, Nyassobi_Membership::META_DISCORD_USERNAME);
        $guild = $settings['discord_guild_id'] ?? '';
        $role = $settings['discord_member_role_id'] ?? '';
        if ('' === $username || '' === $guild || '' === $role) {
            return 'none';
        }

        $membership = $this->membership();
        $found = $membership->discord_request('GET', '/guilds/' . rawurlencode($guild) . '/members/search?limit=10&query=' . rawurlencode($username));
        if (null === $found) {
            return 'error';
        }
        foreach ($found as $member) {
            if (strtolower((string) ($member['user']['username'] ?? '')) === $username) {
                $given = $membership->discord_request('PUT', '/guilds/' . rawurlencode($guild) . '/members/' . rawurlencode((string) $member['user']['id']) . '/roles/' . rawurlencode($role));

                return null === $given ? 'error' : 'given';
            }
        }

        return 'absent';
    }

    /* ------------------------------------------------------------------
     * Reminders and expiry
     * ------------------------------------------------------------------ */

    public function daily(): void
    {
        $this->expire_unpaid();
        $this->expire_paid();
        $this->send_renewal_reminders();
    }

    /* ------------------------------------------------------------------
     * End-of-season renewal reminder
     * ------------------------------------------------------------------ */

    /**
     * Everyone's membership ends on 31 August. Addresses are not kept online:
     * they live in the president's register. On the reminder date, the bot
     * announces the new season to the "Adhérent" role on Discord, and the
     * bureau is asked to paste the register's email column on a page that
     * sends the reminder and stores nothing.
     */
    public function send_renewal_reminders(bool $force = false): bool
    {
        $settings = $this->settings();
        $season = $this->season();
        $end_year = (int) substr($season, 5, 4);
        [$day, $month] = array_map('intval', explode('/', $settings['renewal_reminder_date']));
        $due = (new \DateTimeImmutable('now', wp_timezone()))->format('Y-m-d') >= sprintf('%04d-%02d-%02d', $end_year, $month, $day);
        $sent = (array) get_option(self::RENEWALS_SENT_OPTION, []);
        if (! $force && (! $due || in_array($season, $sent, true))) {
            return false;
        }

        $next = $end_year . '-' . ($end_year + 1);
        $membership = $this->membership();
        $channel = $settings['renewal_channel_id'] ?? '';
        $role = $settings['discord_member_role_id'] ?? '';
        if ('' !== $channel && '' !== $role) {
            $membership->discord_request('POST', '/channels/' . rawurlencode($channel) . '/messages', [
                'content' => sprintf("<@&%s> Les adhésions %s sont ouvertes ! Vos adhésions %s se terminent le 31 août : pour continuer avec nous, refaites une demande sur %s/adhesion 🐾", $role, $next, $season, $settings['site_url']),
                'allowed_mentions' => ['roles' => [$role]],
            ]);
        }
        if (is_email($settings['bureau_email'] ?? '')) {
            $membership->send_mail($settings['bureau_email'], sprintf(__('Nyassobi : c\'est le moment du rappel de renouvellement %s', 'nyassobi-wp-plugin'), $season), [
                sprintf(__('Les adhésions %s se terminent le 31 août.', 'nyassobi-wp-plugin'), $season),
                __('Pour prévenir les adhérents, copiez la colonne « E-mail » du registre et collez-la ici : chacun reçoit un rappel individuel, et aucune adresse n\'est gardée en ligne.', 'nyassobi-wp-plugin'),
                admin_url('edit.php?post_type=' . Nyassobi_Membership::POST_TYPE . '&page=nyassobi-renouvellement'),
            ], false);
        }

        $sent[] = $season;
        update_option(self::RENEWALS_SENT_OPTION, array_slice(array_values(array_unique($sent)), -5), false);

        return true;
    }

    public function register_renewal_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . Nyassobi_Membership::POST_TYPE,
            __('Rappel de renouvellement', 'nyassobi-wp-plugin'),
            __('Rappel de fin de saison', 'nyassobi-wp-plugin'),
            Nyassobi_Membership::CAP_ADHESIONS,
            'nyassobi-renouvellement',
            [$this, 'render_renewal_page']
        );
    }

    public function render_renewal_page(): void
    {
        if (! current_user_can(Nyassobi_Membership::CAP_ADHESIONS)) {
            return;
        }
        $season = $this->season();
        $end_year = (int) substr($season, 5, 4);
        $sent = isset($_GET['nyassobi_envoyes']) ? (int) $_GET['nyassobi_envoyes'] : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Rappel de fin de saison', 'nyassobi-wp-plugin'); ?></h1>
            <?php if (null !== $sent) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(sprintf(_n('%d rappel envoyé. Aucune adresse n\'a été enregistrée.', '%d rappels envoyés. Aucune adresse n\'a été enregistrée.', $sent, 'nyassobi-wp-plugin'), $sent)); ?></p></div>
            <?php endif; ?>
            <p><?php echo esc_html(sprintf(__('Les adhésions %1$s se terminent le 31 août %2$d. Copiez la colonne « E-mail » du registre des membres (Excel) et collez-la ci-dessous : chaque adresse reçoit un rappel individuel l\'invitant à refaire une demande pour %3$s.', 'nyassobi-wp-plugin'), $season, $end_year, $end_year . '-' . ($end_year + 1))); ?></p>
            <p><?php esc_html_e('Les adresses ne sont pas enregistrées dans WordPress : elles servent à l\'envoi, puis sont oubliées.', 'nyassobi-wp-plugin'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="nyassobi_renewal_send">
                <?php wp_nonce_field('nyassobi_renewal_send'); ?>
                <textarea name="adresses" rows="12" class="large-text code" placeholder="prenom.nom@exemple.fr&#10;autre@exemple.fr"></textarea>
                <?php submit_button(__('Envoyer le rappel', 'nyassobi-wp-plugin'), 'primary', 'submit', true, ['onclick' => "return confirm('" . esc_js(__('Envoyer le rappel de renouvellement à ces adresses ?', 'nyassobi-wp-plugin')) . "');"]); ?>
            </form>
        </div>
        <?php
    }

    public function send_renewal_from_register(): void
    {
        if (! current_user_can(Nyassobi_Membership::CAP_ADHESIONS) || ! check_admin_referer('nyassobi_renewal_send')) {
            wp_die(esc_html__('Action non autorisée.', 'nyassobi-wp-plugin'));
        }
        $this->send_renewal_to((string) wp_unslash($_POST['adresses'] ?? ''), true);
    }

    /**
     * Sends the reminder to every address found in the pasted text, then
     * forgets them. Whatever Excel puts around the column (header, blank
     * lines, separators): only what looks like an address is kept, once.
     */
    public function send_renewal_to(string $pasted, bool $redirect = false): int
    {
        preg_match_all('/[^\s;,<>"\']+@[^\s;,<>"\']+\.[A-Za-z]{2,}/', $pasted, $found);
        $addresses = array_values(array_unique(array_filter(array_map(static fn ($a) => strtolower(sanitize_email($a)), $found[0]), 'is_email')));

        $settings = $this->settings();
        $season = $this->season();
        $end_year = (int) substr($season, 5, 4);
        $membership = $this->membership();
        foreach ($addresses as $address) {
            $membership->send_mail($address, sprintf(__('Nyassobi : ton adhésion %s se termine le 31 août', 'nyassobi-wp-plugin'), $season), [
                __('Bonjour,', 'nyassobi-wp-plugin'),
                '',
                sprintf(__('Ton adhésion à Nyassobi pour la saison %s se termine le 31 août.', 'nyassobi-wp-plugin'), $season),
                sprintf(__('Pour continuer l\'aventure en %s, refais une demande sur notre site : comme chaque adhésion, elle sera validée par le conseil d\'administration, puis tu recevras le lien pour régler ta cotisation.', 'nyassobi-wp-plugin'), $end_year . '-' . ($end_year + 1)),
                $settings['site_url'] . '/adhesion',
                '',
                __('Merci pour cette saison avec nous,', 'nyassobi-wp-plugin'),
                __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'),
            ]);
        }

        if ($redirect) {
            wp_safe_redirect(add_query_arg('nyassobi_envoyes', count($addresses), admin_url('edit.php?post_type=' . Nyassobi_Membership::POST_TYPE . '&page=nyassobi-renouvellement')));
            exit;
        }

        return count($addresses);
    }

    /**
     * A paid membership waits for the bureau to copy it into the offline
     * register and click "Finaliser". If that never happens, the data must
     * not stay online forever: reminder first, then erasure. Nothing changes
     * for the member, who already got their welcome email.
     */
    private function expire_paid(): void
    {
        $settings = $this->settings();
        $ids = get_posts(
            [
                'post_type' => Nyassobi_Membership::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 200,
                'meta_query' => [['key' => Nyassobi_Membership::META_STATUS, 'value' => Nyassobi_Membership::STATUS_PAID]],
            ]
        );
        $membership = $this->membership();
        $bureau = is_email($settings['bureau_email'] ?? '') ? $settings['bureau_email'] : '';

        foreach ($ids as $id) {
            $id = (int) $id;
            $days = (time() - (int) $this->meta($id, self::META_PAID_AT)) / DAY_IN_SECONDS;

            if ($days >= (int) $settings['paid_retention_days']) {
                if ('' !== $bureau) {
                    $membership->send_mail($bureau, sprintf(__('Nyassobi : demande n°%d effacée sans finalisation', 'nyassobi-wp-plugin'), $id), [
                        sprintf(__('La demande n°%1$d, payée il y a %2$d jours, n\'avait pas été finalisée : ses données viennent d\'être effacées de WordPress, comme le prévoit notre politique de confidentialité.', 'nyassobi-wp-plugin'), $id, (int) $days),
                        __('Si la personne n\'a pas encore été inscrite au registre des membres, il faudra lui redemander ses informations (elle est bien membre : sa cotisation est réglée).', 'nyassobi-wp-plugin'),
                    ], false);
                }
                wp_delete_post($id, true);
            } elseif ($days >= (int) $settings['finalize_reminder_days'] && '' === $this->meta($id, self::META_FINALIZE_REMINDED) && '' !== $bureau) {
                $membership->send_mail($bureau, sprintf(__('Nyassobi : rappel, demande n°%d à finaliser', 'nyassobi-wp-plugin'), $id), [
                    sprintf(__('La demande n°%1$d est payée depuis %2$d jours mais n\'est pas encore finalisée.', 'nyassobi-wp-plugin'), $id, (int) $days),
                    sprintf(__('Pensez à l\'inscrire au registre des membres puis à cliquer sur « Finaliser » : sans cela, ses données seront effacées automatiquement dans %d jours.', 'nyassobi-wp-plugin'), max(1, (int) ceil((int) $settings['paid_retention_days'] - $days))),
                    admin_url('post.php?post=' . $id . '&action=edit'),
                ], false);
                update_post_meta($id, self::META_FINALIZE_REMINDED, (string) time());
            }
        }
    }

    private function expire_unpaid(): void
    {
        $settings = $this->settings();
        $ids = get_posts(
            [
                'post_type' => Nyassobi_Membership::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 200,
                'meta_query' => [['key' => Nyassobi_Membership::META_STATUS, 'value' => Nyassobi_Membership::STATUS_ACCEPTED]],
            ]
        );
        $membership = $this->membership();

        foreach ($ids as $id) {
            $id = (int) $id;
            $days = (time() - (int) $this->meta($id, self::META_ACCEPTED_AT)) / DAY_IN_SECONDS;
            $email = $this->meta($id, Nyassobi_Membership::META_EMAIL);
            $pseudo = $this->meta($id, Nyassobi_Membership::META_PSEUDO);

            if ($days >= (int) $settings['expiry_days']) {
                $membership->send_mail($email, __('Nyassobi : ta demande d\'adhésion a expiré', 'nyassobi-wp-plugin'), [
                    sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $pseudo),
                    '',
                    __('Ta cotisation n\'a pas été réglée à temps : ta demande d\'adhésion a expiré et tes informations ont été effacées.', 'nyassobi-wp-plugin'),
                    __('Tu peux refaire une demande quand tu veux depuis notre site. Si c\'est une erreur, réponds simplement à cet e-mail.', 'nyassobi-wp-plugin'),
                    '',
                    __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'),
                ]);
                update_post_meta($id, Nyassobi_Membership::META_STATUS, Nyassobi_Membership::STATUS_LAPSED);
                $membership->update_discord_message($id);
                wp_delete_post($id, true);
            } elseif ($days >= (int) $settings['reminder_days'] && '' === $this->meta($id, self::META_REMINDED)) {
                $membership->send_mail($email, __('Nyassobi : ta cotisation t\'attend', 'nyassobi-wp-plugin'), [
                    sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $pseudo),
                    '',
                    __('Petit rappel : ta demande d\'adhésion a été acceptée, il ne manque plus que ta cotisation pour la finaliser.', 'nyassobi-wp-plugin'),
                    $this->page_url($this->meta($id, self::META_TOKEN)),
                    '',
                    sprintf(__('Sans paiement d\'ici %d jours, la demande expirera et tes informations seront effacées.', 'nyassobi-wp-plugin'), max(1, (int) ceil((int) $settings['expiry_days'] - $days))),
                    '',
                    __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'),
                ]);
                update_post_meta($id, self::META_REMINDED, (string) time());
            }
        }
    }

    /* ------------------------------------------------------------------
     * GraphQL: fees and the personal payment page
     * ------------------------------------------------------------------ */

    public function register_graphql(): void
    {
        if (! function_exists('register_graphql_object_type')) {
            return;
        }

        register_graphql_object_type('NyassobiMembershipFees', [
            'fields' => [
                'normal' => ['type' => ['non_null' => 'Int'], 'description' => __('Cotisation annuelle, en euros.', 'nyassobi-wp-plugin')],
                'reduced' => ['type' => ['non_null' => 'Int'], 'description' => __('Tarif réduit, en euros.', 'nyassobi-wp-plugin')],
            ],
        ]);
        register_graphql_field('RootQuery', 'nyassobiMembershipFees', [
            'type' => ['non_null' => 'NyassobiMembershipFees'],
            'resolve' => function (): array {
                $settings = $this->settings();
                return ['normal' => (int) $settings['fee_normal'], 'reduced' => (int) $settings['fee_reduced']];
            },
        ]);

        register_graphql_object_type('NyassobiCotisation', [
            'description' => __('Page de paiement personnelle d\'une demande acceptée. Aucune donnée personnelle.', 'nyassobi-wp-plugin'),
            'fields' => [
                'status' => ['type' => ['non_null' => 'String'], 'description' => 'a_payer | payee | introuvable'],
                'amount' => ['type' => 'Int'],
                'reducedRate' => ['type' => 'Boolean'],
                'season' => ['type' => 'String'],
                'cardUrl' => ['type' => 'String'],
                'cardAutomatic' => ['type' => 'Boolean', 'description' => __('Faux si la carte passe par le lien de secours (paiement non détecté).', 'nyassobi-wp-plugin')],
                'paypalUrl' => ['type' => 'String'],
            ],
        ]);
        register_graphql_field('RootQuery', 'nyassobiCotisation', [
            'type' => ['non_null' => 'NyassobiCotisation'],
            'args' => ['token' => ['type' => ['non_null' => 'String']]],
            'resolve' => function ($root, array $args): array {
                return $this->cotisation((string) ($args['token'] ?? ''));
            },
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function cotisation(string $token): array
    {
        $post_id = $this->find_by_token($token);
        $status = $post_id ? $this->meta($post_id, Nyassobi_Membership::META_STATUS) : '';
        if (! in_array($status, [Nyassobi_Membership::STATUS_ACCEPTED, Nyassobi_Membership::STATUS_PAID], true)) {
            return ['status' => 'introuvable'];
        }
        $settings = $this->settings();
        $helloasso = $this->helloasso()->is_configured();
        $pay = fn (string $way): string => add_query_arg(['jeton' => $token, 'moyen' => $way], rest_url(Nyassobi_Membership::REST_NAMESPACE . '/payer'));

        return [
            'status' => Nyassobi_Membership::STATUS_PAID === $status ? 'payee' : 'a_payer',
            'amount' => $this->fee_euros($post_id),
            'reducedRate' => '1' === $this->meta($post_id, Nyassobi_Membership::META_REDUCED_RATE),
            'season' => $this->season($post_id),
            'cardUrl' => $helloasso ? $pay('helloasso') : (($settings['payment_url'] ?? '') ?: null),
            'cardAutomatic' => $helloasso,
            'paypalUrl' => $this->paypal()->is_configured() ? $pay('paypal') : null,
        ];
    }

    /* ------------------------------------------------------------------
     * REST: going to the payment and coming back
     * ------------------------------------------------------------------ */

    public function register_rest_routes(): void
    {
        $ns = Nyassobi_Membership::REST_NAMESPACE;
        // Public on purpose: the 40-character token is the key, and these
        // routes only ever redirect.
        register_rest_route($ns, '/payer', ['methods' => 'GET', 'callback' => [$this, 'route_pay'], 'permission_callback' => '__return_true']);
        register_rest_route($ns, '/retour/helloasso', ['methods' => 'GET', 'callback' => [$this, 'route_helloasso_return'], 'permission_callback' => '__return_true']);
        register_rest_route($ns, '/retour/paypal', ['methods' => 'GET', 'callback' => [$this, 'route_paypal_return'], 'permission_callback' => '__return_true']);
        register_rest_route($ns, '/annule', ['methods' => 'GET', 'callback' => [$this, 'route_cancel'], 'permission_callback' => '__return_true']);
        // HelloAsso notifications are not signed for associations: they are
        // only a signal, the payment is always checked with the API.
        register_rest_route($ns, '/helloasso', ['methods' => 'POST', 'callback' => [$this, 'route_helloasso_notification'], 'permission_callback' => '__return_true']);
    }

    /** @return never */
    private function go(string $url)
    {
        wp_redirect($url, 302, 'Nyassobi');
        exit;
    }

    public function route_pay(\WP_REST_Request $request): void
    {
        $token = (string) $request->get_param('jeton');
        $post_id = $this->find_by_token($token);
        $status = $post_id ? $this->meta($post_id, Nyassobi_Membership::META_STATUS) : '';
        if (Nyassobi_Membership::STATUS_PAID === $status) {
            $this->go($this->page_url($token, 'deja'));
        }
        if (Nyassobi_Membership::STATUS_ACCEPTED !== $status) {
            $this->go($this->page_url($token));
        }

        $item = sprintf('Cotisation Nyassobi %s', $this->season());
        $cents = $this->fee_euros($post_id) * 100;
        $back = add_query_arg('jeton', $token, rest_url(Nyassobi_Membership::REST_NAMESPACE . '/annule'));

        if ('paypal' === $request->get_param('moyen') && $this->paypal()->is_configured()) {
            $order = $this->paypal()->create_order([
                'amount_cents' => $cents,
                'item' => $item,
                'reference' => 'adhesion-' . $post_id,
                'return_url' => add_query_arg('jeton', $token, rest_url(Nyassobi_Membership::REST_NAMESPACE . '/retour/paypal')),
                'cancel_url' => $back,
            ]);
            if (null === $order) {
                $this->go($this->page_url($token, 'erreur'));
            }
            $this->remember($post_id, self::META_PAYPAL_ORDERS, $order['id']);
            $this->go($order['url']);
        }

        if ($this->helloasso()->is_configured()) {
            $checkout = $this->helloasso()->create_checkout([
                'amount_cents' => $cents,
                'item' => $item,
                'return_url' => add_query_arg('jeton', $token, rest_url(Nyassobi_Membership::REST_NAMESPACE . '/retour/helloasso')),
                'back_url' => $back,
                'error_url' => $this->page_url($token, 'erreur'),
                'metadata' => ['adhesion' => $post_id],
            ]);
            if (null === $checkout) {
                $this->go($this->page_url($token, 'erreur'));
            }
            $this->remember($post_id, self::META_HELLOASSO_INTENTS, $checkout['id']);
            $this->go($checkout['url']);
        }

        $fallback = $this->settings()['payment_url'] ?? '';
        $this->go('' !== $fallback ? $fallback : $this->page_url($token, 'erreur'));
    }

    /** Keeps the last few payment attempts of a request, to check them later. */
    private function remember(int $post_id, string $key, string $id): void
    {
        // A missing meta reads as '', which (array) would keep as a first, empty id.
        $ids = array_filter((array) get_post_meta($post_id, $key, true), 'strlen');
        $ids[] = $id;
        update_post_meta($post_id, $key, array_slice(array_values(array_unique($ids)), -10));
    }

    private function helloasso_check(int $post_id): bool
    {
        $helloasso = $this->helloasso();
        foreach (array_reverse((array) get_post_meta($post_id, self::META_HELLOASSO_INTENTS, true)) as $intent) {
            if ($helloasso->is_paid((string) $intent)) {
                $this->mark_paid($post_id, 'helloasso');
                return true;
            }
        }

        return false;
    }

    public function route_helloasso_return(\WP_REST_Request $request): void
    {
        $token = (string) $request->get_param('jeton');
        $post_id = $this->find_by_token($token);
        if (! $post_id) {
            $this->go($this->page_url($token));
        }
        $paid = Nyassobi_Membership::STATUS_PAID === $this->meta($post_id, Nyassobi_Membership::META_STATUS) || $this->helloasso_check($post_id);
        // The bank may take a moment: "en cours" tells the page to check again.
        $this->go($this->page_url($token, $paid ? 'ok' : ('succeeded' === $request->get_param('code') ? 'en-cours' : 'annule')));
    }

    public function route_paypal_return(\WP_REST_Request $request): void
    {
        $token = (string) $request->get_param('jeton');
        $order = (string) $request->get_param('token');
        $post_id = $this->find_by_token($token);
        if (! $post_id || ! in_array($order, (array) get_post_meta($post_id, self::META_PAYPAL_ORDERS, true), true)) {
            $this->go($this->page_url($token, 'erreur'));
        }
        if ($this->paypal()->capture($order, 'adhesion-' . $post_id)) {
            $this->mark_paid($post_id, 'paypal');
            $this->go($this->page_url($token, 'ok'));
        }
        $this->go($this->page_url($token, 'erreur'));
    }

    public function route_cancel(\WP_REST_Request $request): void
    {
        $this->go($this->page_url((string) $request->get_param('jeton'), 'annule'));
    }

    public function route_helloasso_notification(\WP_REST_Request $request): \WP_REST_Response
    {
        $payload = json_decode($request->get_body(), true);
        $post_id = (int) ($payload['metadata']['adhesion'] ?? 0);
        $post = $post_id ? get_post($post_id) : null;
        if ($post instanceof \WP_Post && Nyassobi_Membership::POST_TYPE === $post->post_type && Nyassobi_Membership::STATUS_ACCEPTED === $this->meta($post_id, Nyassobi_Membership::META_STATUS)) {
            $this->helloasso_check($post_id);
        }

        // Always 200: HelloAsso would otherwise keep retrying a notification we ignore.
        return new \WP_REST_Response(['ok' => true], 200);
    }

    /* ------------------------------------------------------------------
     * Admin
     * ------------------------------------------------------------------ */

    /** @return int[] Paid requests, oldest payment first. */
    private function paid_ids(): array
    {
        $ids = get_posts(
            [
                'post_type' => Nyassobi_Membership::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'meta_query' => [['key' => Nyassobi_Membership::META_STATUS, 'value' => Nyassobi_Membership::STATUS_PAID]],
                'meta_key' => self::META_PAID_AT,
                'orderby' => 'meta_value_num',
                'order' => 'ASC',
            ]
        );

        return array_map('intval', $ids);
    }

    public function export_button(string $post_type): void
    {
        if (Nyassobi_Membership::POST_TYPE !== $post_type || ! current_user_can(Nyassobi_Membership::CAP_ADHESIONS)) {
            return;
        }
        $count = count($this->paid_ids());
        // The export holds decrypted identities: it is behind the bureau
        // passphrase. Rendered after the list filters' form, not inside it.
        add_action('admin_footer', static function () use ($count): void {
            printf(
                '<div id="nyassobi-export" style="margin:12px 0">%s</div><script>(function(){var b=document.getElementById("nyassobi-export"),t=document.querySelector(".wp-header-end");if(b&&t){t.parentNode.insertBefore(b,t.nextSibling);}})();</script>',
                Nyassobi_Membership::passphrase_form(
                    'nyassobi_membership_export',
                    'nyassobi_membership_export',
                    sprintf(_n('Exporter %d adhésion payée pour le registre (Excel)', 'Exporter %d adhésions payées pour le registre (Excel)', $count, 'nyassobi-wp-plugin'), $count)
                ) // phpcs:ignore
            );
        });
    }

    /**
     * The members register is kept offline (an Excel file on the
     * president's computer). This file opens straight in Excel: UTF-8 with
     * BOM, semicolons, French dates. Identity and email (for the
     * end-of-season reminder); never the pseudonym.
     */
    public function export_register(): void
    {
        if (! current_user_can(Nyassobi_Membership::CAP_ADHESIONS) || ! check_admin_referer('nyassobi_membership_export')) {
            wp_die(esc_html__('Action non autorisée.', 'nyassobi-wp-plugin'));
        }
        $pair = Nyassobi_Vault::unlock((string) wp_unslash($_POST['passphrase'] ?? ''));
        if (null === $pair) {
            wp_die(esc_html__('Mot de passe du bureau incorrect.', 'nyassobi-wp-plugin'), '', ['back_link' => true]);
        }
        $open = fn (int $id, string $key): string => (string) (Nyassobi_Vault::open($this->meta($id, $key), $pair) ?? __('(illisible)', 'nyassobi-wp-plugin'));
        $format_date = static function (string $iso): string {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
            return $date ? $date->format('d/m/Y') : $iso;
        };
        $rows = [['Nom', 'Prénom', 'Date de naissance', 'E-mail', 'Mineur', 'Date d\'adhésion', 'Saison', 'Cotisation (€)', 'Tarif', 'Payée par', 'N° de demande']];
        foreach ($this->paid_ids() as $id) {
            $birth = $open($id, Nyassobi_Membership::META_BIRTH_DATE);
            $rows[] = [
                $open($id, Nyassobi_Membership::META_LAST_NAME),
                $open($id, Nyassobi_Membership::META_FIRST_NAME),
                $format_date($birth),
                // Kept in the register for the end-of-season renewal reminder.
                $this->meta($id, Nyassobi_Membership::META_EMAIL),
                '1' === $this->meta($id, Nyassobi_Membership::META_MINOR) ? 'oui' : 'non',
                wp_date('d/m/Y', (int) $this->meta($id, self::META_PAID_AT)),
                $this->season($id),
                (string) $this->fee_euros($id),
                '1' === $this->meta($id, Nyassobi_Membership::META_REDUCED_RATE) ? 'réduit' : 'normal',
                self::VIA_LABELS[$this->meta($id, self::META_PAID_VIA)] ?? '',
                (string) $id,
            ];
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="adhesions-a-inscrire-' . wp_date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            // A cell starting with = + - @ would be run as a formula by Excel.
            $row = array_map(static fn (string $cell): string => preg_match('/^[=+\-@]/', $cell) ? "'" . $cell : $cell, $row);
            fputcsv($out, $row, ';', '"', '\\', "\r\n");
        }
        fclose($out);
        sodium_memzero($pair);
        exit;
    }

    /**
     * @param array<string,string> $actions
     *
     * @return array<string,string>
     */
    public function bulk_actions(array $actions): array
    {
        unset($actions['edit']);
        $actions['nyassobi_finaliser'] = __('Finaliser (inscrites au registre, effacer leurs données)', 'nyassobi-wp-plugin');

        return $actions;
    }

    /**
     * @param int[] $ids
     */
    public function handle_bulk_finalize(string $redirect, string $action, array $ids): string
    {
        if ('nyassobi_finaliser' !== $action || ! current_user_can(Nyassobi_Membership::CAP_ADHESIONS)) {
            return $redirect;
        }
        $done = 0;
        foreach ($ids as $id) {
            // Only paid requests: the others are still waiting for a vote or a payment.
            if (Nyassobi_Membership::STATUS_PAID === $this->meta((int) $id, Nyassobi_Membership::META_STATUS)) {
                wp_delete_post((int) $id, true);
                ++$done;
            }
        }

        return add_query_arg(['nyassobi_finalisees' => $done, 'nyassobi_ignorees' => count($ids) - $done], $redirect);
    }

    public function finalized_notice(): void
    {

        if (! isset($_GET['nyassobi_finalisees'])) {
            return;
        }
        $done = (int) $_GET['nyassobi_finalisees'];
        $skipped = (int) ($_GET['nyassobi_ignorees'] ?? 0);
        printf('<div class="notice notice-success is-dismissible"><p>%s%s</p></div>',
            esc_html(sprintf(_n('%d adhésion finalisée : ses données sont effacées de WordPress.', '%d adhésions finalisées : leurs données sont effacées de WordPress.', $done, 'nyassobi-wp-plugin'), $done)),
            $skipped ? esc_html(sprintf(_n(' %d demande ignorée (pas encore payée).', ' %d demandes ignorées (pas encore payées).', $skipped, 'nyassobi-wp-plugin'), $skipped)) : ''
        );
    }

    public function metabox_rows(int $post_id): void
    {
        $rows = [];
        $username = $this->meta($post_id, Nyassobi_Membership::META_DISCORD_USERNAME);
        if ('' !== $username) {
            $rows[__('Pseudo Discord', 'nyassobi-wp-plugin')] = $username;
        }
        $rows[__('Cotisation', 'nyassobi-wp-plugin')] = sprintf('%d €', $this->fee_euros($post_id));
        $paid_at = (int) $this->meta($post_id, self::META_PAID_AT);
        if ($paid_at) {
            $rows[__('Payée le', 'nyassobi-wp-plugin')] = wp_date('j F Y à H:i', $paid_at) . ' · ' . (self::VIA_LABELS[$this->meta($post_id, self::META_PAID_VIA)] ?? '') . ' · ' . sprintf(__('saison %s', 'nyassobi-wp-plugin'), $this->season($post_id));
            $rows[__('Effacement automatique', 'nyassobi-wp-plugin')] = wp_date('j F Y', $paid_at + (int) $this->settings()['paid_retention_days'] * DAY_IN_SECONDS) . ' ' . __('(sauf finalisation avant)', 'nyassobi-wp-plugin');
        } elseif ($accepted = (int) $this->meta($post_id, self::META_ACCEPTED_AT)) {
            $rows[__('Acceptée le', 'nyassobi-wp-plugin')] = wp_date('j F Y', $accepted) . ('' !== $this->meta($post_id, self::META_REMINDED) ? ' · ' . __('relance envoyée', 'nyassobi-wp-plugin') : '');
        }
        $note = $this->meta($post_id, Nyassobi_Membership::META_DISCORD_NOTE);
        if ('' !== $note) {
            $rows[__('Rôle Discord', 'nyassobi-wp-plugin')] = $note;
        }
        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
        }
    }
}
