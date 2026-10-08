<?php
/**
 * Membership requests reviewed by the board (CA) on Discord.
 *
 * Flow: the React front-end calls the `submitNyassobiMembership` mutation, the
 * request is stored as a private post, and a message with Pour / Contre /
 * Abstention buttons is posted in a private Discord channel. Discord sends each
 * click to the REST route below (Discord "interactions endpoint"), so no bot
 * process has to run anywhere. Once a majority of the board is reached either
 * way, the applicant receives an email.
 *
 * Privacy: Discord only ever sees the pseudonym, and even that is removed from
 * the message once the vote is decided. The civil identity stays in WordPress,
 * visible to administrators only; a refused request is deleted right away.
 *
 * @package NyassobiWPPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Nyassobi_Membership
{
    public const POST_TYPE = 'nyassobi_adhesion';
    private const OPTION_NAME = 'nyassobi_membership';
    private const OPTION_GROUP = 'nyassobi_membership_group';
    private const PAGE_SLUG = 'nyassobi-membership';
    public const CAPABILITY = 'manage_options';
    /**
     * Reading and handling membership requests. Granted only to the bureau
     * accounts chosen in the settings (see grant_bureau_cap()), not to every
     * administrator.
     */
    public const CAP_ADHESIONS = 'nyassobi_adhesions';
    private const BUREAU_OPTION = 'nyassobi_bureau_users';
    public const REST_NAMESPACE = 'nyassobi/v1';
    private const DISCORD_API = 'https://discord.com/api/v10';
    private const CUSTOM_ID_PREFIX = 'nyassobi_vote';
    private const DECIDED_HOOK = 'nyassobi_membership_decided';
    public const PURGE_HOOK = 'nyassobi_membership_purge';
    private const PENDING_EXPIRY_DAYS = 90;
    private const SUBMISSIONS_PER_HOUR = 3;

    public const META_PSEUDO = '_nyassobi_pseudo';
    public const META_FIRST_NAME = '_nyassobi_first_name';
    public const META_LAST_NAME = '_nyassobi_last_name';
    public const META_BIRTH_DATE = '_nyassobi_birth_date';
    public const META_EMAIL = '_nyassobi_email';
    public const META_EMAIL_HASH = '_nyassobi_email_hash';
    public const META_REDUCED_RATE = '_nyassobi_reduced_rate';
    public const META_STATUS = '_nyassobi_status';
    public const META_VOTES = '_nyassobi_votes';
    public const META_DISCORD_MESSAGE = '_nyassobi_discord_message';
    private const META_PARENTAL_FILE = '_nyassobi_parental_file';
    private const META_PARENTAL_MIME = '_nyassobi_parental_mime';
    /** Kept in clear (instead of the encrypted birth date) for the rate and the emails. */
    public const META_MINOR = '_nyassobi_minor';

    /** Signed parental authorization: a scan or a phone photo. */
    private const PARENTAL_MAX_BYTES = 5 * 1024 * 1024;
    private const PARENTAL_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    /** Accepted, but the fee was never paid in time. */
    public const STATUS_LAPSED = 'lapsed';
    public const META_DISCORD_USERNAME = '_nyassobi_discord_username';
    /** Line added under the Discord message once paid (role given, or to give by hand). */
    public const META_DISCORD_NOTE = '_nyassobi_discord_note';
    /** Set by the register export: finalizing erases the data, so only what was exported can go. */
    public const META_EXPORTED_AT = '_nyassobi_exported_at';

    private const VOTE_LABELS = [
        'pour' => 'Pour',
        'contre' => 'Contre',
        'abstention' => 'Abstention',
    ];

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
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'schedule_purge']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('add_meta_boxes', [$this, 'register_metabox']);
        add_action('admin_post_nyassobi_membership_action', [$this, 'handle_admin_action']);
        add_action('admin_post_nyassobi_membership_reveal', [$this, 'reveal_identity']);
        add_action('admin_post_nyassobi_vault', [$this, 'handle_vault_form']);
        add_filter('user_has_cap', [$this, 'grant_bureau_cap'], 10, 3);
        add_action('before_delete_post', [$this, 'delete_parental_file']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_column'], 10, 2);
        add_action('graphql_register_types', [$this, 'register_mutation']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action(self::DECIDED_HOOK, [$this, 'notify_decision']);
        add_action(self::PURGE_HOOK, [$this, 'purge_expired']);
        add_filter('pre_trash_post', [$this, 'delete_instead_of_trash'], 10, 2);
    }

    /**
     * The trash would keep personal data for 30 days: a request sent to the
     * trash is deleted for good instead.
     *
     * @param bool|null $trash
     */
    public function delete_instead_of_trash($trash, \WP_Post $post)
    {
        if (self::POST_TYPE !== $post->post_type) {
            return $trash;
        }

        return (bool) wp_delete_post($post->ID, true);
    }

    /* ------------------------------------------------------------------
     * Storage
     * ------------------------------------------------------------------ */

    public function register_post_type(): void
    {
        // Every capability maps to the bureau's own: other administrators,
        // editors and authors cannot open the requests.
        $caps = [
            'edit_post' => self::CAP_ADHESIONS,
            'read_post' => self::CAP_ADHESIONS,
            'delete_post' => self::CAP_ADHESIONS,
            'edit_posts' => self::CAP_ADHESIONS,
            'edit_others_posts' => self::CAP_ADHESIONS,
            'delete_posts' => self::CAP_ADHESIONS,
            'publish_posts' => self::CAP_ADHESIONS,
            'read_private_posts' => self::CAP_ADHESIONS,
            'create_posts' => 'do_not_allow',
        ];

        register_post_type(
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => __('Adhésions', 'nyassobi-wp-plugin'),
                    'singular_name' => __('Demande d\'adhésion', 'nyassobi-wp-plugin'),
                    'menu_name' => __('Adhésions', 'nyassobi-wp-plugin'),
                    'all_items' => __('Demandes', 'nyassobi-wp-plugin'),
                    'edit_item' => __('Demande d\'adhésion', 'nyassobi-wp-plugin'),
                    'not_found' => __('Aucune demande pour le moment.', 'nyassobi-wp-plugin'),
                ],
                'public' => false,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_rest' => false,
                'show_in_graphql' => false,
                'exclude_from_search' => true,
                'menu_icon' => 'dashicons-groups',
                'menu_position' => 26,
                'supports' => ['title'],
                'capabilities' => $caps,
                'map_meta_cap' => false,
            ]
        );
    }

    /* ------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    /**
     * Settings sections, in page order.
     *
     * @return array<string,string>
     */
    private function get_sections(): array
    {
        return [
            'discord' => __('Vote du CA sur Discord', 'nyassobi-wp-plugin'),
            'paiement' => __('Paiement de la cotisation', 'nyassobi-wp-plugin'),
            'role' => __('Rôle Discord des adhérents', 'nyassobi-wp-plugin'),
            'emails' => __('Expéditeur des e-mails', 'nyassobi-wp-plugin'),
        ];
    }

    /**
     * Types: `id` (Discord identifiers and keys, digits / hex only), `text`,
     * `secret` (never printed back), `number`, `url`, `email`, `checkbox`.
     *
     * @return array<string,array{label:string,description:string,type:string,section:string}>
     */
    public function get_fields_definition(): array
    {
        return [
            'discord_application_id' => [
                'section' => 'discord',
                'label' => __('ID de l\'application Discord', 'nyassobi-wp-plugin'),
                'description' => __('Portail développeur Discord > General Information > Application ID.', 'nyassobi-wp-plugin'),
                'type' => 'id',
            ],
            'discord_public_key' => [
                'section' => 'discord',
                'label' => __('Clé publique Discord', 'nyassobi-wp-plugin'),
                'description' => __('General Information > Public Key. Sert à vérifier que les clics viennent bien de Discord.', 'nyassobi-wp-plugin'),
                'type' => 'id',
            ],
            'discord_bot_token' => [
                'section' => 'discord',
                'label' => __('Jeton du bot Discord', 'nyassobi-wp-plugin'),
                'description' => __('Bot > Reset Token. Laisser vide pour conserver le jeton déjà enregistré.', 'nyassobi-wp-plugin'),
                'type' => 'secret',
            ],
            'discord_channel_id' => [
                'section' => 'discord',
                'label' => __('ID du salon du CA', 'nyassobi-wp-plugin'),
                'description' => __('Salon privé où arrivent les demandes (clic droit sur le salon > Copier l\'identifiant).', 'nyassobi-wp-plugin'),
                'type' => 'id',
            ],
            'discord_board_role_id' => [
                'section' => 'discord',
                'label' => __('ID du rôle CA', 'nyassobi-wp-plugin'),
                'description' => __('Seules les personnes ayant ce rôle peuvent voter.', 'nyassobi-wp-plugin'),
                'type' => 'id',
            ],
            'board_size' => [
                'section' => 'discord',
                'label' => __('Nombre de membres du CA', 'nyassobi-wp-plugin'),
                'description' => __('Une demande est acceptée à la moitié + 1 de ce nombre (4 voix pour un CA de 6).', 'nyassobi-wp-plugin'),
                'type' => 'number',
            ],
            'bureau_email' => [
                'section' => 'discord',
                'label' => __('E-mail du bureau', 'nyassobi-wp-plugin'),
                'description' => __('Prévenu quand une cotisation est payée, pour inscrire la personne au registre. Aucune donnée personnelle dans ce message.', 'nyassobi-wp-plugin'),
                'type' => 'email',
            ],
            'site_url' => [
                'section' => 'paiement',
                'label' => __('Adresse du site public', 'nyassobi-wp-plugin'),
                'description' => __('Par exemple https://nyassobi.fr : les e-mails y renvoient vers la page de paiement de la cotisation.', 'nyassobi-wp-plugin'),
                'type' => 'url',
            ],
            'fee_normal' => [
                'section' => 'paiement',
                'label' => __('Cotisation, tarif normal (€)', 'nyassobi-wp-plugin'),
                'description' => __('20 € par défaut. Affichée sur le formulaire du site.', 'nyassobi-wp-plugin'),
                'type' => 'number',
            ],
            'fee_reduced' => [
                'section' => 'paiement',
                'label' => __('Cotisation, tarif réduit (€)', 'nyassobi-wp-plugin'),
                'description' => __('15 € par défaut : mineurs, étudiants, demandeurs d\'emploi, aides sociales.', 'nyassobi-wp-plugin'),
                'type' => 'number',
            ],
            'helloasso_client_id' => [
                'section' => 'paiement',
                'label' => __('HelloAsso : client ID', 'nyassobi-wp-plugin'),
                'description' => __('Back-office HelloAsso > Mon compte > Intégrations et API.', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'helloasso_client_secret' => [
                'section' => 'paiement',
                'label' => __('HelloAsso : client secret', 'nyassobi-wp-plugin'),
                'description' => __('Laisser vide pour conserver la clé déjà enregistrée.', 'nyassobi-wp-plugin'),
                'type' => 'secret',
            ],
            'helloasso_org_slug' => [
                'section' => 'paiement',
                'label' => __('HelloAsso : nom de l\'association dans l\'adresse', 'nyassobi-wp-plugin'),
                'description' => __('La partie après /associations/ dans l\'adresse de la page HelloAsso (« nyassobi »).', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'helloasso_sandbox' => [
                'section' => 'paiement',
                'label' => __('HelloAsso : environnement de test', 'nyassobi-wp-plugin'),
                'description' => __('Utilise helloasso-sandbox.com et ses cartes bancaires fictives.', 'nyassobi-wp-plugin'),
                'type' => 'checkbox',
            ],
            'payment_url' => [
                'section' => 'paiement',
                'label' => __('Lien de paiement de secours', 'nyassobi-wp-plugin'),
                'description' => __('Utilisé tant que l\'API HelloAsso n\'est pas renseignée : le bureau marque alors le paiement à la main.', 'nyassobi-wp-plugin'),
                'type' => 'url',
            ],
            'paypal_client_id' => [
                'section' => 'paiement',
                'label' => __('PayPal : client ID', 'nyassobi-wp-plugin'),
                'description' => __('Compte PayPal Business > Développeurs > Applications. Vide = PayPal non proposé.', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'paypal_client_secret' => [
                'section' => 'paiement',
                'label' => __('PayPal : secret', 'nyassobi-wp-plugin'),
                'description' => __('Laisser vide pour conserver la clé déjà enregistrée.', 'nyassobi-wp-plugin'),
                'type' => 'secret',
            ],
            'paypal_sandbox' => [
                'section' => 'paiement',
                'label' => __('PayPal : environnement de test', 'nyassobi-wp-plugin'),
                'description' => __('Utilise le bac à sable de PayPal (comptes fictifs).', 'nyassobi-wp-plugin'),
                'type' => 'checkbox',
            ],
            'reminder_days' => [
                'section' => 'paiement',
                'label' => __('Relance après (jours)', 'nyassobi-wp-plugin'),
                'description' => __('Un e-mail de rappel part si la cotisation n\'est pas réglée. 7 par défaut.', 'nyassobi-wp-plugin'),
                'type' => 'number',
            ],
            'expiry_days' => [
                'section' => 'paiement',
                'label' => __('Expiration après (jours)', 'nyassobi-wp-plugin'),
                'description' => __('Sans paiement, la demande expire et ses données sont effacées. 30 par défaut.', 'nyassobi-wp-plugin'),
                'type' => 'number',
            ],
            'finalize_reminder_days' => [
                'section' => 'paiement',
                'label' => __('Rappel au bureau après paiement (jours)', 'nyassobi-wp-plugin'),
                'description' => __('Si une adhésion payée n\'est pas encore finalisée, le bureau reçoit un rappel. 15 par défaut.', 'nyassobi-wp-plugin'),
                'type' => 'number',
            ],
            'paid_retention_days' => [
                'section' => 'paiement',
                'label' => __('Effacement après paiement (jours)', 'nyassobi-wp-plugin'),
                'description' => __('Une adhésion payée est effacée de WordPress au bout de ce délai, même sans finalisation : les données ne restent pas en ligne. 30 par défaut.', 'nyassobi-wp-plugin'),
                'type' => 'number',
            ],
            'renewal_reminder_date' => [
                'section' => 'paiement',
                'label' => __('Rappel de renouvellement (JJ/MM)', 'nyassobi-wp-plugin'),
                'description' => __('Ce jour-là, le bureau est invité à envoyer le rappel de renouvellement (adresses tirées du registre, rien n\'est gardé en ligne). 15/08 par défaut, avant le 31 août.', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'renewal_channel_id' => [
                'section' => 'role',
                'label' => __('Salon de l\'annonce de renouvellement', 'nyassobi-wp-plugin'),
                'description' => __('Facultatif : le jour du rappel, le bot y mentionne le rôle « Adhérent » pour annoncer la nouvelle saison. Aucune donnée personnelle.', 'nyassobi-wp-plugin'),
                'type' => 'id',
            ],
            'discord_guild_id' => [
                'section' => 'role',
                'label' => __('ID du serveur Discord', 'nyassobi-wp-plugin'),
                'description' => __('Clic droit sur le serveur > Copier l\'identifiant. Vide = pas de rôle automatique.', 'nyassobi-wp-plugin'),
                'type' => 'id',
            ],
            'discord_member_role_id' => [
                'section' => 'role',
                'label' => __('ID du rôle « Adhérent »', 'nyassobi-wp-plugin'),
                'description' => __('Donné automatiquement au paiement. Le rôle du bot doit être placé au-dessus dans la liste des rôles.', 'nyassobi-wp-plugin'),
                'type' => 'id',
            ],
            'sender_email' => [
                'section' => 'emails',
                'label' => __('Adresse d\'expédition', 'nyassobi-wp-plugin'),
                'description' => __('Une adresse en @nyassobi.fr (par exemple adhesion@nyassobi.fr). Sans elle, WordPress écrit au nom de « WordPress », ce qui fait souvent atterrir les e-mails en spam.', 'nyassobi-wp-plugin'),
                'type' => 'email',
            ],
            'sender_name' => [
                'section' => 'emails',
                'label' => __('Nom de l\'expéditeur', 'nyassobi-wp-plugin'),
                'description' => __('« Nyassobi » par défaut.', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'discord_invite_url' => [
                'section' => 'role',
                'label' => __('Invitation au serveur Discord', 'nyassobi-wp-plugin'),
                'description' => __('Envoyée aux nouveaux membres qui ne sont pas encore sur le serveur.', 'nyassobi-wp-plugin'),
                'type' => 'url',
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function get_settings(): array
    {
        $stored = get_option(self::OPTION_NAME, []);
        $settings = is_array($stored) ? array_map('strval', $stored) : [];
        $defaults = ['board_size' => 6, 'fee_normal' => 20, 'fee_reduced' => 15, 'reminder_days' => 7, 'expiry_days' => 30, 'finalize_reminder_days' => 15, 'paid_retention_days' => 30];
        foreach ($defaults as $key => $default) {
            $settings[$key] = (string) (max(0, (int) ($settings[$key] ?? 0)) ?: $default);
        }
        // The test environment points the links to its own copy of the site.
        $settings['renewal_reminder_date'] = preg_match('#^(\d{1,2})/(\d{1,2})$#', trim($settings['renewal_reminder_date'] ?? ''), $d) && checkdate((int) $d[2], (int) $d[1], 2000) && (int) $d[2] <= 8
            ? sprintf('%02d/%02d', (int) $d[1], (int) $d[2])
            : '15/08';
        $settings['site_url'] = (string) apply_filters('nyassobi_membership_site_url', untrailingslashit($settings['site_url'] ?? '') ?: home_url());

        return $settings;
    }

    /**
     * The flow is only offered once Discord is configured and the bureau has
     * set its passphrase: no request can ever be stored unencrypted.
     */
    private function is_configured(): bool
    {
        if (! Nyassobi_Vault::is_ready()) {
            return false;
        }
        $settings = self::get_settings();
        foreach (['discord_application_id', 'discord_public_key', 'discord_bot_token', 'discord_channel_id', 'discord_board_role_id'] as $key) {
            if ('' === trim($settings[$key] ?? '')) {
                return false;
            }
        }

        return true;
    }

    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => [],
            ]
        );

        $intros = [
            'discord' => sprintf(
                '<p>%s</p><p>%s <code>%s</code></p>',
                esc_html__('Les demandes d\'adhésion du site sont soumises au vote du conseil d\'administration dans un salon Discord privé. Le CA ne voit que le pseudo.', 'nyassobi-wp-plugin'),
                esc_html__('Dans le portail développeur Discord, renseigner comme « Interactions Endpoint URL » :', 'nyassobi-wp-plugin'),
                esc_html(rest_url(self::REST_NAMESPACE . '/discord'))
            ),
            'paiement' => sprintf(
                '<p>%s</p><p>%s <code>%s</code></p>',
                esc_html__('Une fois la demande acceptée, la personne reçoit un lien vers une page de paiement : carte bancaire par HelloAsso, et PayPal si ses clés sont renseignées. Le paiement est détecté tout seul.', 'nyassobi-wp-plugin'),
                esc_html__('Dans HelloAsso (Intégrations et API > Notifications), indiquer comme adresse de notification :', 'nyassobi-wp-plugin'),
                esc_html(rest_url(self::REST_NAMESPACE . '/helloasso'))
            ),
            'emails' => '<p>' . esc_html__('Les réponses aux e-mails arrivent à l\'adresse de contact des réglages Nyassobi.', 'nyassobi-wp-plugin') . '</p>',
            'role' => '<p>' . esc_html__('Facultatif : le formulaire demande alors le pseudo Discord, et le rôle est donné dès le paiement. Le bot doit avoir la permission « Gérer les rôles ».', 'nyassobi-wp-plugin') . '</p>',
        ];

        foreach ($this->get_sections() as $section => $title) {
            add_settings_section(
                'nyassobi_membership_' . $section,
                $title,
                static function () use ($intros, $section): void {
                    echo $intros[$section]; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
                },
                self::PAGE_SLUG
            );
        }

        foreach ($this->get_fields_definition() as $key => $field) {
            add_settings_field(
                $key,
                esc_html($field['label']),
                [$this, 'render_field'],
                self::PAGE_SLUG,
                'nyassobi_membership_' . $field['section'],
                ['key' => $key, 'type' => $field['type'], 'description' => $field['description']]
            );
        }
    }

    public function register_settings_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            __('Réglages des adhésions', 'nyassobi-wp-plugin'),
            __('Réglages', 'nyassobi-wp-plugin'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_settings_page']
        );
    }

    public function render_settings_page(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Réglages des adhésions', 'nyassobi-wp-plugin'); ?></h1>
            <?php
            $notice = get_transient('nyassobi_vault_notice_' . get_current_user_id());
            if ($notice) {
                delete_transient('nyassobi_vault_notice_' . get_current_user_id());
                printf('<div class="notice notice-success"><p>%s</p></div>', esc_html((string) $notice));
            }
            ?>
            <?php if (! $this->is_configured()) : ?>
                <div class="notice notice-warning"><p>
                    <?php esc_html_e('Tant que Discord n\'est pas entièrement configuré et que le mot de passe du bureau n\'est pas choisi (en bas de page), le site continue d\'envoyer vers l\'ancien formulaire d\'adhésion.', 'nyassobi-wp-plugin'); ?>
                </p></div>
            <?php endif; ?>
            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
            <?php $this->render_bureau_box(); ?>
            <?php do_action('nyassobi_membership_settings_after'); ?>
        </div>
        <?php
    }

    /**
     * @param array<string,string> $args
     */
    public function render_field(array $args): void
    {
        $stored = get_option(self::OPTION_NAME, []);
        $key = $args['key'];
        $value = is_array($stored) ? (string) ($stored[$key] ?? '') : '';
        $name = sprintf('%s[%s]', self::OPTION_NAME, $key);

        switch ($args['type']) {
            case 'secret':
                // Secrets are never printed back into the page.
                printf(
                    '<input type="password" name="%1$s" id="%2$s" value="" class="regular-text" autocomplete="off" placeholder="%3$s" />',
                    esc_attr($name),
                    esc_attr($key),
                    esc_attr('' !== $value ? __('Clé enregistrée', 'nyassobi-wp-plugin') : '')
                );
                break;
            case 'number':
                printf('<input type="number" min="0" max="500" name="%1$s" id="%2$s" value="%3$s" class="small-text" />', esc_attr($name), esc_attr($key), esc_attr($value));
                break;
            case 'checkbox':
                printf('<label><input type="checkbox" name="%1$s" id="%2$s" value="1" %3$s /> %4$s</label>', esc_attr($name), esc_attr($key), checked('1', $value, false), esc_html__('Activé', 'nyassobi-wp-plugin'));
                break;
            case 'url':
            case 'email':
                printf('<input type="%4$s" name="%1$s" id="%2$s" value="%3$s" class="regular-text code" />', esc_attr($name), esc_attr($key), esc_attr($value), esc_attr($args['type']));
                break;
            default:
                printf('<input type="text" name="%1$s" id="%2$s" value="%3$s" class="regular-text code" />', esc_attr($name), esc_attr($key), esc_attr($value));
        }

        if ('' !== ($args['description'] ?? '')) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    /**
     * @param array<string,mixed>|null $input
     *
     * @return array<string,string>
     */
    public function sanitize_settings(?array $input): array
    {
        $previous = get_option(self::OPTION_NAME, []);
        $previous = is_array($previous) ? $previous : [];
        $sanitized = [];

        foreach ($this->get_fields_definition() as $key => $field) {
            $raw = trim((string) ($input[$key] ?? ''));

            switch ($field['type']) {
                case 'secret':
                    $sanitized[$key] = '' !== $raw ? sanitize_text_field($raw) : (string) ($previous[$key] ?? '');
                    break;
                case 'number':
                    $sanitized[$key] = '' === $raw ? '' : (string) max(0, min(500, (int) $raw));
                    break;
                case 'checkbox':
                    $sanitized[$key] = '1' === $raw ? '1' : '';
                    break;
                case 'url':
                    $sanitized[$key] = esc_url_raw($raw);
                    break;
                case 'email':
                    $sanitized[$key] = sanitize_email($raw);
                    break;
                case 'text':
                    $sanitized[$key] = sanitize_text_field($raw);
                    break;
                default:
                    $sanitized[$key] = preg_replace('/[^0-9a-fA-F]/', '', $raw) ?? '';
            }
        }

        return $sanitized;
    }

    /* ------------------------------------------------------------------
     * GraphQL mutation
     * ------------------------------------------------------------------ */

    public function register_mutation(): void
    {
        if (! function_exists('register_graphql_mutation')) {
            return;
        }

        // Lets the front-end know before anyone fills the form in whether
        // requests can be received; otherwise it points to the old form.
        register_graphql_field(
            'RootQuery',
            'nyassobiMembershipOpen',
            [
                'type' => ['non_null' => 'Boolean'],
                'description' => __('Vrai quand les demandes d\'adhésion du site sont reçues et soumises au CA.', 'nyassobi-wp-plugin'),
                'resolve' => fn (): bool => $this->is_configured(),
            ]
        );

        // Without Discord there is nobody to vote: the mutation is simply not exposed.
        if (! $this->is_configured()) {
            return;
        }

        register_graphql_input_type(
            'NyassobiUploadInput',
            [
                'description' => __('Fichier envoyé en base64.', 'nyassobi-wp-plugin'),
                'fields' => [
                    'fileName' => ['type' => ['non_null' => 'String']],
                    'mimeType' => ['type' => ['non_null' => 'String']],
                    'base64' => ['type' => ['non_null' => 'String']],
                ],
            ]
        );

        register_graphql_mutation(
            'submitNyassobiMembership',
            [
                'inputFields' => [
                    'pseudo' => ['type' => ['non_null' => 'String']],
                    'firstName' => ['type' => ['non_null' => 'String']],
                    'lastName' => ['type' => ['non_null' => 'String']],
                    'birthDate' => ['type' => ['non_null' => 'String'], 'description' => 'AAAA-MM-JJ'],
                    'email' => ['type' => ['non_null' => 'String']],
                    'reducedRate' => ['type' => ['non_null' => 'Boolean']],
                    'acceptsRules' => ['type' => ['non_null' => 'Boolean']],
                    'acceptsPrivacy' => ['type' => ['non_null' => 'Boolean']],
                    'discordUsername' => [
                        'type' => 'String',
                        'description' => __('Pseudo Discord, pour recevoir le rôle Adhérent au paiement.', 'nyassobi-wp-plugin'),
                    ],
                    'parentalAuthorization' => [
                        'type' => 'NyassobiUploadInput',
                        'description' => __('Autorisation parentale signée (PDF ou photo), obligatoire pour les mineurs.', 'nyassobi-wp-plugin'),
                    ],
                ],
                'outputFields' => [
                    'success' => [
                        'type' => ['non_null' => 'Boolean'],
                        'resolve' => static fn ($payload): bool => (bool) ($payload['success'] ?? false),
                    ],
                    'message' => [
                        'type' => ['non_null' => 'String'],
                        'resolve' => static fn ($payload): string => (string) ($payload['message'] ?? ''),
                    ],
                ],
                'mutateAndGetPayload' => function (array $input): array {
                    return $this->handle_submission($input);
                },
            ]
        );
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array{success:bool,message:string}
     */
    private function handle_submission(array $input): array
    {
        $error = '\GraphQL\Error\UserError';

        $pseudo = sanitize_text_field((string) ($input['pseudo'] ?? ''));
        $first_name = sanitize_text_field((string) ($input['firstName'] ?? ''));
        $last_name = sanitize_text_field((string) ($input['lastName'] ?? ''));
        $birth_date = sanitize_text_field((string) ($input['birthDate'] ?? ''));
        $email = sanitize_email((string) ($input['email'] ?? ''));
        $reduced_rate = (bool) ($input['reducedRate'] ?? false);
        // Discord usernames: 2 to 32 lowercase letters, digits, dots and underscores.
        $discord_username = strtolower(ltrim(trim((string) ($input['discordUsername'] ?? '')), '@'));

        if ('' === $pseudo || '' === $first_name || '' === $last_name) {
            throw new $error(__('Merci de remplir ton pseudo, ton prénom et ton nom.', 'nyassobi-wp-plugin'));
        }
        if (mb_strlen($pseudo) > 60 || mb_strlen($first_name) > 80 || mb_strlen($last_name) > 80) {
            throw new $error(__('Un des champs est trop long.', 'nyassobi-wp-plugin'));
        }
        if (! is_email($email)) {
            throw new $error(__('Cette adresse e-mail ne semble pas valide.', 'nyassobi-wp-plugin'));
        }
        if ('' !== $discord_username && ! preg_match('/^[a-z0-9_.]{2,32}$/', $discord_username)) {
            throw new $error(__('Ce pseudo Discord ne semble pas valide (lettres, chiffres, points et tirets bas).', 'nyassobi-wp-plugin'));
        }
        $age = self::age_from_birth_date($birth_date);
        if (null === $age || $age < 0 || $age > 120) {
            throw new $error(__('La date de naissance ne semble pas valide.', 'nyassobi-wp-plugin'));
        }
        if (empty($input['acceptsRules']) || empty($input['acceptsPrivacy'])) {
            throw new $error(__('Il faut accepter les statuts, le règlement et le traitement des données.', 'nyassobi-wp-plugin'));
        }

        // Checked before anything is stored, so a bad file costs nothing.
        $parental = null;
        if ($age < 18) {
            $parental = self::decode_upload($input['parentalAuthorization'] ?? null);
            if (is_string($parental)) {
                throw new $error($parental);
            }
        }

        // A few requests per hour and per address are plenty for a human.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $rate_key = 'nyassobi_join_' . md5(wp_salt('auth') . $ip);
        $attempts = (int) get_transient($rate_key);
        // Filterable so a test environment can submit as often as it needs.
        $limit = (int) apply_filters('nyassobi_membership_submissions_per_hour', self::SUBMISSIONS_PER_HOUR);
        if ($attempts >= $limit) {
            throw new $error(__('Trop de demandes envoyées depuis cette connexion. Réessaie dans une heure.', 'nyassobi-wp-plugin'));
        }
        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);

        $email_hash = hash_hmac('sha256', strtolower($email), wp_salt('auth'));
        if ($this->find_pending_by_email_hash($email_hash)) {
            throw new $error(__('Une demande est déjà en cours pour cette adresse e-mail. Le CA va bientôt voter !', 'nyassobi-wp-plugin'));
        }

        $post_id = wp_insert_post(
            [
                'post_type' => self::POST_TYPE,
                'post_status' => 'private',
                'post_title' => __('Demande d\'adhésion', 'nyassobi-wp-plugin'),
            ],
            true
        );
        if (is_wp_error($post_id)) {
            return ['success' => false, 'message' => __('La demande n\'a pas pu être enregistrée. Réessaie plus tard.', 'nyassobi-wp-plugin')];
        }

        wp_update_post(['ID' => $post_id, 'post_title' => sprintf(__('Demande n°%d', 'nyassobi-wp-plugin'), $post_id)]);
        update_post_meta($post_id, self::META_PSEUDO, $pseudo);
        // Identity is encrypted for the bureau before it is ever written.
        update_post_meta($post_id, self::META_FIRST_NAME, Nyassobi_Vault::seal($first_name));
        update_post_meta($post_id, self::META_LAST_NAME, Nyassobi_Vault::seal($last_name));
        update_post_meta($post_id, self::META_BIRTH_DATE, Nyassobi_Vault::seal($birth_date));
        update_post_meta($post_id, self::META_MINOR, $age < 18 ? '1' : '0');
        update_post_meta($post_id, self::META_EMAIL, $email);
        update_post_meta($post_id, self::META_EMAIL_HASH, $email_hash);
        // Minors always pay the reduced rate.
        update_post_meta($post_id, self::META_REDUCED_RATE, ($reduced_rate || $age < 18) ? '1' : '0');
        update_post_meta($post_id, self::META_STATUS, self::STATUS_PENDING);
        update_post_meta($post_id, self::META_VOTES, []);
        if ('' !== $discord_username) {
            update_post_meta($post_id, self::META_DISCORD_USERNAME, $discord_username);
        }


        if (is_array($parental) && ! $this->store_parental_file($post_id, $parental)) {
            wp_delete_post($post_id, true);
            return ['success' => false, 'message' => __('L\'autorisation parentale n\'a pas pu être enregistrée. Réessaie plus tard.', 'nyassobi-wp-plugin')];
        }

        $message_id = $this->post_discord_message($post_id);
        if (null === $message_id) {
            // Without the Discord message nobody can vote: better to tell the
            // applicant now than to leave the request forgotten.
            wp_delete_post($post_id, true);
            return ['success' => false, 'message' => __('La demande n\'a pas pu être transmise au CA. Réessaie dans quelques minutes, ou écris-nous.', 'nyassobi-wp-plugin')];
        }
        update_post_meta($post_id, self::META_DISCORD_MESSAGE, $message_id);

        $this->send_mail(
            $email,
            __('Nyassobi : demande d\'adhésion bien reçue', 'nyassobi-wp-plugin'),
            [
                sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $pseudo),
                '',
                __('Nous avons bien reçu ta demande d\'adhésion à Nyassobi, merci !', 'nyassobi-wp-plugin'),
                __('Le conseil d\'administration va l\'étudier. Tu recevras un e-mail dès qu\'il aura voté, avec la marche à suivre pour régler ta cotisation.', 'nyassobi-wp-plugin'),
                '',
                __('Pour que ce prochain e-mail ne finisse pas dans tes spams, ajoute notre adresse à tes contacts. Si celui-ci y était, signale-le comme « non spam ».', 'nyassobi-wp-plugin'),
                '',
                __('À très vite,', 'nyassobi-wp-plugin'),
                __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'),
            ]
        );

        return [
            'success' => true,
            'message' => __('Le conseil d\'administration va étudier ta demande. Tu recevras sa réponse par e-mail, puis le lien pour régler ta cotisation. Un e-mail de confirmation vient de partir : s\'il n\'est pas dans ta boîte de réception, regarde dans tes spams et ajoute notre adresse à tes contacts, pour ne pas rater la suite.', 'nyassobi-wp-plugin'),
        ];
    }

    /* ------------------------------------------------------------------
     * Parental authorization
     * ------------------------------------------------------------------ */

    /**
     * Decodes and checks an uploaded file. The type is read from the bytes
     * themselves, never trusted from the browser.
     *
     * @param mixed $upload
     *
     * @return array{bytes:string,mime:string}|string The file, or an error message.
     */
    public static function decode_upload($upload)
    {
        if (! is_array($upload) || '' === (string) ($upload['base64'] ?? '')) {
            return __('Comme tu as moins de 18 ans, il faut joindre l\'autorisation parentale signée.', 'nyassobi-wp-plugin');
        }
        // A data: URL prefix is tolerated, browsers produce one.
        $data = preg_replace('/^data:[^,]*,/', '', (string) $upload['base64']) ?? '';
        if (strlen($data) > (int) ceil(self::PARENTAL_MAX_BYTES * 4 / 3) + 8) {
            return __('L\'autorisation parentale dépasse 5 Mo.', 'nyassobi-wp-plugin');
        }
        $bytes = base64_decode($data, true);
        if (false === $bytes || '' === $bytes) {
            return __('Le fichier de l\'autorisation parentale est illisible.', 'nyassobi-wp-plugin');
        }
        if (strlen($bytes) > self::PARENTAL_MAX_BYTES) {
            return __('L\'autorisation parentale dépasse 5 Mo.', 'nyassobi-wp-plugin');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! isset(self::PARENTAL_TYPES[$mime])) {
            return __('L\'autorisation parentale doit être un PDF ou une photo (JPEG, PNG, WebP, HEIC).', 'nyassobi-wp-plugin');
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /**
     * Folder outside the media library. Apache refuses direct access, and the
     * random file names cannot be guessed on servers that ignore .htaccess.
     */
    private static function private_dir(): string
    {
        $dir = trailingslashit(wp_upload_dir()['basedir']) . 'nyassobi-prive';
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (! file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (! file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence.\n");
        }

        return $dir;
    }

    /**
     * @param array{bytes:string,mime:string} $file
     */
    private function store_parental_file(int $post_id, array $file): bool
    {
        $name = bin2hex(random_bytes(16)) . '.' . self::PARENTAL_TYPES[$file['mime']];
        if (false === file_put_contents(self::private_dir() . '/' . $name, Nyassobi_Vault::seal($file['bytes']))) {
            return false;
        }
        update_post_meta($post_id, self::META_PARENTAL_FILE, $name);
        update_post_meta($post_id, self::META_PARENTAL_MIME, $file['mime']);

        return true;
    }

    private static function parental_path(int $post_id): ?string
    {
        $name = (string) get_post_meta($post_id, self::META_PARENTAL_FILE, true);
        if (! preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $name)) {
            return null;
        }
        $path = self::private_dir() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /** The document goes with the request, whatever the reason it is deleted. */
    public function delete_parental_file(int $post_id): void
    {
        if (self::POST_TYPE !== get_post_type($post_id)) {
            return;
        }
        $path = self::parental_path($post_id);
        if (null !== $path) {
            wp_delete_file($path);
        }
    }

    /* ------------------------------------------------------------------
     * Bureau access and the encrypted identity
     * ------------------------------------------------------------------ */

    /** @return int[] WordPress accounts of the bureau. */
    public static function bureau_ids(): array
    {
        return array_values(array_filter(array_map('intval', (array) get_option(self::BUREAU_OPTION, []))));
    }

    /**
     * Grants the membership capability to the bureau accounts only. Until
     * the bureau is chosen, administrators have it, so that someone can set
     * things up.
     *
     * @param array<string,bool> $allcaps
     * @param string[]           $caps
     * @param array<int,mixed>   $args
     *
     * @return array<string,bool>
     */
    public function grant_bureau_cap(array $allcaps, array $caps, array $args): array
    {
        if (! in_array(self::CAP_ADHESIONS, $caps, true)) {
            return $allcaps;
        }
        $bureau = self::bureau_ids();
        $user_id = (int) ($args[1] ?? 0);
        $allcaps[self::CAP_ADHESIONS] = $bureau ? in_array($user_id, $bureau, true) : ! empty($allcaps[self::CAPABILITY]);

        return $allcaps;
    }

    /**
     * Shows a request's identity and parental authorization, decrypted with
     * the bureau passphrase for this page only. Nothing decrypted is stored.
     */
    public function reveal_identity(): void
    {
        $post_id = isset($_POST['post']) ? (int) $_POST['post'] : 0;
        if (! current_user_can(self::CAP_ADHESIONS) || ! check_admin_referer('nyassobi_membership_reveal_' . $post_id)) {
            wp_die(esc_html__('Action non autorisée.', 'nyassobi-wp-plugin'));
        }
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            wp_die(esc_html__('Demande introuvable.', 'nyassobi-wp-plugin'));
        }
        $pair = Nyassobi_Vault::unlock((string) wp_unslash($_POST['passphrase'] ?? ''));
        $back = admin_url('post.php?post=' . $post_id . '&action=edit');
        if (null === $pair) {
            wp_die(esc_html(Nyassobi_Vault::wrong_passphrase_message()), '', ['back_link' => true]);
        }

        $field = fn (string $key): string => (string) (Nyassobi_Vault::open((string) get_post_meta($post_id, $key, true), $pair) ?? __('(illisible)', 'nyassobi-wp-plugin'));
        $birth = $field(self::META_BIRTH_DATE);
        $rows = [
            __('Pseudo', 'nyassobi-wp-plugin') => (string) get_post_meta($post_id, self::META_PSEUDO, true),
            __('Prénom', 'nyassobi-wp-plugin') => $field(self::META_FIRST_NAME),
            __('Nom', 'nyassobi-wp-plugin') => $field(self::META_LAST_NAME),
            __('Date de naissance', 'nyassobi-wp-plugin') => $birth,
            __('E-mail', 'nyassobi-wp-plugin') => (string) get_post_meta($post_id, self::META_EMAIL, true),
        ];
        $html = '<h1>' . esc_html(sprintf(__('Demande n°%d', 'nyassobi-wp-plugin'), $post_id)) . '</h1><table class="widefat striped" style="max-width:640px"><tbody>';
        foreach ($rows as $label => $value) {
            $html .= sprintf('<tr><th style="width:40%%">%s</th><td>%s</td></tr>', esc_html($label), esc_html($value));
        }
        $html .= '</tbody></table>';

        $path = self::parental_path($post_id);
        if (null !== $path) {
            $bytes = Nyassobi_Vault::open((string) file_get_contents($path), $pair);
            $mime = (string) get_post_meta($post_id, self::META_PARENTAL_MIME, true);
            if (null !== $bytes && isset(self::PARENTAL_TYPES[$mime])) {
                $data = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                $html .= '<h2>' . esc_html__('Autorisation parentale', 'nyassobi-wp-plugin') . '</h2>';
                $html .= 'application/pdf' === $mime
                    ? sprintf('<object data="%s" type="application/pdf" style="width:100%%;height:70vh"></object>', esc_attr($data))
                    : sprintf('<img src="%s" alt="" style="max-width:100%%;max-height:70vh">', esc_attr($data));
                $html .= sprintf('<p><a class="button" download="autorisation-parentale-%d.%s" href="%s">%s</a></p>', $post_id, esc_attr(self::PARENTAL_TYPES[$mime]), esc_attr($data), esc_html__('Télécharger', 'nyassobi-wp-plugin'));
            }
        }
        sodium_memzero($pair);
        $html .= sprintf('<p><a href="%s">%s</a></p>', esc_url($back), esc_html__('← Retour à la demande', 'nyassobi-wp-plugin'));

        nocache_headers();
        wp_die($html, esc_html__('Demande d\'adhésion', 'nyassobi-wp-plugin'), ['response' => 200]); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    /** A small form asking for the bureau passphrase before an action. */
    public static function passphrase_form(string $action, string $nonce_action, string $button, array $hidden = [], bool $inside_form = false): string
    {
        if ($inside_form) {
            return self::detached_passphrase_form($action, $nonce_action, $button, $hidden);
        }
        $fields = '';
        foreach ($hidden as $name => $value) {
            $fields .= sprintf('<input type="hidden" name="%s" value="%s">', esc_attr($name), esc_attr((string) $value));
        }

        return sprintf(
            '<form method="post" action="%1$s" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap"><input type="hidden" name="action" value="%2$s">%3$s%4$s<input type="password" name="passphrase" placeholder="%5$s" autocomplete="off" required class="regular-text" style="width:16em"><button class="button button-primary">%6$s</button></form>',
            esc_url(admin_url('admin-post.php')),
            esc_attr($action),
            wp_nonce_field($nonce_action, '_wpnonce', true, false),
            $fields,
            esc_attr__('Mot de passe du bureau', 'nyassobi-wp-plugin'),
            esc_html($button)
        );
    }

    /**
     * Same fields, for a place already inside a form (a metabox of the edit
     * screen): browsers drop a nested <form>, and the click would submit the
     * post instead. The fields point with form="…" to an empty form printed
     * at the end of the page.
     *
     * @param array<string,string|int> $hidden
     */
    private static function detached_passphrase_form(string $action, string $nonce_action, string $button, array $hidden): string
    {
        $id = 'nyassobi-form-' . wp_unique_id();
        add_action('admin_footer', static function () use ($id): void {
            printf('<form id="%s" method="post" action="%s"></form>', esc_attr($id), esc_url(admin_url('admin-post.php')));
        });
        $hidden = ['action' => $action, '_wpnonce' => wp_create_nonce($nonce_action)] + $hidden;
        $fields = '';
        foreach ($hidden as $name => $value) {
            $fields .= sprintf('<input type="hidden" form="%s" name="%s" value="%s">', esc_attr($id), esc_attr($name), esc_attr((string) $value));
        }

        return sprintf(
            '<span style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap">%1$s<input type="password" form="%2$s" name="passphrase" placeholder="%3$s" autocomplete="off" required class="regular-text" style="width:16em"><button type="submit" form="%2$s" class="button button-primary">%4$s</button></span>',
            $fields,
            esc_attr($id),
            esc_attr__('Mot de passe du bureau', 'nyassobi-wp-plugin'),
            esc_html($button)
        );
    }

    /** Bureau accounts and the vault passphrase, on the settings page. */
    private function render_bureau_box(): void
    {
        $can_edit = ! self::bureau_ids() || in_array(get_current_user_id(), self::bureau_ids(), true);
        echo '<h2>' . esc_html__('Bureau et mot de passe des données', 'nyassobi-wp-plugin') . '</h2>';
        $names = array_map(static fn (int $id): string => (string) (get_userdata($id)->user_login ?? ''), self::bureau_ids());
        echo '<p>' . esc_html__('Seuls ces comptes WordPress voient les demandes d\'adhésion :', 'nyassobi-wp-plugin') . ' <strong>' . esc_html($names ? implode(', ', $names) : __('pas encore choisis (tous les administrateurs)', 'nyassobi-wp-plugin')) . '</strong></p>';
        if (! $can_edit) {
            echo '<p class="description">' . esc_html__('Seul un membre du bureau peut modifier ces réglages.', 'nyassobi-wp-plugin') . '</p>';
            return;
        }
        $form = static function (string $what, string $inner, string $button): void {
            printf(
                '<form method="post" action="%s" style="margin:12px 0"><input type="hidden" name="action" value="nyassobi_vault"><input type="hidden" name="quoi" value="%s">%s%s <button class="button">%s</button></form>',
                esc_url(admin_url('admin-post.php')),
                esc_attr($what),
                wp_nonce_field('nyassobi_vault_' . $what, '_wpnonce', true, false),
                $inner, // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
                esc_html($button)
            );
        };
        $form('bureau', '<label>' . esc_html__('Identifiants WordPress du bureau, séparés par des virgules :', 'nyassobi-wp-plugin') . ' <input type="text" name="comptes" class="regular-text" value="' . esc_attr(implode(', ', $names)) . '"></label>', __('Enregistrer le bureau', 'nyassobi-wp-plugin'));

        if (! Nyassobi_Vault::is_ready()) {
            echo '<p>' . esc_html__('Le mot de passe du bureau chiffre les noms, dates de naissance et autorisations parentales : sans lui, personne (ni un autre administrateur, ni l\'hébergeur) ne peut les lire. Tant qu\'il n\'est pas choisi, le circuit d\'adhésion reste fermé. Notez-le en lieu sûr : s\'il est perdu, les demandes en cours deviennent illisibles.', 'nyassobi-wp-plugin') . '</p>';
            $form('creer', '<input type="password" name="nouveau" placeholder="' . esc_attr__('Mot de passe (10 caractères ou plus)', 'nyassobi-wp-plugin') . '" autocomplete="new-password" required class="regular-text"> <input type="password" name="confirmation" placeholder="' . esc_attr__('Le même, une seconde fois', 'nyassobi-wp-plugin') . '" autocomplete="new-password" required class="regular-text">', __('Choisir le mot de passe du bureau', 'nyassobi-wp-plugin'));
            return;
        }
        echo '<p>' . esc_html__('Le mot de passe du bureau est en place : les données d\'identité sont chiffrées.', 'nyassobi-wp-plugin') . '</p>';
        $form('changer', '<input type="password" name="ancien" placeholder="' . esc_attr__('Mot de passe actuel', 'nyassobi-wp-plugin') . '" autocomplete="current-password" required class="regular-text"> <input type="password" name="nouveau" placeholder="' . esc_attr__('Nouveau mot de passe', 'nyassobi-wp-plugin') . '" autocomplete="new-password" required class="regular-text"> <input type="password" name="confirmation" placeholder="' . esc_attr__('Le même, une seconde fois', 'nyassobi-wp-plugin') . '" autocomplete="new-password" required class="regular-text">', __('Changer le mot de passe', 'nyassobi-wp-plugin'));
        $form('perdu', '<label><input type="checkbox" name="confirme" value="1" required> ' . esc_html__('Mot de passe perdu : en choisir un nouveau. Les demandes en cours deviendront illisibles (il faudra les redemander).', 'nyassobi-wp-plugin') . '</label> <input type="password" name="nouveau" placeholder="' . esc_attr__('Nouveau mot de passe', 'nyassobi-wp-plugin') . '" autocomplete="new-password" required class="regular-text"> <input type="password" name="confirmation" placeholder="' . esc_attr__('Le même, une seconde fois', 'nyassobi-wp-plugin') . '" autocomplete="new-password" required class="regular-text">', __('Réinitialiser', 'nyassobi-wp-plugin'));
    }

    public function handle_vault_form(): void
    {
        $what = sanitize_key((string) ($_POST['quoi'] ?? ''));
        $can_edit = ! self::bureau_ids() || in_array(get_current_user_id(), self::bureau_ids(), true);
        if (! current_user_can(self::CAPABILITY) || ! $can_edit || ! check_admin_referer('nyassobi_vault_' . $what)) {
            wp_die(esc_html__('Action non autorisée.', 'nyassobi-wp-plugin'));
        }
        $new = (string) wp_unslash($_POST['nouveau'] ?? '');
        $confirm = (string) wp_unslash($_POST['confirmation'] ?? '');
        $fail = static function (string $message): void {
            wp_die(esc_html($message), '', ['back_link' => true]);
        };
        if (in_array($what, ['creer', 'changer', 'perdu'], true)) {
            if (mb_strlen($new) < Nyassobi_Vault::MIN_PASSPHRASE) {
                $fail(__('Le mot de passe doit faire au moins 10 caractères.', 'nyassobi-wp-plugin'));
            }
            if ($new !== $confirm) {
                $fail(__('Les deux mots de passe ne correspondent pas.', 'nyassobi-wp-plugin'));
            }
        }
        $message = '';
        switch ($what) {
            case 'bureau':
                $ids = [];
                foreach (array_filter(array_map('trim', explode(',', (string) wp_unslash($_POST['comptes'] ?? '')))) as $login) {
                    $user = get_user_by('login', $login) ?: get_user_by('email', $login);
                    if (! $user) {
                        $fail(sprintf(__('Compte introuvable : %s', 'nyassobi-wp-plugin'), $login));
                    }
                    $ids[] = (int) $user->ID;
                }
                // Never lock oneself out by mistake.
                if ($ids && ! in_array(get_current_user_id(), $ids, true)) {
                    $fail(__('Ajoutez aussi votre propre compte, sinon vous perdriez l\'accès aux adhésions.', 'nyassobi-wp-plugin'));
                }
                update_option(self::BUREAU_OPTION, array_values(array_unique($ids)), false);
                $message = __('Bureau enregistré.', 'nyassobi-wp-plugin');
                break;
            case 'creer':
                if (Nyassobi_Vault::is_ready()) {
                    $fail(__('Un mot de passe existe déjà.', 'nyassobi-wp-plugin'));
                }
                Nyassobi_Vault::setup($new);
                $message = __('Mot de passe du bureau enregistré : les nouvelles demandes seront chiffrées.', 'nyassobi-wp-plugin');
                break;
            case 'changer':
                if (! Nyassobi_Vault::change((string) wp_unslash($_POST['ancien'] ?? ''), $new)) {
                    $fail(Nyassobi_Vault::is_throttled() ? Nyassobi_Vault::wrong_passphrase_message() : __('Mot de passe actuel incorrect.', 'nyassobi-wp-plugin'));
                }
                $message = __('Mot de passe du bureau changé.', 'nyassobi-wp-plugin');
                break;
            case 'perdu':
                if (empty($_POST['confirme'])) {
                    $fail(__('Cochez la case de confirmation.', 'nyassobi-wp-plugin'));
                }
                Nyassobi_Vault::setup($new);
                $message = __('Nouveau mot de passe en place. Les demandes déjà reçues ne sont plus lisibles.', 'nyassobi-wp-plugin');
                break;
            default:
                $fail(__('Action inconnue.', 'nyassobi-wp-plugin'));
        }
        set_transient('nyassobi_vault_notice_' . get_current_user_id(), $message, 60);
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE . '&page=' . self::PAGE_SLUG));
        exit;
    }

    private function find_pending_by_email_hash(string $hash): bool
    {
        $ids = get_posts(
            [
                'post_type' => self::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'meta_query' => [
                    ['key' => self::META_EMAIL_HASH, 'value' => $hash],
                    ['key' => self::META_STATUS, 'value' => self::STATUS_PENDING],
                ],
            ]
        );

        return ! empty($ids);
    }

    public static function age_from_birth_date(string $birth_date, ?\DateTimeImmutable $today = null): ?int
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $birth_date, wp_timezone());
        if (! $date || $date->format('Y-m-d') !== $birth_date) {
            return null;
        }
        $today = $today ?? new \DateTimeImmutable('now', wp_timezone());
        $age = $date->diff($today)->y;

        return $date > $today ? -1 : $age;
    }

    /* ------------------------------------------------------------------
     * Votes
     * ------------------------------------------------------------------ */

    /**
     * Votes needed to accept: half the board plus one.
     */
    public static function majority(int $board_size): int
    {
        return intdiv($board_size, 2) + 1;
    }

    /**
     * Decision once it can no longer change: accepted as soon as enough
     * "pour", refused as soon as enough other votes make that impossible.
     *
     * @param array<string,string> $votes Discord user id => pour|contre|abstention.
     */
    public static function decide(array $votes, int $board_size): ?string
    {
        $needed = self::majority($board_size);
        $tally = self::tally($votes);
        if ($tally['pour'] >= $needed) {
            return self::STATUS_ACCEPTED;
        }
        $still_possible = $board_size - $tally['contre'] - $tally['abstention'];
        if ($still_possible < $needed) {
            return self::STATUS_REFUSED;
        }

        return null;
    }

    /**
     * @param array<string,string> $votes
     *
     * @return array{pour:int,contre:int,abstention:int}
     */
    public static function tally(array $votes): array
    {
        $tally = ['pour' => 0, 'contre' => 0, 'abstention' => 0];
        foreach ($votes as $choice) {
            if (isset($tally[$choice])) {
                ++$tally[$choice];
            }
        }

        return $tally;
    }

    /* ------------------------------------------------------------------
     * Discord
     * ------------------------------------------------------------------ */

    /**
     * Message shown in the board channel. The pseudonym only appears while
     * the vote is open.
     *
     * @return array<string,mixed>
     */
    private function build_message(int $post_id): array
    {
        $settings = self::get_settings();
        $board_size = (int) $settings['board_size'];
        $status = (string) get_post_meta($post_id, self::META_STATUS, true);
        $votes = (array) get_post_meta($post_id, self::META_VOTES, true);
        $tally = self::tally($votes);
        $count_line = sprintf(
            '✅ Pour : **%d** · ❌ Contre : **%d** · ⚪ Abstention : **%d**',
            $tally['pour'],
            $tally['contre'],
            $tally['abstention']
        );

        if (self::STATUS_PENDING === $status) {
            $pseudo = self::escape_markdown((string) get_post_meta($post_id, self::META_PSEUDO, true));
            $embed = [
                'title' => sprintf('Nouvelle demande d\'adhésion n°%d', $post_id),
                'description' => sprintf("Pseudo : **%s**\n\n%s\nIl faut %d voix « pour » sur %d.", $pseudo, $count_line, self::majority($board_size), $board_size),
                'color' => 0xE8622F,
                'footer' => ['text' => 'Réservé au CA · un vote peut être changé tant que la décision n\'est pas prise'],
            ];
        } else {
            $outcomes = [
                self::STATUS_ACCEPTED => 'Acceptée · cotisation en attente',
                self::STATUS_PAID => 'Acceptée · cotisation reçue',
                self::STATUS_REFUSED => 'Refusée',
                self::STATUS_LAPSED => 'Acceptée · cotisation non réglée, demande expirée',
                self::STATUS_EXPIRED => 'Expirée sans décision',
            ];
            $note = (string) get_post_meta($post_id, self::META_DISCORD_NOTE, true);
            $embed = [
                'title' => sprintf('Demande n°%d · %s', $post_id, $outcomes[$status] ?? $status),
                'description' => $count_line . ('' !== $note ? "\n" . $note : ''),
                'color' => self::STATUS_PAID === $status ? 0x248046 : (self::STATUS_ACCEPTED === $status ? 0x0F9D93 : 0x87685C),
            ];
        }

        $buttons = [];
        foreach (['pour' => 3, 'contre' => 4, 'abstention' => 2] as $choice => $style) {
            $buttons[] = [
                'type' => 2,
                'style' => $style,
                'label' => self::VOTE_LABELS[$choice],
                'custom_id' => sprintf('%s:%d:%s', self::CUSTOM_ID_PREFIX, $post_id, $choice),
                'disabled' => self::STATUS_PENDING !== $status,
            ];
        }

        return [
            'embeds' => [$embed],
            'components' => self::STATUS_PENDING === $status ? [['type' => 1, 'components' => $buttons]] : [],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    private static function escape_markdown(string $text): string
    {
        return (string) preg_replace('/([\\\\*_~`|>#\[\]()@:-])/u', '\\\\$1', $text);
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>|null Decoded response, or null on failure.
     */
    public function discord_request(string $method, string $path, array $body = []): ?array
    {
        $settings = self::get_settings();
        $response = wp_remote_request(
            self::DISCORD_API . $path,
            [
                'method' => $method,
                'timeout' => 8,
                'headers' => [
                    'Authorization' => 'Bot ' . ($settings['discord_bot_token'] ?? ''),
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'DiscordBot (https://nyassobi.fr, 1.0)',
                ],
                'body' => $body ? wp_json_encode($body) : null,
            ]
        );

        if (is_wp_error($response)) {
            error_log('[Nyassobi] Discord : ' . $response->get_error_message());
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log(sprintf('[Nyassobi] Discord a répondu %d sur %s', $code, $path));
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function post_discord_message(int $post_id): ?string
    {
        $settings = self::get_settings();
        $result = $this->discord_request('POST', '/channels/' . rawurlencode($settings['discord_channel_id']) . '/messages', $this->build_message($post_id));

        return isset($result['id']) ? (string) $result['id'] : null;
    }

    /** Refreshes the Discord message after a decision taken outside Discord. */
    public function update_discord_message(int $post_id): void
    {
        $message_id = (string) get_post_meta($post_id, self::META_DISCORD_MESSAGE, true);
        if ('' === $message_id) {
            return;
        }
        $settings = self::get_settings();
        $this->discord_request(
            'PATCH',
            '/channels/' . rawurlencode($settings['discord_channel_id']) . '/messages/' . rawurlencode($message_id),
            $this->build_message($post_id)
        );
    }

    public function register_rest_routes(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/discord',
            [
                'methods' => 'POST',
                'callback' => [$this, 'handle_interaction'],
                // Authentication is the Ed25519 signature checked in the callback.
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Discord interactions endpoint: validation pings and button clicks.
     */
    public function handle_interaction(\WP_REST_Request $request): \WP_REST_Response
    {
        if (! $this->verify_signature($request)) {
            return new \WP_REST_Response(['error' => 'invalid request signature'], 401);
        }

        $payload = json_decode($request->get_body(), true);
        if (! is_array($payload)) {
            return new \WP_REST_Response(['error' => 'bad request'], 400);
        }

        // Type 1: PING, sent by Discord when the endpoint URL is saved.
        if (1 === (int) ($payload['type'] ?? 0)) {
            return new \WP_REST_Response(['type' => 1], 200);
        }

        // Type 3: button click.
        if (3 !== (int) ($payload['type'] ?? 0)) {
            return $this->ephemeral(__('Action inconnue.', 'nyassobi-wp-plugin'));
        }

        $custom_id = (string) ($payload['data']['custom_id'] ?? '');
        if (! preg_match('/^' . self::CUSTOM_ID_PREFIX . ':(\d+):(pour|contre|abstention)$/', $custom_id, $match)) {
            return $this->ephemeral(__('Bouton inconnu.', 'nyassobi-wp-plugin'));
        }
        $post_id = (int) $match[1];
        $choice = $match[2];

        $settings = self::get_settings();
        $roles = (array) ($payload['member']['roles'] ?? []);
        $user_id = (string) ($payload['member']['user']['id'] ?? '');
        if ('' === $user_id || ! in_array($settings['discord_board_role_id'], $roles, true)) {
            return $this->ephemeral(__('Seuls les membres du CA peuvent voter.', 'nyassobi-wp-plugin'));
        }

        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            return $this->ephemeral(__('Cette demande n\'existe plus.', 'nyassobi-wp-plugin'));
        }

        // Two board members can click at the same moment: the lock keeps one
        // vote from overwriting the other.
        if (! $this->acquire_lock($post_id)) {
            return $this->ephemeral(__('Un autre vote est en cours d\'enregistrement, réessaie dans une seconde.', 'nyassobi-wp-plugin'));
        }

        try {
            if (self::STATUS_PENDING !== get_post_meta($post_id, self::META_STATUS, true)) {
                return $this->ephemeral(__('Le vote est déjà terminé pour cette demande.', 'nyassobi-wp-plugin'));
            }

            $votes = (array) get_post_meta($post_id, self::META_VOTES, true);
            $votes[$user_id] = $choice;
            update_post_meta($post_id, self::META_VOTES, $votes);

            $decision = self::decide($votes, (int) $settings['board_size']);
            if (null !== $decision) {
                update_post_meta($post_id, self::META_STATUS, $decision);
                // Emails can take a few seconds; Discord expects an answer in
                // three, so they are sent right after this response.
                wp_schedule_single_event(time(), self::DECIDED_HOOK, [$post_id]);
                // The site gets few visits: start WP-Cron now rather than
                // waiting for the next one.
                spawn_cron();
            }
        } finally {
            $this->release_lock($post_id);
        }

        // Type 7: update the message the button belongs to.
        return new \WP_REST_Response(['type' => 7, 'data' => $this->build_message($post_id)], 200);
    }

    private function verify_signature(\WP_REST_Request $request): bool
    {
        $settings = self::get_settings();
        $public_key = $settings['discord_public_key'] ?? '';
        $signature = (string) $request->get_header('x_signature_ed25519');
        $timestamp = (string) $request->get_header('x_signature_timestamp');

        if ('' === $public_key || '' === $signature || '' === $timestamp || ! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        if (! ctype_xdigit($signature) || 128 !== strlen($signature) || ! ctype_xdigit($public_key) || 64 !== strlen($public_key)) {
            return false;
        }
        // The timestamp is part of what Discord signs: refusing old ones stops
        // a recorded click from being replayed later.
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 5 * MINUTE_IN_SECONDS) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached((string) hex2bin($signature), $timestamp . $request->get_body(), (string) hex2bin($public_key));
        } catch (\SodiumException $exception) {
            return false;
        }
    }

    private function ephemeral(string $message): \WP_REST_Response
    {
        // Type 4 with flag 64: a reply only the clicking person sees.
        return new \WP_REST_Response(['type' => 4, 'data' => ['content' => $message, 'flags' => 64]], 200);
    }

    public function acquire_lock(int $post_id): bool
    {
        $key = 'nyassobi_vote_lock_' . $post_id;
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            // add_option fails if the row exists: an atomic "take the lock".
            if (add_option($key, (string) time(), '', false)) {
                return true;
            }
            // A lock older than 10 s belongs to a request that died.
            if ((int) get_option($key) < time() - 10) {
                delete_option($key);
                continue;
            }
            usleep(150000);
        }

        return false;
    }

    public function release_lock(int $post_id): void
    {
        delete_option('nyassobi_vote_lock_' . $post_id);
    }

    /* ------------------------------------------------------------------
     * After the decision
     * ------------------------------------------------------------------ */

    public function notify_decision(int $post_id): void
    {
        $status = (string) get_post_meta($post_id, self::META_STATUS, true);
        // WP-Cron can run the same event twice at once (two visits, or a
        // manual run): add_post_meta with $unique fails for the second one,
        // so a decision is announced exactly once.
        if (! in_array($status, [self::STATUS_ACCEPTED, self::STATUS_REFUSED], true) || ! add_post_meta($post_id, '_nyassobi_decision_notified', $status, true)) {
            return;
        }
        $email = (string) get_post_meta($post_id, self::META_EMAIL, true);
        // Les e-mails s'adressent à la personne par son pseudo, comme dans la communauté.
        $pseudo = (string) get_post_meta($post_id, self::META_PSEUDO, true);
        $settings = self::get_settings();

        if (self::STATUS_ACCEPTED === $status) {
            // The acceptance email carries the personal payment link: it is
            // written by Nyassobi_Membership_Payment.
            do_action('nyassobi_membership_accepted', $post_id);
        } elseif (self::STATUS_REFUSED === $status) {
            $this->send_mail(
                $email,
                __('Nyassobi : réponse à ta demande d\'adhésion', 'nyassobi-wp-plugin'),
                [
                    sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $pseudo),
                    '',
                    __('Le conseil d\'administration n\'a pas retenu ta demande d\'adhésion à Nyassobi cette fois-ci.', 'nyassobi-wp-plugin'),
                    __('Les informations que tu nous avais transmises ont été effacées.', 'nyassobi-wp-plugin'),
                    __('Si tu as une question, tu peux simplement répondre à cet e-mail.', 'nyassobi-wp-plugin'),
                    '',
                    __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'),
                ]
            );
            // Nothing is kept about a refused request.
            wp_delete_post($post_id, true);
        }
    }

    /**
     * @param string[] $lines
     */
    public function send_mail(string $to, string $subject, array $lines, bool $reply_to_contact = true): void
    {
        if (! is_email($to)) {
            return;
        }
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $settings = self::get_settings();
        // An address of the association's own domain passes the SPF check of
        // nyassobi.fr; WordPress' default "wordpress@admin..." passes none.
        if (is_email($settings['sender_email'] ?? '')) {
            $name = str_replace(['"', "\r", "\n"], '', ($settings['sender_name'] ?? '') ?: 'Nyassobi');
            $headers[] = sprintf('From: "%s" <%s>', $name, $settings['sender_email']);
        }
        $main = class_exists('Nyassobi_WP_Plugin') ? Nyassobi_WP_Plugin::get_settings() : [];
        if ($reply_to_contact && is_email($main['contact_email'] ?? '')) {
            $headers[] = 'Reply-To: Nyassobi <' . $main['contact_email'] . '>';
        }
        wp_mail($to, $subject, implode("\n", $lines), $headers);
    }

    public function schedule_purge(): void
    {
        if (! wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }
    }

    /**
     * Requests nobody voted on for months are deleted, with their data.
     */
    public function purge_expired(): void
    {
        $ids = get_posts(
            [
                'post_type' => self::POST_TYPE,
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 100,
                'date_query' => [['before' => self::PENDING_EXPIRY_DAYS . ' days ago']],
                'meta_query' => [['key' => self::META_STATUS, 'value' => self::STATUS_PENDING]],
            ]
        );
        foreach ($ids as $id) {
            update_post_meta((int) $id, self::META_STATUS, 'expired');
            $this->update_discord_message((int) $id);
            wp_delete_post((int) $id, true);
        }
    }

    /* ------------------------------------------------------------------
     * Admin screens
     * ------------------------------------------------------------------ */

    /**
     * @param array<string,string> $columns
     *
     * @return array<string,string>
     */
    public function admin_columns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '',
            'title' => __('Demande', 'nyassobi-wp-plugin'),
            'nyassobi_pseudo' => __('Pseudo', 'nyassobi-wp-plugin'),
            'nyassobi_status' => __('Statut', 'nyassobi-wp-plugin'),
            'nyassobi_votes' => __('Votes', 'nyassobi-wp-plugin'),
            'date' => $columns['date'] ?? __('Date', 'nyassobi-wp-plugin'),
        ];
    }

    public function render_admin_column(string $column, int $post_id): void
    {
        switch ($column) {
            case 'nyassobi_pseudo':
                echo esc_html((string) get_post_meta($post_id, self::META_PSEUDO, true));
                break;
            case 'nyassobi_status':
                echo esc_html($this->status_label((string) get_post_meta($post_id, self::META_STATUS, true)));
                break;
            case 'nyassobi_votes':
                $tally = self::tally((array) get_post_meta($post_id, self::META_VOTES, true));
                printf('%d / %d / %d', $tally['pour'], $tally['contre'], $tally['abstention']);
                break;
        }
    }

    public function status_label(string $status): string
    {
        switch ($status) {
            case self::STATUS_PENDING:
                return __('Vote en cours', 'nyassobi-wp-plugin');
            case self::STATUS_ACCEPTED:
                return __('Acceptée, cotisation en attente', 'nyassobi-wp-plugin');
            case self::STATUS_PAID:
                return __('Cotisation payée, à inscrire au registre', 'nyassobi-wp-plugin');
            case self::STATUS_REFUSED:
                return __('Refusée', 'nyassobi-wp-plugin');
            default:
                return $status;
        }
    }

    public function register_metabox(): void
    {
        add_meta_box('nyassobi_membership_details', __('Demande', 'nyassobi-wp-plugin'), [$this, 'render_metabox'], self::POST_TYPE, 'normal', 'high');
        remove_meta_box('submitdiv', self::POST_TYPE, 'side');
    }

    public function render_metabox(\WP_Post $post): void
    {
        $id = $post->ID;
        $status = (string) get_post_meta($id, self::META_STATUS, true);
        $minor = '1' === get_post_meta($id, self::META_MINOR, true);
        $tally = self::tally((array) get_post_meta($id, self::META_VOTES, true));
        $rows = [
            __('Pseudo', 'nyassobi-wp-plugin') => get_post_meta($id, self::META_PSEUDO, true),
            __('E-mail', 'nyassobi-wp-plugin') => get_post_meta($id, self::META_EMAIL, true),
            __('Âge', 'nyassobi-wp-plugin') => $minor ? __('mineur·e (autorisation parentale jointe)', 'nyassobi-wp-plugin') : __('majeur·e', 'nyassobi-wp-plugin'),
            __('Tarif', 'nyassobi-wp-plugin') => '1' === get_post_meta($id, self::META_REDUCED_RATE, true) ? __('Réduit (15 €)', 'nyassobi-wp-plugin') : __('Normal (20 €)', 'nyassobi-wp-plugin'),
            __('Statut', 'nyassobi-wp-plugin') => $this->status_label($status),
            __('Votes', 'nyassobi-wp-plugin') => sprintf(__('%1$d pour, %2$d contre, %3$d abstention(s)', 'nyassobi-wp-plugin'), $tally['pour'], $tally['contre'], $tally['abstention']),
        ];
        echo '<table class="form-table" role="presentation"><tbody>';
        // Name, first name, birth date and parental authorization are
        // encrypted: they only show after the bureau passphrase.
        printf(
            '<tr><th scope="row">%s</th><td><p class="description" style="margin-top:0">%s</p>%s</td></tr>',
            esc_html__('Identité', 'nyassobi-wp-plugin'),
            esc_html($minor ? __('Nom, prénom, date de naissance et autorisation parentale sont chiffrés.', 'nyassobi-wp-plugin') : __('Nom, prénom et date de naissance sont chiffrés.', 'nyassobi-wp-plugin')),
            self::passphrase_form('nyassobi_membership_reveal', 'nyassobi_membership_reveal_' . $id, __('Afficher', 'nyassobi-wp-plugin'), ['post' => $id], true) // phpcs:ignore
        );
        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
        }
        do_action('nyassobi_membership_metabox_rows', $id);
        echo '</tbody></table>';

        $action_url = static function (string $action) use ($id): string {
            return wp_nonce_url(admin_url('admin-post.php?action=nyassobi_membership_action&do=' . $action . '&post=' . $id), 'nyassobi_membership_' . $action . '_' . $id);
        };

        echo '<p>';
        if (self::STATUS_PENDING === $status) {
            // Fallback when Discord is unavailable: the board can still decide here.
            printf('<a class="button button-primary" href="%s">%s</a> ', esc_url($action_url('accept')), esc_html__('Accepter (décision du CA)', 'nyassobi-wp-plugin'));
            printf('<a class="button" href="%s">%s</a> ', esc_url($action_url('refuse')), esc_html__('Refuser (décision du CA)', 'nyassobi-wp-plugin'));
        }
        if (self::STATUS_ACCEPTED === $status) {
            printf(
                '<a class="button" href="%s" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($action_url('paid')),
                esc_js(__('La cotisation a bien été reçue par un autre moyen (espèces, virement...) ?', 'nyassobi-wp-plugin')),
                esc_html__('Marquer la cotisation comme payée', 'nyassobi-wp-plugin')
            );
        }
        if (self::STATUS_PAID === $status && '' === (string) get_post_meta($post->ID, self::META_EXPORTED_AT, true)) {
            echo '<span class="description">' . esc_html__('Pour finaliser, exporter d\'abord pour le registre (bouton en haut de la liste des adhésions).', 'nyassobi-wp-plugin') . '</span>';
        } elseif (self::STATUS_PAID === $status) {
            printf(
                '<a class="button button-primary" href="%s" onclick="return confirm(\'%s\');">%s</a>',
                esc_url($action_url('finalize')),
                esc_js(__('La personne est bien inscrite au registre local ? Ses données vont être effacées de WordPress.', 'nyassobi-wp-plugin')),
                esc_html__('Finaliser et effacer les données en ligne', 'nyassobi-wp-plugin')
            );
        }
        echo '</p>';
        echo '<p class="description">' . esc_html__('Les données restent ici le temps de la décision et du paiement, puis sont effacées : le registre des membres est tenu hors ligne par le bureau.', 'nyassobi-wp-plugin') . '</p>';
    }

    public function handle_admin_action(): void
    {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $action = isset($_GET['do']) ? sanitize_key((string) $_GET['do']) : '';

        if (! current_user_can(self::CAP_ADHESIONS) || ! check_admin_referer('nyassobi_membership_' . $action . '_' . $post_id)) {
            wp_die(esc_html__('Action non autorisée.', 'nyassobi-wp-plugin'));
        }
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type) {
            wp_die(esc_html__('Demande introuvable.', 'nyassobi-wp-plugin'));
        }
        $status = (string) get_post_meta($post_id, self::META_STATUS, true);

        if (in_array($action, ['accept', 'refuse'], true) && self::STATUS_PENDING === $status) {
            update_post_meta($post_id, self::META_STATUS, 'accept' === $action ? self::STATUS_ACCEPTED : self::STATUS_REFUSED);
            $this->update_discord_message($post_id);
            $this->notify_decision($post_id);
        } elseif ('paid' === $action && self::STATUS_ACCEPTED === $status) {
            // Paid another way (cash at a convention, transfer...).
            do_action('nyassobi_membership_mark_paid', $post_id, 'manuel');
        } elseif ('finalize' === $action && self::STATUS_PAID === $status && '' !== (string) get_post_meta($post_id, self::META_EXPORTED_AT, true)) {
            wp_delete_post($post_id, true);
        }

        wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE));
        exit;
    }
}
