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
    private const CAPABILITY = 'manage_options';
    private const REST_NAMESPACE = 'nyassobi/v1';
    private const DISCORD_API = 'https://discord.com/api/v10';
    private const CUSTOM_ID_PREFIX = 'nyassobi_vote';
    private const DECIDED_HOOK = 'nyassobi_membership_decided';
    private const PURGE_HOOK = 'nyassobi_membership_purge';
    private const PENDING_EXPIRY_DAYS = 90;
    private const SUBMISSIONS_PER_HOUR = 3;

    private const META_PSEUDO = '_nyassobi_pseudo';
    private const META_FIRST_NAME = '_nyassobi_first_name';
    private const META_LAST_NAME = '_nyassobi_last_name';
    private const META_BIRTH_DATE = '_nyassobi_birth_date';
    private const META_EMAIL = '_nyassobi_email';
    private const META_EMAIL_HASH = '_nyassobi_email_hash';
    private const META_REDUCED_RATE = '_nyassobi_reduced_rate';
    private const META_STATUS = '_nyassobi_status';
    private const META_VOTES = '_nyassobi_votes';
    private const META_DISCORD_MESSAGE = '_nyassobi_discord_message';
    private const META_PARENTAL_FILE = '_nyassobi_parental_file';
    private const META_PARENTAL_MIME = '_nyassobi_parental_mime';

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

    private const STATUS_PENDING = 'pending';
    private const STATUS_ACCEPTED = 'accepted';
    private const STATUS_REFUSED = 'refused';

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
        add_action('admin_post_nyassobi_membership_file', [$this, 'download_parental_file']);
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
        // Every capability maps to manage_options: editors and authors must
        // not be able to read members' personal data.
        $caps = [
            'edit_post' => self::CAPABILITY,
            'read_post' => self::CAPABILITY,
            'delete_post' => self::CAPABILITY,
            'edit_posts' => self::CAPABILITY,
            'edit_others_posts' => self::CAPABILITY,
            'delete_posts' => self::CAPABILITY,
            'publish_posts' => self::CAPABILITY,
            'read_private_posts' => self::CAPABILITY,
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
     * @return array<string,array{label:string,description:string,type:string}>
     */
    private function get_fields_definition(): array
    {
        return [
            'discord_application_id' => [
                'label' => __('ID de l\'application Discord', 'nyassobi-wp-plugin'),
                'description' => __('Portail développeur Discord > General Information > Application ID.', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'discord_public_key' => [
                'label' => __('Clé publique Discord', 'nyassobi-wp-plugin'),
                'description' => __('General Information > Public Key. Sert à vérifier que les clics viennent bien de Discord.', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'discord_bot_token' => [
                'label' => __('Jeton du bot Discord', 'nyassobi-wp-plugin'),
                'description' => __('Bot > Reset Token. Laisser vide pour conserver le jeton déjà enregistré.', 'nyassobi-wp-plugin'),
                'type' => 'secret',
            ],
            'discord_channel_id' => [
                'label' => __('ID du salon du CA', 'nyassobi-wp-plugin'),
                'description' => __('Salon privé où arrivent les demandes (clic droit sur le salon > Copier l\'identifiant).', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'discord_board_role_id' => [
                'label' => __('ID du rôle CA', 'nyassobi-wp-plugin'),
                'description' => __('Seules les personnes ayant ce rôle peuvent voter.', 'nyassobi-wp-plugin'),
                'type' => 'text',
            ],
            'board_size' => [
                'label' => __('Nombre de membres du CA', 'nyassobi-wp-plugin'),
                'description' => __('Une demande est acceptée à la moitié + 1 de ce nombre (4 voix pour un CA de 6).', 'nyassobi-wp-plugin'),
                'type' => 'number',
            ],
            'payment_url' => [
                'label' => __('Lien de paiement de la cotisation', 'nyassobi-wp-plugin'),
                'description' => __('Envoyé par e-mail une fois la demande acceptée (formulaire HelloAsso, par exemple).', 'nyassobi-wp-plugin'),
                'type' => 'url',
            ],
            'bureau_email' => [
                'label' => __('E-mail du bureau', 'nyassobi-wp-plugin'),
                'description' => __('Prévenu quand une demande est acceptée, pour la finaliser. Aucune donnée personnelle dans ce message.', 'nyassobi-wp-plugin'),
                'type' => 'email',
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
        $settings['board_size'] = (string) max(1, (int) ($settings['board_size'] ?? 6));

        return $settings;
    }

    /** The flow is only offered once Discord is fully configured. */
    private function is_configured(): bool
    {
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

        add_settings_section(
            'nyassobi_membership_section',
            __('Vote du CA sur Discord', 'nyassobi-wp-plugin'),
            function (): void {
                printf(
                    '<p>%s</p><p>%s <code>%s</code></p>',
                    esc_html__('Les demandes d\'adhésion du site sont soumises au vote du conseil d\'administration dans un salon Discord privé. Le CA ne voit que le pseudo.', 'nyassobi-wp-plugin'),
                    esc_html__('Dans le portail développeur Discord, renseigner comme « Interactions Endpoint URL » :', 'nyassobi-wp-plugin'),
                    esc_html(rest_url(self::REST_NAMESPACE . '/discord'))
                );
            },
            self::PAGE_SLUG
        );

        foreach ($this->get_fields_definition() as $key => $field) {
            add_settings_field(
                $key,
                esc_html($field['label']),
                [$this, 'render_field'],
                self::PAGE_SLUG,
                'nyassobi_membership_section',
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
            <?php if (! $this->is_configured()) : ?>
                <div class="notice notice-warning"><p>
                    <?php esc_html_e('Tant que Discord n\'est pas entièrement configuré, le site continue d\'envoyer vers l\'ancien formulaire d\'adhésion.', 'nyassobi-wp-plugin'); ?>
                </p></div>
            <?php endif; ?>
            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * @param array<string,string> $args
     */
    public function render_field(array $args): void
    {
        $settings = self::get_settings();
        $key = $args['key'];
        $value = $settings[$key] ?? '';
        $name = sprintf('%s[%s]', self::OPTION_NAME, $key);

        switch ($args['type']) {
            case 'secret':
                // The token is never printed back into the page.
                printf(
                    '<input type="password" name="%1$s" id="%2$s" value="" class="regular-text" autocomplete="off" placeholder="%3$s" />',
                    esc_attr($name),
                    esc_attr($key),
                    esc_attr('' !== $value ? __('Jeton enregistré', 'nyassobi-wp-plugin') : '')
                );
                break;
            case 'number':
                printf('<input type="number" min="1" max="50" name="%1$s" id="%2$s" value="%3$s" class="small-text" />', esc_attr($name), esc_attr($key), esc_attr($value));
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
        $previous = self::get_settings();
        $sanitized = [];

        foreach ($this->get_fields_definition() as $key => $field) {
            $raw = trim((string) ($input[$key] ?? ''));

            switch ($field['type']) {
                case 'secret':
                    $sanitized[$key] = '' !== $raw ? sanitize_text_field($raw) : ($previous[$key] ?? '');
                    break;
                case 'number':
                    $sanitized[$key] = (string) max(1, min(50, (int) $raw));
                    break;
                case 'url':
                    $sanitized[$key] = esc_url_raw($raw);
                    break;
                case 'email':
                    $sanitized[$key] = sanitize_email($raw);
                    break;
                default:
                    // Discord identifiers and keys are digits / hex only.
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

        if ('' === $pseudo || '' === $first_name || '' === $last_name) {
            throw new $error(__('Merci de remplir ton pseudo, ton prénom et ton nom.', 'nyassobi-wp-plugin'));
        }
        if (mb_strlen($pseudo) > 60 || mb_strlen($first_name) > 80 || mb_strlen($last_name) > 80) {
            throw new $error(__('Un des champs est trop long.', 'nyassobi-wp-plugin'));
        }
        if (! is_email($email)) {
            throw new $error(__('Cette adresse e-mail ne semble pas valide.', 'nyassobi-wp-plugin'));
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
        update_post_meta($post_id, self::META_FIRST_NAME, $first_name);
        update_post_meta($post_id, self::META_LAST_NAME, $last_name);
        update_post_meta($post_id, self::META_BIRTH_DATE, $birth_date);
        update_post_meta($post_id, self::META_EMAIL, $email);
        update_post_meta($post_id, self::META_EMAIL_HASH, $email_hash);
        // Minors always pay the reduced rate.
        update_post_meta($post_id, self::META_REDUCED_RATE, ($reduced_rate || $age < 18) ? '1' : '0');
        update_post_meta($post_id, self::META_STATUS, self::STATUS_PENDING);
        update_post_meta($post_id, self::META_VOTES, []);

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
                sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $first_name),
                '',
                __('Nous avons bien reçu ta demande d\'adhésion à Nyassobi, merci !', 'nyassobi-wp-plugin'),
                __('Le conseil d\'administration va l\'étudier. Tu recevras un e-mail dès qu\'il aura voté, avec la marche à suivre pour régler ta cotisation.', 'nyassobi-wp-plugin'),
                '',
                __('À très vite,', 'nyassobi-wp-plugin'),
                __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'),
            ]
        );

        return [
            'success' => true,
            'message' => __('Le conseil d\'administration va étudier ta demande. Tu recevras sa réponse par e-mail, puis le lien pour régler ta cotisation.', 'nyassobi-wp-plugin'),
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
        if (false === file_put_contents(self::private_dir() . '/' . $name, $file['bytes'])) {
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

    public function download_parental_file(): void
    {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (! current_user_can(self::CAPABILITY) || ! check_admin_referer('nyassobi_membership_file_' . $post_id)) {
            wp_die(esc_html__('Action non autorisée.', 'nyassobi-wp-plugin'));
        }
        $path = self::parental_path($post_id);
        if (null === $path) {
            wp_die(esc_html__('Document introuvable.', 'nyassobi-wp-plugin'));
        }
        $mime = (string) get_post_meta($post_id, self::META_PARENTAL_MIME, true);
        nocache_headers();
        header('Content-Type: ' . (isset(self::PARENTAL_TYPES[$mime]) ? $mime : 'application/octet-stream'));
        header('Content-Disposition: inline; filename="autorisation-parentale-' . $post_id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . filesize($path));
        readfile($path);
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
            $accepted = self::STATUS_ACCEPTED === $status;
            $outcome = $accepted ? 'Acceptée' : (self::STATUS_REFUSED === $status ? 'Refusée' : 'Expirée sans décision');
            $embed = [
                'title' => sprintf('Demande n°%d · %s', $post_id, $outcome),
                'description' => $count_line,
                'color' => $accepted ? 0x0F9D93 : 0x87685C,
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
    private function discord_request(string $method, string $path, array $body = []): ?array
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
    private function update_discord_message(int $post_id): void
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

    private function acquire_lock(int $post_id): bool
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

    private function release_lock(int $post_id): void
    {
        delete_option('nyassobi_vote_lock_' . $post_id);
    }

    /* ------------------------------------------------------------------
     * After the decision
     * ------------------------------------------------------------------ */

    public function notify_decision(int $post_id): void
    {
        $status = (string) get_post_meta($post_id, self::META_STATUS, true);
        $email = (string) get_post_meta($post_id, self::META_EMAIL, true);
        $first_name = (string) get_post_meta($post_id, self::META_FIRST_NAME, true);
        $settings = self::get_settings();

        if (self::STATUS_ACCEPTED === $status) {
            $age = self::age_from_birth_date((string) get_post_meta($post_id, self::META_BIRTH_DATE, true));
            $reduced = '1' === get_post_meta($post_id, self::META_REDUCED_RATE, true);
            $lines = [
                sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $first_name),
                '',
                __('Bonne nouvelle : le conseil d\'administration a accepté ta demande d\'adhésion à Nyassobi !', 'nyassobi-wp-plugin'),
                '',
                sprintf(
                    __('Pour la finaliser, il reste à régler ta cotisation annuelle (%s) :', 'nyassobi-wp-plugin'),
                    $reduced ? __('tarif réduit, 15 €', 'nyassobi-wp-plugin') : __('20 €', 'nyassobi-wp-plugin')
                ),
                $settings['payment_url'] ?? '',
            ];
            if (null !== $age && $age < 18) {
                $lines[] = '';
                $lines[] = __('Nous avons bien ton autorisation parentale : le bureau la vérifie au moment de finaliser ton adhésion.', 'nyassobi-wp-plugin');
            }
            array_push($lines, '', __('Bienvenue dans la bande,', 'nyassobi-wp-plugin'), __('L\'équipe Nyassobi', 'nyassobi-wp-plugin'));
            $this->send_mail($email, __('Nyassobi : ta demande d\'adhésion est acceptée', 'nyassobi-wp-plugin'), $lines);

            if (is_email($settings['bureau_email'] ?? '')) {
                $this->send_mail(
                    $settings['bureau_email'],
                    sprintf(__('Nyassobi : demande n°%d acceptée', 'nyassobi-wp-plugin'), $post_id),
                    [
                        sprintf(__('La demande d\'adhésion n°%d a été acceptée par le CA.', 'nyassobi-wp-plugin'), $post_id),
                        __('Une fois la cotisation reçue et la personne ajoutée au registre, la finaliser (et effacer ses données en ligne) ici :', 'nyassobi-wp-plugin'),
                        admin_url('post.php?post=' . $post_id . '&action=edit'),
                    ],
                    false
                );
            }
        } elseif (self::STATUS_REFUSED === $status) {
            $this->send_mail(
                $email,
                __('Nyassobi : réponse à ta demande d\'adhésion', 'nyassobi-wp-plugin'),
                [
                    sprintf(__('Bonjour %s,', 'nyassobi-wp-plugin'), $first_name),
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
    private function send_mail(string $to, string $subject, array $lines, bool $reply_to_contact = true): void
    {
        if (! is_email($to)) {
            return;
        }
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
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

    private function status_label(string $status): string
    {
        switch ($status) {
            case self::STATUS_PENDING:
                return __('Vote en cours', 'nyassobi-wp-plugin');
            case self::STATUS_ACCEPTED:
                return __('Acceptée, à finaliser', 'nyassobi-wp-plugin');
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
        $birth_date = (string) get_post_meta($id, self::META_BIRTH_DATE, true);
        $age = self::age_from_birth_date($birth_date);
        $tally = self::tally((array) get_post_meta($id, self::META_VOTES, true));
        $rows = [
            __('Pseudo', 'nyassobi-wp-plugin') => get_post_meta($id, self::META_PSEUDO, true),
            __('Prénom', 'nyassobi-wp-plugin') => get_post_meta($id, self::META_FIRST_NAME, true),
            __('Nom', 'nyassobi-wp-plugin') => get_post_meta($id, self::META_LAST_NAME, true),
            __('Date de naissance', 'nyassobi-wp-plugin') => $birth_date . (null !== $age ? sprintf(' (%d ans%s)', $age, $age < 18 ? ', mineur·e' : '') : ''),
            __('E-mail', 'nyassobi-wp-plugin') => get_post_meta($id, self::META_EMAIL, true),
            __('Tarif', 'nyassobi-wp-plugin') => '1' === get_post_meta($id, self::META_REDUCED_RATE, true) ? __('Réduit (15 €)', 'nyassobi-wp-plugin') : __('Normal (20 €)', 'nyassobi-wp-plugin'),
            __('Statut', 'nyassobi-wp-plugin') => $this->status_label($status),
            __('Votes', 'nyassobi-wp-plugin') => sprintf(__('%1$d pour, %2$d contre, %3$d abstention(s)', 'nyassobi-wp-plugin'), $tally['pour'], $tally['contre'], $tally['abstention']),
        ];
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($rows as $label => $value) {
            printf('<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html($label), esc_html((string) $value));
        }
        if (null !== $age && $age < 18) {
            $file_url = wp_nonce_url(admin_url('admin-post.php?action=nyassobi_membership_file&post=' . $id), 'nyassobi_membership_file_' . $id);
            printf(
                '<tr><th scope="row">%s</th><td>%s</td></tr>',
                esc_html__('Autorisation parentale', 'nyassobi-wp-plugin'),
                null !== self::parental_path($id)
                    ? sprintf('<a class="button" href="%s" target="_blank" rel="noopener">%s</a>', esc_url($file_url), esc_html__('Ouvrir le document à vérifier', 'nyassobi-wp-plugin'))
                    : esc_html__('Document manquant', 'nyassobi-wp-plugin')
            );
        }
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

        if (! current_user_can(self::CAPABILITY) || ! check_admin_referer('nyassobi_membership_' . $action . '_' . $post_id)) {
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
        } elseif ('finalize' === $action && self::STATUS_ACCEPTED === $status) {
            wp_delete_post($post_id, true);
        }

        wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE));
        exit;
    }
}
