<?php
/**
 * Main plugin class for Nyassobi WP Plugin.
 *
 * @package NyassobiWPPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Nyassobi_WP_Plugin
{
    private const OPTION_NAME = 'nyassobi_wp_plugin';
    private const OPTION_GROUP = 'nyassobi_wp_plugin_group';
    private const PAGE_SLUG = 'nyassobi-wp-plugin';
    private const CAPABILITY = 'manage_options';
    private const ATELIER_POST_TYPE = 'nyassobi_atelier';
    private const ATELIER_TAXONOMY = 'atelier_type';
    private const ATELIER_ATTACHMENT_META = 'nyassobi_atelier_attachment';
    private const ATELIER_VIDEO_META = 'nyassobi_atelier_video';

    /** @var self|null */
    private static $instance = null;

    /**
     * Singleton accessor.
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor hooks.
     */
    private function __construct()
    {
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('init', [$this, 'register_ateliers']);
        add_action('add_meta_boxes', [$this, 'register_atelier_metaboxes']);
        add_action('save_post', [$this, 'save_atelier_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('graphql_register_types', [$this, 'register_graphql_types']);
        add_action('graphql_register_types', [$this, 'register_contact_mutation']);
        add_filter('the_content', [$this, 'maybe_gate_atelier_content']);
    }

    /**
     * Registers the option and its fields using the Settings API.
     */
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
            'nyassobi_wp_plugin_section',
            __('Paramètres Nyassobi', 'nyassobi-wp-plugin'),
            function (): void {
                echo '<p>' . esc_html__(
                    'Indiquez les informations qui seront utilisées par le site headless Nyassobi.',
                    'nyassobi-wp-plugin'
                ) . '</p>';
            },
            self::PAGE_SLUG
        );

        foreach ($this->get_fields_definition() as $field_key => $field) {
            add_settings_field(
                $field_key,
                esc_html($field['label']),
                [$this, 'render_field'],
                self::PAGE_SLUG,
                'nyassobi_wp_plugin_section',
                [
                    'key' => $field_key,
                    'type' => $field['type'],
                    'description' => $field['description'],
                ]
            );
        }
    }

    /**
     * Adds the Nyassobi settings page to the admin menu.
     */
    public function register_settings_page(): void
    {
        add_options_page(
            __('Paramètres Nyassobi', 'nyassobi-wp-plugin'),
            __('Paramètres Nyassobi', 'nyassobi-wp-plugin'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_settings_page']
        );
    }

    /**
     * Ensures default taxonomy terms exist for atelier types.
     */
    private function ensure_default_atelier_terms(): void
    {
        if (! taxonomy_exists(self::ATELIER_TAXONOMY)) {
            return;
        }

        $defaults = [
            'gratuit' => __('Gratuit', 'nyassobi-wp-plugin'),
            'payant' => __('Payant', 'nyassobi-wp-plugin'),
        ];

        foreach ($defaults as $slug => $label) {
            if (! term_exists($slug, self::ATELIER_TAXONOMY)) {
                wp_insert_term($label, self::ATELIER_TAXONOMY, ['slug' => $slug]);
            }
        }
    }

    /**
     * Enqueues admin assets for atelier editing.
     */
    public function enqueue_admin_assets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || self::ATELIER_POST_TYPE !== $screen->post_type) {
            return;
        }

        wp_enqueue_media();

        $script = <<<'JS'
(function ($) {
    $(document).on('click', '.nyassobi-media-button', function (event) {
        event.preventDefault();

        const targetSelector = $(this).data('target');
        const $field = $(targetSelector);
        if (!$field.length) {
            return;
        }

        const frame = wp.media({
            title: $(this).data('title') || '',
            button: { text: $(this).data('button') || '' },
            multiple: false
        });

        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            if (attachment && attachment.url) {
                $field.val(attachment.url);
            }
        });

        frame.open();
    });
})(jQuery);
JS;

        wp_add_inline_script('jquery', $script);
    }

    /**
     * Registers the Ateliers content type and taxonomy.
     */
    public function register_ateliers(): void
    {
        register_post_type(
            self::ATELIER_POST_TYPE,
            [
                'label' => __('Ateliers', 'nyassobi-wp-plugin'),
                'labels' => [
                    'name' => __('Ateliers', 'nyassobi-wp-plugin'),
                    'singular_name' => __('Atelier', 'nyassobi-wp-plugin'),
                    'add_new_item' => __('Ajouter un atelier', 'nyassobi-wp-plugin'),
                    'edit_item' => __('Modifier l\'atelier', 'nyassobi-wp-plugin'),
                    'view_item' => __('Voir l\'atelier', 'nyassobi-wp-plugin'),
                    'search_items' => __('Rechercher un atelier', 'nyassobi-wp-plugin'),
                ],
                'public' => true,
                'has_archive' => true,
                'rewrite' => ['slug' => 'ateliers'],
                'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'revisions'],
                'show_in_rest' => true,
                'show_in_graphql' => true,
                'graphql_single_name' => 'Atelier',
                'graphql_plural_name' => 'Ateliers',
                'menu_icon' => 'dashicons-welcome-learn-more',
                'menu_position' => 22,
            ]
        );

        register_taxonomy(
            self::ATELIER_TAXONOMY,
            self::ATELIER_POST_TYPE,
            [
                'label' => __('Types d\'ateliers', 'nyassobi-wp-plugin'),
                'labels' => [
                    'name' => __('Types d\'ateliers', 'nyassobi-wp-plugin'),
                    'singular_name' => __('Type d\'atelier', 'nyassobi-wp-plugin'),
                ],
                'public' => true,
                'hierarchical' => false,
                'rewrite' => ['slug' => 'type-ateliers'],
                'show_in_rest' => true,
                'show_in_graphql' => true,
                'graphql_single_name' => 'AtelierType',
                'graphql_plural_name' => 'AtelierTypes',
            ]
        );

        $this->ensure_default_atelier_terms();

        register_post_meta(
            self::ATELIER_POST_TYPE,
            self::ATELIER_ATTACHMENT_META,
            [
                'type' => 'string',
                'single' => true,
                'sanitize_callback' => 'esc_url_raw',
                'show_in_rest' => true,
                'auth_callback' => static function (): bool {
                    return current_user_can('edit_posts');
                },
            ]
        );

        register_post_meta(
            self::ATELIER_POST_TYPE,
            self::ATELIER_VIDEO_META,
            [
                'type' => 'string',
                'single' => true,
                'sanitize_callback' => 'esc_url_raw',
                'show_in_rest' => true,
                'auth_callback' => static function (): bool {
                    return current_user_can('edit_posts');
                },
            ]
        );
    }

    /**
     * Outputs the settings page markup.
     */
    public function render_settings_page(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Paramètres Nyassobi', 'nyassobi-wp-plugin'); ?></h1>
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
     * Renders a field input.
     *
     * @param array<string,string> $args Context provided by add_settings_field.
     */
    public function render_field(array $args): void
    {
        $options = self::get_settings();
        $value = $options[$args['key']] ?? '';
        $description = $args['description'] ?? '';

        if ('textarea' === $args['type']) {
            printf(
                '<textarea name="%1$s[%2$s]" id="%2$s" rows="5" class="large-text code">%3$s</textarea>',
                esc_attr(self::OPTION_NAME),
                esc_attr($args['key']),
                esc_textarea($value)
            );
        } elseif ('email' === $args['type']) {
            printf(
                '<input type="email" name="%1$s[%2$s]" id="%2$s" value="%3$s" class="regular-text" />',
                esc_attr(self::OPTION_NAME),
                esc_attr($args['key']),
                esc_attr($value)
            );
        } else {
            printf(
                '<input type="url" name="%1$s[%2$s]" id="%2$s" value="%3$s" class="regular-text code" />',
                esc_attr(self::OPTION_NAME),
                esc_attr($args['key']),
                esc_attr($value)
            );
        }

        if ($description) {
            printf(
                '<p class="description">%s</p>',
                esc_html($description)
            );
        }
    }

    /**
     * Sanitizes option values before persisting them.
     *
     * @param array<string,mixed>|null $input Raw user input.
     *
     * @return array<string,string>
     */
    public function sanitize_settings(?array $input): array
    {
        $sanitized = [];
        $fields = $this->get_fields_definition();

        foreach ($fields as $key => $field) {
            $raw_value = isset($input[$key]) ? (string) $input[$key] : '';

            if ('email' === $field['type']) {
                $sanitized[$key] = sanitize_email($raw_value);
            } elseif ('textarea' === $field['type']) {
                $sanitized[$key] = sanitize_textarea_field($raw_value);
            } else {
                $sanitized[$key] = esc_url_raw($raw_value);
            }
        }

        return array_filter(
            $sanitized,
            static function (?string $value): bool {
                // Preserve empty strings to avoid PHP < 8.1 array_filter behavior differences.
                return null !== $value;
            }
        );
    }

    /**
     * Registers GraphQL type and field when WPGraphQL is active.
     */
    public function register_graphql_types(): void
    {
        if (! function_exists('register_graphql_object_type') || ! function_exists('register_graphql_field')) {
            return;
        }

        register_graphql_object_type(
            'NyassobiSettings',
            [
                'description' => __('Paramètres Nyassobi pour le site headless.', 'nyassobi-wp-plugin'),
                'fields' => [
                    'contactEmail' => [
                        'type' => 'String',
                        'description' => __('Adresse email de contact.', 'nyassobi-wp-plugin'),
                    ],
                    'introTextNyassobi' => [
                        'type' => 'String',
                        'description' => __('Texte d\'introduction de Nyassobi.', 'nyassobi-wp-plugin'),
                    ],
                    'signupFormUrl' => [
                        'type' => 'String',
                        'description' => __('URL du formulaire d\'inscription.', 'nyassobi-wp-plugin'),
                    ],
                    'parentalAgreementUrl' => [
                        'type' => 'String',
                        'description' => __('URL pour l\'accord parental.', 'nyassobi-wp-plugin'),
                    ],
                    'associationStatusUrl' => [
                        'type' => 'String',
                        'description' => __('URL pour les statuts associatifs.', 'nyassobi-wp-plugin'),
                    ],
                    'internalRulesUrl' => [
                        'type' => 'String',
                        'description' => __('URL pour le règlement intérieur.', 'nyassobi-wp-plugin'),
                    ],
                ],
            ]
        );

        register_graphql_field(
            'RootQuery',
            'nyassobiSettings',
            [
                'type' => 'NyassobiSettings',
                'description' => __('Paramètres disponibles pour le front-end Nyassobi.', 'nyassobi-wp-plugin'),
                'resolve' => static function (): array {
                    return Nyassobi_WP_Plugin::format_settings_for_graphql();
                },
            ]
        );

        register_graphql_field(
            'Atelier',
            'attachmentUrl',
            [
                'type' => 'String',
                'description' => __('Pièce jointe (PDF/PPT) liée à l\'atelier.', 'nyassobi-wp-plugin'),
                'resolve' => function ($post): ?string {
                    $wp_post = $this->resolve_post_from_graphql_value($post);
                    if (! $wp_post) {
                        return null;
                    }

                    if ($this->is_paid_atelier($wp_post) && ! is_user_logged_in()) {
                        return null;
                    }

                    $url = get_post_meta($wp_post->ID, self::ATELIER_ATTACHMENT_META, true);
                    return $url ? (string) $url : null;
                },
            ]
        );

        register_graphql_field(
            'Atelier',
            'videoUrl',
            [
                'type' => 'String',
                'description' => __('Lien vidéo (fichier ou YouTube) pour l\'atelier.', 'nyassobi-wp-plugin'),
                'resolve' => function ($post): ?string {
                    $wp_post = $this->resolve_post_from_graphql_value($post);
                    if (! $wp_post) {
                        return null;
                    }

                    if ($this->is_paid_atelier($wp_post) && ! is_user_logged_in()) {
                        return null;
                    }

                    $url = get_post_meta($wp_post->ID, self::ATELIER_VIDEO_META, true);
                    return $url ? (string) $url : null;
                },
            ]
        );
    }

    /**
     * Registers the WPGraphQL mutation used by the React front-end contact form.
     */
    public function register_contact_mutation(): void
    {
        if (! function_exists('register_graphql_mutation')) {
            return;
        }

        register_graphql_mutation(
            'sendNyassobiContactMessage',
            [
                'inputFields' => [
                    'fullname' => [
                        'type' => ['non_null' => 'String'],
                        'description' => __('Full name of the requester.', 'nyassobi-wp-plugin'),
                    ],
                    'email' => [
                        'type' => ['non_null' => 'String'],
                        'description' => __('Email address of the requester.', 'nyassobi-wp-plugin'),
                    ],
                    'subject' => [
                        'type' => ['non_null' => 'String'],
                        'description' => __('Subject of the contact request.', 'nyassobi-wp-plugin'),
                    ],
                    'message' => [
                        'type' => ['non_null' => 'String'],
                        'description' => __('Message body provided by the requester.', 'nyassobi-wp-plugin'),
                    ],
                    'token' => [
                        'type' => 'String',
                        'description' => __('Optional anti-spam token.', 'nyassobi-wp-plugin'),
                    ],
                ],
                'outputFields' => [
                    'success' => [
                        'type' => ['non_null' => 'Boolean'],
                        'description' => __('Flag indicating whether wp_mail succeeded.', 'nyassobi-wp-plugin'),
                        'resolve' => static function ($payload, array $args = [], $context = null, $info = null): bool {
                            $payload = is_array($payload) ? $payload : [];
                            return (bool) ($payload['success'] ?? false);
                        },
                    ],
                    'message' => [
                        'type' => ['non_null' => 'String'],
                        'description' => __('Human readable status returned by the mutation.', 'nyassobi-wp-plugin'),
                        'resolve' => static function ($payload, array $args = [], $context = null, $info = null): string {
                            $payload = is_array($payload) ? $payload : [];
                            return (string) ($payload['message'] ?? '');
                        },
                    ],
                ],
                'mutateAndGetPayload' => function (array $input, $context = null, $info = null): array {
                    return $this->handle_contact_mutation($input);
                },
            ]
        );
    }

    /**
     * Validates and sends the contact email.
     *
     * @param array<string,mixed> $input Raw mutation arguments.
     *
     * @return array{success:bool,message:string}
     */
    private function handle_contact_mutation(array $input): array
    {
        $user_error_class = '\GraphQL\Error\UserError';

        if (! class_exists($user_error_class)) {
            return [
                'success' => false,
                'message' => __('GraphQL error handler is not available.', 'nyassobi-wp-plugin'),
            ];
        }

        // Sanitize user supplied values.
        $fullname = sanitize_text_field((string) ($input['fullname'] ?? ''));
        $email = sanitize_email((string) ($input['email'] ?? ''));
        $subject = sanitize_text_field((string) ($input['subject'] ?? ''));
        $message = sanitize_textarea_field((string) ($input['message'] ?? ''));
        $token = isset($input['token']) ? sanitize_text_field((string) $input['token']) : '';

        $sanitized = [
            'fullname' => $fullname,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'token' => $token,
        ];

        if ('' === $fullname) {
            throw new $user_error_class(__('The fullname field is required.', 'nyassobi-wp-plugin'));
        }

        if ('' === $subject) {
            throw new $user_error_class(__('The subject field is required.', 'nyassobi-wp-plugin'));
        }

        if ('' === $message) {
            throw new $user_error_class(__('The message field is required.', 'nyassobi-wp-plugin'));
        }

        if (! is_email($email)) {
            throw new $user_error_class(__('Please supply a valid email address.', 'nyassobi-wp-plugin'));
        }

        // TODO: plug in reCAPTCHA or nonce validation via the filter below.
        $token_valid = apply_filters('nyassobi_wp_plugin_validate_contact_token', true, $token, $sanitized, $input);
        if (! $token_valid) {
            throw new $user_error_class(__('Security validation failed.', 'nyassobi-wp-plugin'));
        }

        $settings = self::get_settings();
        $recipient = isset($settings['contact_email']) ? sanitize_email((string) $settings['contact_email']) : sanitize_email((string) get_option('admin_email'));

        $mail_subject = sprintf('[Nyassobi] %s', $subject);
        $body_lines = [
            'New Nyassobi contact request received:',
            '',
            'Full name: ' . $fullname,
            'Email: ' . $email,
            '',
            'Subject: ' . $subject,
            '',
            'Message:',
            $message,
        ];
        $body = implode("\n", $body_lines);

        // Compose headers with Reply-To so the team can answer quickly.
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        // Without an explicit sender, WordPress writes as "WordPress
        // <wordpress@admin.nyassobi.fr>": a subdomain with no SPF record,
        // which mail providers tend to file as spam.
        $sender = isset($settings['sender_email']) ? sanitize_email((string) $settings['sender_email']) : '';
        if (is_email($sender)) {
            $headers[] = sprintf('From: Nyassobi <%s>', $sender);
        }

        if ($email) {
            $reply_to_name = $fullname !== '' ? $fullname : $email;
            $headers[] = sprintf('Reply-To: %s <%s>', $reply_to_name, $email);
        }

        $headers = apply_filters('nyassobi_wp_plugin_contact_headers', $headers, $sanitized, $input);
        $body = apply_filters('nyassobi_wp_plugin_contact_body', $body, $sanitized, $input);
        $mail_subject = apply_filters('nyassobi_wp_plugin_contact_subject', $mail_subject, $sanitized, $input);
        $recipient = apply_filters('nyassobi_wp_plugin_contact_recipient', $recipient, $sanitized, $input);

        if (! is_email($recipient)) {
            throw new $user_error_class(__('No valid contact email is configured in Nyassobi settings.', 'nyassobi-wp-plugin'));
        }

        $sent = wp_mail($recipient, $mail_subject, $body, $headers);

        if (! $sent) {
            return [
                'success' => false,
                'message' => __('The message could not be sent. Please try again later.', 'nyassobi-wp-plugin'),
            ];
        }

        return [
            'success' => true,
            'message' => __('Thank you! Your message has been sent.', 'nyassobi-wp-plugin'),
        ];
    }

    /**
     * Registers atelier meta boxes for media and video links.
     */
    public function register_atelier_metaboxes(): void
    {
        add_meta_box(
            'nyassobi_atelier_assets',
            __('Ressources de l\'atelier', 'nyassobi-wp-plugin'),
            [$this, 'render_atelier_assets_metabox'],
            self::ATELIER_POST_TYPE,
            'normal',
            'default'
        );
    }

    /**
     * Renders the atelier assets metabox.
     */
    public function render_atelier_assets_metabox(\WP_Post $post): void
    {
        wp_nonce_field('nyassobi_atelier_assets_nonce', 'nyassobi_atelier_assets_nonce');

        $attachment = get_post_meta($post->ID, self::ATELIER_ATTACHMENT_META, true);
        $video = get_post_meta($post->ID, self::ATELIER_VIDEO_META, true);
        ?>
        <p>
            <label for="<?php echo esc_attr(self::ATELIER_ATTACHMENT_META); ?>">
                <?php esc_html_e('Pièce jointe (PDF/PPT)', 'nyassobi-wp-plugin'); ?>
            </label><br />
            <input type="url" class="widefat" id="<?php echo esc_attr(self::ATELIER_ATTACHMENT_META); ?>"
                   name="<?php echo esc_attr(self::ATELIER_ATTACHMENT_META); ?>"
                   value="<?php echo esc_attr((string) $attachment); ?>"
                   placeholder="<?php esc_attr_e('URL de la ressource', 'nyassobi-wp-plugin'); ?>" />
            <button type="button"
                    class="button nyassobi-media-button"
                    data-target="#<?php echo esc_attr(self::ATELIER_ATTACHMENT_META); ?>"
                    data-title="<?php echo esc_attr__('Choisir une pièce jointe', 'nyassobi-wp-plugin'); ?>"
                    data-button="<?php echo esc_attr__('Utiliser ce fichier', 'nyassobi-wp-plugin'); ?>">
                <?php esc_html_e('Téléverser / choisir un fichier', 'nyassobi-wp-plugin'); ?>
            </button>
            <em><?php esc_html_e('Uploadez votre fichier dans la médiathèque puis copiez son URL.', 'nyassobi-wp-plugin'); ?></em>
        </p>
        <p>
            <label for="<?php echo esc_attr(self::ATELIER_VIDEO_META); ?>">
                <?php esc_html_e('Vidéo (fichier ou lien YouTube)', 'nyassobi-wp-plugin'); ?>
            </label><br />
            <input type="url" class="widefat" id="<?php echo esc_attr(self::ATELIER_VIDEO_META); ?>"
                   name="<?php echo esc_attr(self::ATELIER_VIDEO_META); ?>"
                   value="<?php echo esc_attr((string) $video); ?>"
                   placeholder="<?php esc_attr_e('URL de la vidéo (MP4 ou YouTube)', 'nyassobi-wp-plugin'); ?>" />
            <button type="button"
                    class="button nyassobi-media-button"
                    data-target="#<?php echo esc_attr(self::ATELIER_VIDEO_META); ?>"
                    data-title="<?php echo esc_attr__('Choisir une vidéo', 'nyassobi-wp-plugin'); ?>"
                    data-button="<?php echo esc_attr__('Utiliser cette vidéo', 'nyassobi-wp-plugin'); ?>">
                <?php esc_html_e('Téléverser / choisir une vidéo', 'nyassobi-wp-plugin'); ?>
            </button>
            <em><?php esc_html_e('Collez le lien YouTube ou l’URL d’un fichier vidéo hébergé.', 'nyassobi-wp-plugin'); ?></em>
        </p>
        <?php
    }

    /**
     * Saves atelier meta fields.
     */
    public function save_atelier_meta(int $post_id): void
    {
        if (! isset($_POST['nyassobi_atelier_assets_nonce'])) {
            return;
        }

        if (! wp_verify_nonce((string) $_POST['nyassobi_atelier_assets_nonce'], 'nyassobi_atelier_assets_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $post_type = get_post_type($post_id);
        if (self::ATELIER_POST_TYPE !== $post_type) {
            return;
        }

        if (! current_user_can('edit_post', $post_id)) {
            return;
        }

        $attachment = isset($_POST[self::ATELIER_ATTACHMENT_META]) ? esc_url_raw((string) $_POST[self::ATELIER_ATTACHMENT_META]) : '';
        $video = isset($_POST[self::ATELIER_VIDEO_META]) ? esc_url_raw((string) $_POST[self::ATELIER_VIDEO_META]) : '';

        update_post_meta($post_id, self::ATELIER_ATTACHMENT_META, $attachment);
        update_post_meta($post_id, self::ATELIER_VIDEO_META, $video);
    }

    /**
     * Returns excerpt/CTA for paid ateliers when the visitor is not logged in.
     */
    public function maybe_gate_atelier_content(string $content): string
    {
        if (is_admin()) {
            return $content;
        }

        $post = get_post();

        if (! ($post instanceof \WP_Post) || self::ATELIER_POST_TYPE !== $post->post_type) {
            return $content;
        }

        if (! has_term('payant', self::ATELIER_TAXONOMY, $post)) {
            return $content;
        }

        if (is_user_logged_in()) {
            return $content;
        }

        $preview = has_excerpt($post)
            ? wpautop(wp_kses_post($post->post_excerpt))
            : wpautop(esc_html(wp_trim_words(wp_strip_all_tags($content), 55, '…')));

        $cta = sprintf(
            '<p><strong>%s</strong></p>',
            esc_html__('Adhérez à Nyassobi pour découvrir tous le contenu exclusif concernant les ateliers.', 'nyassobi-wp-plugin')
        );

        return $preview . $cta;
    }

    /**
     * Helper: is the atelier marked as paid?
     */
    private function is_paid_atelier(\WP_Post $post): bool
    {
        return has_term('payant', self::ATELIER_TAXONOMY, $post);
    }

    /**
     * Normalizes GraphQL resolver value to a WP_Post instance.
     *
     * @param mixed $post Resolver value (can be WP_Post, array, or WPGraphQL model).
     */
    private function resolve_post_from_graphql_value($post): ?\WP_Post
    {
        if ($post instanceof \WP_Post) {
            return $post;
        }

        $maybe_id = null;

        if (is_object($post) && isset($post->ID)) {
            $maybe_id = (int) $post->ID;
        } elseif (is_object($post) && isset($post->databaseId)) {
            $maybe_id = (int) $post->databaseId;
        } elseif (is_array($post) && isset($post['ID'])) {
            $maybe_id = (int) $post['ID'];
        } elseif (is_array($post) && isset($post['databaseId'])) {
            $maybe_id = (int) $post['databaseId'];
        }

        if ($maybe_id) {
            $wp_post = get_post($maybe_id);
            if ($wp_post instanceof \WP_Post) {
                return $wp_post;
            }
        }

        return null;
    }

    /**
     * Retrieves plugin settings as stored.
     *
     * @return array<string,string>
     */
    public static function get_settings(): array
    {
        $stored = get_option(self::OPTION_NAME, []);

        if (is_array($stored)) {
            return array_map(
                static function ($value): string {
                    return (string) $value;
                },
                $stored
            );
        }

        return [];
    }

    /**
     * Formats settings for GraphQL responses.
     *
     * @return array<string,?string>
     */
    public static function format_settings_for_graphql(): array
    {
        $options = self::get_settings();

        return [
            'contactEmail' => $options['contact_email'] ?? null,
            'introTextNyassobi' => $options['intro_text_nyassobi'] ?? null,
            'signupFormUrl' => $options['signup_form_url'] ?? null,
            'parentalAgreementUrl' => $options['parental_agreement_url'] ?? null,
            'associationStatusUrl' => $options['association_status_url'] ?? null,
            'internalRulesUrl' => $options['internal_rules_url'] ?? null,
        ];
    }

    /**
     * Field metadata.
     *
     * @return array<string,array<string,string>>
     */
    private function get_fields_definition(): array
    {
        return [
            'contact_email' => [
                'label' => __('Adresse email de contact', 'nyassobi-wp-plugin'),
                'description' => __('Email principal pour les demandes entrantes.', 'nyassobi-wp-plugin'),
                'type' => 'email',
            ],
            'sender_email' => [
                'label' => __('Adresse d\'expédition des e-mails', 'nyassobi-wp-plugin'),
                'description' => __('Adresse en @nyassobi.fr utilisée comme expéditeur (ex. contact@nyassobi.fr). Sans elle, les e-mails partent au nom de « WordPress » et finissent souvent en spam.', 'nyassobi-wp-plugin'),
                'type' => 'email',
            ],
            'intro_text_nyassobi' => [
                'label' => __('Texte d\'introduction', 'nyassobi-wp-plugin'),
                'description' => __('Texte long de présentation affiché sur le front Nyassobi.', 'nyassobi-wp-plugin'),
                'type' => 'textarea',
            ],
            'signup_form_url' => [
                'label' => __('URL du formulaire d\'inscription', 'nyassobi-wp-plugin'),
                'description' => __('Lien vers le formulaire d\'adhésion en ligne.', 'nyassobi-wp-plugin'),
                'type' => 'url',
            ],
            'parental_agreement_url' => [
                'label' => __('URL de l\'accord parental', 'nyassobi-wp-plugin'),
                'description' => __('Lien vers le document d\'accord parental.', 'nyassobi-wp-plugin'),
                'type' => 'url',
            ],
            'association_status_url' => [
                'label' => __('URL des statuts associatifs', 'nyassobi-wp-plugin'),
                'description' => __('Lien vers les statuts officiels de l\'association.', 'nyassobi-wp-plugin'),
                'type' => 'url',
            ],
            'internal_rules_url' => [
                'label' => __('URL du règlement intérieur', 'nyassobi-wp-plugin'),
                'description' => __('Lien vers le règlement intérieur actualisé.', 'nyassobi-wp-plugin'),
                'type' => 'url',
            ],
        ];
    }
}
