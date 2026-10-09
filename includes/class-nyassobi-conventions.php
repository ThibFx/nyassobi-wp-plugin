<?php
/**
 * Staff and animators for the conventions where Nyassobi holds a stand.
 *
 * Flow: the CA lists the season's conventions (in WordPress, or with slash
 * commands on Discord). Members sign in on the site with Discord, which
 * proves they hold the "Adhérent" role, and say for each convention whether
 * they can come as stand staff (with their travel time) or run an animation
 * (done remotely). Each convention has a recap message in a private Discord channel,
 * updated on every answer, so the CA can pick the best-suited people.
 *
 * Privacy: only the Discord account, the roles, travel times (never a town)
 * and free comments are kept, and everything about a convention is erased
 * 30 days after it ends, its Discord recap included.
 *
 * @package NyassobiWPPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Nyassobi_Conventions
{
    public const POST_TYPE = 'nyassobi_convention';
    /** WordPress caps post type names at 20 characters. */
    private const RESPONSE_TYPE = 'nyassobi_volontaire';

    private const META_START = '_nyassobi_conv_debut';
    private const META_END = '_nyassobi_conv_fin';
    private const META_CITY = '_nyassobi_conv_ville';
    private const META_NEEDS = '_nyassobi_conv_besoins';
    private const META_OPEN = '_nyassobi_conv_ouverte';
    private const META_MESSAGE = '_nyassobi_conv_message';
    /** Added by the CA: shown to members (site, public table)… */
    private const META_DESCRIPTION = '_nyassobi_conv_description';
    private const META_LINK = '_nyassobi_conv_lien';
    private const META_IMAGES = '_nyassobi_conv_images';
    private const META_NEWS = '_nyassobi_conv_annonces';
    /** …or kept for the CA only (organisers' recap). */
    private const META_NOTES = '_nyassobi_conv_notes';
    /** Sent by the animators the CA picked, for the communication: model images, pronouns, languages. */
    private const META_COMM = '_nyassobi_conv_comm';

    private const META_DISCORD_ID = '_nyassobi_conv_discord_id';
    private const META_NAME = '_nyassobi_conv_pseudo';
    private const META_CHOICES = '_nyassobi_conv_choix';
    private const META_ANIMATION = '_nyassobi_conv_animation';
    private const META_COMMENT = '_nyassobi_conv_commentaire';

    /** Where the public status table was posted: channel and message. */
    private const SUMMARY_OPTION = 'nyassobi_conv_tableau';
    /** The CA's panel with the "new convention" button, in the organisers' channel. */
    private const PANEL_OPTION = 'nyassobi_conv_panneau';
    private const SESSION_PREFIX = 'nyassobi_conv_session_';
    private const SESSION_SECONDS = 2 * HOUR_IN_SECONDS;
    private const SAVES_PER_HOUR = 20;
    private const RETENTION_DAYS = 30;

    /** What the convention needs, and so which roles can be offered. */
    public const NEEDS = [
        'les-deux' => 'Staff et animation',
        'staff' => 'Staff seulement',
        'animation' => 'Animation seulement',
    ];
    /** One role per person and convention: staff and animators are different people. */
    public const ROLES = [
        'staff' => 'Staff du stand',
        'animation' => 'Animation',
    ];
    /** Preferred time of day, asked of animators (animations last about an hour). */
    public const SLOTS = [
        'matin' => 'matin (10 h – 12 h)',
        'midi' => 'midi (12 h – 14 h)',
        'aprem' => 'après-midi (14 h – 16 h)',
        'fin' => 'fin de journée (16 h – 19 h)',
    ];
    public const TRAVEL = [
        '1h' => 'moins d\'1 h',
        '2h' => 'moins de 2 h',
        '4h' => 'moins de 4 h',
        'plus' => 'plus de 4 h',
    ];

    private const COMMANDS = ['convention-ajouter', 'convention-fermer', 'convention-rouvrir', 'convention-liste', 'convention-infos', 'convention-annonce', 'convention-note'];

    /** CA's decision on a volunteer, never shown to them. */
    public const STATUSES = [
        'retenu' => '✅ Retenu·e',
        'attente' => '⏳ En attente',
        'non' => '❌ Pas retenu·e',
    ];
    private const MAX_IMAGES = 6;
    private const IMAGE_MAX_BYTES = 8 * 1024 * 1024;
    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    /** Images sent on Discord are downloaded after the 3-second answer Discord expects. */
    private const ATTACHMENT_HOOK = 'nyassobi_conv_attachment';
    /** Model images for the communication: PNG only, for their transparent background. */
    private const COMM_TYPES = ['image/png' => 'png'];
    private const COMM_MAX_IMAGES = 5;
    /** Files are posted to Discord in groups below its upload limit (10 Mo without boosts). */
    private const DISCORD_UPLOAD_BYTES = 9 * 1024 * 1024;

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
        add_action('init', [$this, 'register_post_types']);
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'register_metaboxes']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_convention'], 10, 2);
        add_action('before_delete_post', [$this, 'on_delete']);
        add_action('deleted_post', [$this, 'after_change'], 10, 2);
        add_action('trashed_post', [$this, 'after_change']);
        add_action('untrashed_post', [$this, 'after_change']);
        // The table appears as soon as its channel is saved in the settings.
        add_action('update_option_nyassobi_membership', function ($old, $new): void {
            if (($old['conventions_summary_channel_id'] ?? '') !== ($new['conventions_summary_channel_id'] ?? '')) {
                $this->update_summary();
            }
            if (($old['conventions_channel_id'] ?? '') !== ($new['conventions_channel_id'] ?? '')) {
                $this->refresh_discord();
            }
        }, 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_column'], 10, 2);
        add_action('admin_notices', [$this, 'commands_notice']);
        add_action('admin_post_nyassobi_conventions_commands', [$this, 'handle_install_commands']);
        add_action('admin_post_nyassobi_conventions_export', [$this, 'export']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('graphql_register_types', [$this, 'register_graphql']);
        add_filter('nyassobi_discord_command', [$this, 'handle_command'], 10, 2);
        add_filter('nyassobi_discord_component', [$this, 'handle_component'], 10, 2);
        add_filter('nyassobi_discord_component', [$this, 'handle_volunteer'], 10, 2);
        add_filter('nyassobi_discord_component', [$this, 'handle_comm'], 10, 2);
        add_action(Nyassobi_Membership::PURGE_HOOK, [$this, 'purge']);
        add_action(self::ATTACHMENT_HOOK, [$this, 'finish_command'], 10, 3);
        // File inputs on the convention screen need a multipart form.
        add_action('post_edit_form_tag', static function (\WP_Post $post): void {
            if (self::POST_TYPE === $post->post_type) {
                echo ' enctype="multipart/form-data"';
            }
        });
    }

    /** @return array<string,string> */
    private function settings(): array
    {
        return Nyassobi_Membership::get_settings();
    }

    /* ------------------------------------------------------------------
     * Storage
     * ------------------------------------------------------------------ */

    public function register_post_types(): void
    {
        $caps = [
            'edit_post' => Nyassobi_Membership::CAP_ADHESIONS,
            'read_post' => Nyassobi_Membership::CAP_ADHESIONS,
            'delete_post' => Nyassobi_Membership::CAP_ADHESIONS,
            'edit_posts' => Nyassobi_Membership::CAP_ADHESIONS,
            'edit_others_posts' => Nyassobi_Membership::CAP_ADHESIONS,
            'delete_posts' => Nyassobi_Membership::CAP_ADHESIONS,
            'publish_posts' => Nyassobi_Membership::CAP_ADHESIONS,
            'read_private_posts' => Nyassobi_Membership::CAP_ADHESIONS,
            'create_posts' => Nyassobi_Membership::CAP_ADHESIONS,
        ];
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Conventions', 'nyassobi-wp-plugin'),
                'singular_name' => __('Convention', 'nyassobi-wp-plugin'),
                'add_new_item' => __('Ajouter une convention', 'nyassobi-wp-plugin'),
                'edit_item' => __('Convention', 'nyassobi-wp-plugin'),
                'not_found' => __('Aucune convention pour le moment.', 'nyassobi-wp-plugin'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'show_in_graphql' => false,
            'exclude_from_search' => true,
            'menu_icon' => 'dashicons-tickets-alt',
            'menu_position' => 27,
            'supports' => ['title'],
            'capabilities' => $caps,
            'map_meta_cap' => false,
        ]);
        // One per Discord account, edited through the site only.
        register_post_type(self::RESPONSE_TYPE, [
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'show_in_graphql' => false,
            'supports' => ['title'],
        ]);
    }

    private function meta(int $post_id, string $key): string
    {
        return (string) get_post_meta($post_id, $key, true);
    }

    /** @return array{id:int,name:string,city:string,start:string,end:string,needs:string,open:bool,dates:string} */
    private function convention(int $id): array
    {
        $start = $this->meta($id, self::META_START);
        $end = $this->meta($id, self::META_END) ?: $start;

        return [
            'id' => $id,
            'name' => get_the_title($id),
            'city' => $this->meta($id, self::META_CITY),
            'start' => $start,
            'end' => $end,
            'needs' => isset(self::NEEDS[$this->meta($id, self::META_NEEDS)]) ? $this->meta($id, self::META_NEEDS) : 'les-deux',
            'open' => '0' !== $this->meta($id, self::META_OPEN),
            'dates' => self::format_dates($start, $end),
        ];
    }

    /**
     * Each day of the convention, Y-m-d (a week at most).
     *
     * @return string[]
     */
    private function days(int $id): array
    {
        $c = $this->convention($id);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $c['start'], wp_timezone());
        if (! $day) {
            return [];
        }
        $days = [];
        while (count($days) < 7 && $day->format('Y-m-d') <= $c['end']) {
            $days[] = $day->format('Y-m-d');
            $day = $day->modify('+1 day');
        }

        return $days;
    }

    /** « sam. 17 » */
    private static function day_label(string $day, bool $long = false): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, wp_timezone());

        return $date ? wp_date($long ? 'l j F' : 'D j', $date->getTimestamp()) : $day;
    }

    /**
     * Conventions not over yet, soonest first.
     *
     * @return int[]
     */
    private function upcoming_ids(): array
    {
        return array_map('intval', get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 100,
            'meta_query' => [['key' => self::META_END, 'value' => wp_date('Y-m-d'), 'compare' => '>=']],
            'meta_key' => self::META_START,
            'orderby' => 'meta_value',
            'order' => 'ASC',
        ]));
    }

    private function is_upcoming(int $id): bool
    {
        return self::POST_TYPE === get_post_type($id) && 'publish' === get_post_status($id)
            && ($this->meta($id, self::META_END) ?: $this->meta($id, self::META_START)) >= wp_date('Y-m-d');
    }

    /** « 3 et 4 octobre 2026 », « 31 octobre et 1er novembre 2026 ». */
    public static function format_dates(string $start, string $end): string
    {
        $tz = wp_timezone();
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, $tz);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, $tz) ?: $a;
        if (! $a) {
            return '';
        }
        $day = static fn (\DateTimeImmutable $d): string => '1' === $d->format('j') ? '1er' : $d->format('j');
        $month = static fn (\DateTimeImmutable $d): string => wp_date('F', $d->getTimestamp(), $tz);
        if ($a == $b) {
            return $day($a) . ' ' . $month($a) . ' ' . $a->format('Y');
        }
        $joint = 1 === (int) $a->diff($b)->days ? ' et ' : ' au ';
        if ($a->format('Y-m') === $b->format('Y-m')) {
            return $day($a) . $joint . $day($b) . ' ' . $month($b) . ' ' . $b->format('Y');
        }
        if ($a->format('Y') === $b->format('Y')) {
            return $day($a) . ' ' . $month($a) . $joint . $day($b) . ' ' . $month($b) . ' ' . $b->format('Y');
        }

        return $day($a) . ' ' . $month($a) . ' ' . $a->format('Y') . $joint . $day($b) . ' ' . $month($b) . ' ' . $b->format('Y');
    }

    /** « 03/10/2026 », « 3/10 » (next occurrence) or « 2026-10-03 » → 2026-10-03. */
    public static function parse_date(string $text): ?string
    {
        $text = trim($text);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $text, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})(?:[/.-](\d{2,4}))?$#', $text, $m)) {
            [$d, $mo] = [(int) $m[1], (int) $m[2]];
            $y = isset($m[3]) ? (int) $m[3] : (int) wp_date('Y');
            if ($y < 100) {
                $y += 2000;
            }
            if (! isset($m[3]) && sprintf('%04d-%02d-%02d', $y, $mo, $d) < wp_date('Y-m-d')) {
                ++$y;
            }
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * @return int|string The new convention, or an error message.
     */
    public function create(string $name, string $city, string $start, string $end, string $needs)
    {
        $name = sanitize_text_field($name);
        $city = sanitize_text_field($city);
        $start_date = self::parse_date($start);
        $end_date = '' === trim($end) ? $start_date : self::parse_date($end);
        if ('' === $name || mb_strlen($name) > 80 || mb_strlen($city) > 80) {
            return __('Le nom (et la ville) doivent faire moins de 80 caractères.', 'nyassobi-wp-plugin');
        }
        if (null === $start_date || null === $end_date) {
            return __('Date illisible : écris-la comme 03/10/2026.', 'nyassobi-wp-plugin');
        }
        if ($end_date < $start_date) {
            return __('La date de fin est avant la date de début.', 'nyassobi-wp-plugin');
        }
        if ($end_date < wp_date('Y-m-d')) {
            return __('Cette convention est déjà passée.', 'nyassobi-wp-plugin');
        }
        $id = wp_insert_post(['post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_title' => $name], true);
        if (is_wp_error($id)) {
            return __('La convention n\'a pas pu être enregistrée.', 'nyassobi-wp-plugin');
        }
        // Saved by hand: save_convention() only reads the admin form.
        update_post_meta($id, self::META_START, $start_date);
        update_post_meta($id, self::META_END, $end_date);
        update_post_meta($id, self::META_CITY, $city);
        update_post_meta($id, self::META_NEEDS, isset(self::NEEDS[$needs]) ? $needs : 'les-deux');
        update_post_meta($id, self::META_OPEN, '1');
        $this->update_recap((int) $id);
        $this->update_summary();

        return (int) $id;
    }

    private function set_open(int $id, bool $open): void
    {
        update_post_meta($id, self::META_OPEN, $open ? '1' : '0');
        $this->update_recap($id);
        $this->update_summary();
    }

    /* ------------------------------------------------------------------
     * Answers
     * ------------------------------------------------------------------ */

    private function response_id(string $discord_id): int
    {
        $ids = get_posts([
            'post_type' => self::RESPONSE_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [['key' => self::META_DISCORD_ID, 'value' => $discord_id]],
        ]);

        return $ids ? (int) $ids[0] : 0;
    }

    /** @return array<int,array{role:string,travel:string,transport:string}> */
    private function choices(int $response_id): array
    {
        $choices = get_post_meta($response_id, self::META_CHOICES, true);

        return is_array($choices) ? $choices : [];
    }

    /**
     * Everyone who offered to help at this convention.
     *
     * @return array<int,array{discord_id:string,name:string,role:string,travel:string,transport:string,days:string[],slots:string[],animation:string,comment:string,since:int}>
     */
    private function volunteers(int $convention_id): array
    {
        $list = [];
        foreach (get_posts(['post_type' => self::RESPONSE_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1]) as $rid) {
            $rid = (int) $rid;
            $choice = $this->choices($rid)[$convention_id] ?? null;
            if (null === $choice) {
                continue;
            }
            $list[] = [
                'discord_id' => $this->meta($rid, self::META_DISCORD_ID),
                'name' => $this->meta($rid, self::META_NAME),
                'role' => (string) $choice['role'],
                'travel' => (string) $choice['travel'],
                'transport' => (string) $choice['transport'],
                'days' => array_values((array) ($choice['days'] ?? $this->days($convention_id))),
                'slots' => array_values((array) ($choice['slots'] ?? [])),
                'animation' => $this->meta($rid, self::META_ANIMATION),
                'comment' => $this->meta($rid, self::META_COMMENT),
                // First answer first: correcting a typo must not send someone to the bottom.
                'since' => (int) get_post_time('U', true, $rid),
            ];
        }
        usort($list, static fn ($a, $b) => $a['since'] <=> $b['since']);

        return $list;
    }

    /**
     * @param array<string,mixed>       $session
     * @param array<int,array<string,mixed>> $raw_choices
     *
     * @return string|null Error message, or null once saved.
     */
    private function save_response(array $session, array $raw_choices, string $animation, string $comment): ?string
    {
        // A double click sends two saves at once: without a lock, both see
        // "no answer yet" and the person ends up listed twice.
        $lock = 'nyassobi_conv_lock_' . $session['id'];
        for ($try = 0; ! add_option($lock, (string) time(), '', false); ++$try) {
            if ((int) get_option($lock) < time() - 10) {
                delete_option($lock);
            } elseif ($try >= 20) {
                return __('Un enregistrement est déjà en cours : réessaie dans quelques secondes.', 'nyassobi-wp-plugin');
            } else {
                usleep(150000);
            }
        }
        try {
            return $this->save_response_locked($session, $raw_choices, $animation, $comment);
        } finally {
            delete_option($lock);
        }
    }

    /**
     * @param array<string,mixed>            $session
     * @param array<int,array<string,mixed>> $raw_choices
     */
    private function save_response_locked(array $session, array $raw_choices, string $animation, string $comment): ?string
    {
        $discord_id = (string) $session['id'];
        $rate = 'nyassobi_conv_saves_' . $discord_id;
        if ((int) get_transient($rate) >= self::SAVES_PER_HOUR) {
            return __('Beaucoup d\'enregistrements d\'un coup : réessaie dans une heure.', 'nyassobi-wp-plugin');
        }
        set_transient($rate, (int) get_transient($rate) + 1, HOUR_IN_SECONDS);

        $response_id = $this->response_id($discord_id);
        $before = $response_id ? $this->choices($response_id) : [];
        $choices = [];
        foreach ($raw_choices as $raw) {
            $cid = (int) ($raw['conventionId'] ?? 0);
            if (isset($choices[$cid])) {
                continue;
            }
            if (! $this->is_upcoming($cid)) {
                return __('Une des conventions n\'existe plus : recharge la page.', 'nyassobi-wp-plugin');
            }
            $convention = $this->convention($cid);
            // A closed convention keeps the people already in, but takes no one new.
            if (! $convention['open'] && ! isset($before[$cid])) {
                return sprintf(__('L\'équipe de %s est déjà complète.', 'nyassobi-wp-plugin'), $convention['name']);
            }
            $role = (string) ($raw['role'] ?? '');
            $allowed = 'les-deux' === $convention['needs'] ? array_keys(self::ROLES) : [$convention['needs']];
            if (! in_array($role, $allowed, true)) {
                return sprintf(__('Choisis ton rôle pour %s.', 'nyassobi-wp-plugin'), $convention['name']);
            }
            // Animations are done remotely: no travel for them.
            $travel = 'staff' === $role ? (string) ($raw['travel'] ?? '') : '';
            if ('staff' === $role && ! isset(self::TRAVEL[$travel])) {
                return sprintf(__('Indique ton temps de trajet pour %s.', 'nyassobi-wp-plugin'), $convention['name']);
            }
            $transport = 'staff' === $role ? sanitize_text_field((string) ($raw['transport'] ?? '')) : '';
            if (mb_strlen($transport) > 80) {
                return __('Le moyen de transport doit tenir en 80 caractères.', 'nyassobi-wp-plugin');
            }
            // A one-day convention needs no choice of day.
            $all_days = $this->days($cid);
            $days = 1 === count($all_days) ? $all_days : array_values(array_intersect($all_days, array_map('strval', (array) ($raw['days'] ?? []))));
            if (! $days) {
                return sprintf(__('Coche le ou les jours où tu peux venir à %s.', 'nyassobi-wp-plugin'), $convention['name']);
            }
            $slots = 'animation' === $role ? array_values(array_intersect(array_keys(self::SLOTS), array_map('strval', (array) ($raw['slots'] ?? [])))) : [];
            if ('animation' === $role && ! $slots) {
                return sprintf(__('Indique tes horaires préférés pour animer à %s.', 'nyassobi-wp-plugin'), $convention['name']);
            }
            $choices[$cid] = ['role' => $role, 'travel' => $travel, 'transport' => $transport, 'days' => $days, 'slots' => $slots];
        }

        $animation = sanitize_textarea_field($animation);
        $comment = sanitize_textarea_field($comment);
        if (mb_strlen($animation) > 1000 || mb_strlen($comment) > 1000) {
            return __('La description et le commentaire doivent tenir en 1 000 caractères chacun.', 'nyassobi-wp-plugin');
        }
        $animates = (bool) array_filter($choices, static fn ($c) => 'staff' !== $c['role']);
        if ($animates && '' === trim($animation)) {
            return __('Décris en quelques mots l\'animation que tu proposes.', 'nyassobi-wp-plugin');
        }

        if (! $choices) {
            // Nothing ticked: the answer is withdrawn, not kept empty.
            if ($response_id) {
                wp_delete_post($response_id, true);
            }
        } else {
            if (! $response_id) {
                $response_id = wp_insert_post(['post_type' => self::RESPONSE_TYPE, 'post_status' => 'private', 'post_title' => 'Réponse conventions'], true);
                if (is_wp_error($response_id) || ! $response_id) {
                    return __('La réponse n\'a pas pu être enregistrée. Réessaie plus tard.', 'nyassobi-wp-plugin');
                }
                update_post_meta($response_id, self::META_DISCORD_ID, $discord_id);
            }
            update_post_meta($response_id, self::META_NAME, (string) $session['name']);
            update_post_meta($response_id, self::META_CHOICES, $choices);
            update_post_meta($response_id, self::META_ANIMATION, $animates ? $animation : '');
            update_post_meta($response_id, self::META_COMMENT, $comment);
        }

        foreach (array_diff(array_keys($before), array_keys($choices)) as $cid) {
            $notes = $this->notes((int) $cid);
            unset($notes[$discord_id]);
            update_post_meta((int) $cid, self::META_NOTES, $notes);
            $this->forget_comm((int) $cid, $discord_id);
        }
        foreach (array_unique(array_merge(array_keys($before), array_keys($choices))) as $cid) {
            $this->update_recap((int) $cid);
        }
        $this->update_summary();

        return null;
    }

    /** « sam. 17, dim. 18 » or « les 2 jours ». */
    private function days_text(int $id, array $days): string
    {
        if (count($this->days($id)) <= 1) {
            return '';
        }
        if (count($days) === count($this->days($id))) {
            return sprintf('les %d jours', count($days));
        }

        return implode(', ', array_map([self::class, 'day_label'], $days));
    }

    /** « 🚗 moins de 2 h, train » for the stand, « 💻 à distance » for an animation. */
    private static function travel_text(array $person, bool $markdown = true): string
    {
        if ('staff' !== $person['role']) {
            return '💻 à distance';
        }
        $transport = (string) ($person['transport'] ?? '');

        return '🚗 ' . (self::TRAVEL[$person['travel']] ?? $person['travel']) . ('' !== $transport ? ', ' . ($markdown ? Nyassobi_Membership::escape_markdown($transport) : $transport) : '');
    }

    /** « matin, après-midi » */
    private static function slots_text(array $slots): string
    {
        return implode(', ', array_map(static fn (string $s): string => explode(' (', self::SLOTS[$s] ?? $s)[0], $slots));
    }

    /**
     * Staff and animators per day, for conventions of several days.
     *
     * @return string « sam. 17 : 3 staff, 1 animation · dim. 18 : … »
     */
    private function per_day(int $id, array $people): string
    {
        $days = $this->days($id);
        if (count($days) <= 1) {
            return '';
        }
        $parts = [];
        foreach ($days as $day) {
            $here = array_filter($people, static fn ($p) => in_array($day, $p['days'], true));
            $parts[] = sprintf('%s : %d staff, %d anim.', self::day_label($day), count(array_filter($here, static fn ($p) => 'staff' === $p['role'])), count(array_filter($here, static fn ($p) => 'animation' === $p['role'])));
        }

        return implode(' · ', $parts);
    }

    /* ------------------------------------------------------------------
     * Discord recap
     * ------------------------------------------------------------------ */

    /** @return array<string,mixed> */
    private function recap_message(int $id): array
    {
        $c = $this->convention($id);
        $people = $this->volunteers($id);
        $md = static fn (string $t): string => Nyassobi_Membership::escape_markdown($t);
        $short = static fn (string $t, int $n): string => mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;

        $staff = count(array_filter($people, static fn ($p) => 'animation' !== $p['role']));
        $anim = count(array_filter($people, static fn ($p) => 'staff' !== $p['role']));
        $notes = $this->notes($id);
        $kept = count(array_filter($people, static fn ($p) => 'retenu' === ($notes[$p['discord_id']]['status'] ?? '')));
        $head = sprintf("📅 %s%s · Besoin : %s\n**%d volontaire%s** · staff : %d · animation : %d · retenus : %d",
            $c['dates'],
            '' !== $c['city'] ? ' · 📍 ' . $md($c['city']) : '',
            mb_strtolower(self::NEEDS[$c['needs']]),
            count($people),
            count($people) > 1 ? 's' : '',
            $staff,
            $anim,
            $kept
        );
        if ('' !== $this->per_day($id, $people)) {
            $head .= "\n📆 " . $this->per_day($id, $people);
        }
        if (count($people) > 100) {
            $head .= "\nLes menus s'arrêtent à 100 volontaires : les suivants se notent dans WordPress.";
        }

        $comm = $this->comm($id);
        $blocks = [];
        foreach ($people as $p) {
            $note = $notes[$p['discord_id']] ?? [];
            $block = sprintf('%s**%s** <@%s> · %s%s · %s%s%s%s',
                isset(self::STATUSES[$note['status'] ?? '']) ? mb_substr(self::STATUSES[$note['status']], 0, 1) . ' ' : '',
                $md($p['name']),
                $p['discord_id'],
                self::ROLES[$p['role']] ?? $p['role'],
                '' !== $this->days_text($id, $p['days']) ? ' · 📅 ' . $this->days_text($id, $p['days']) : '',
                self::travel_text($p),
                $p['slots'] ? ' · 🕐 ' . self::slots_text($p['slots']) : '',
                ! empty($note['notified']) ? ' · ✉️ prévenu·e' : '',
                isset($comm[$p['discord_id']]) ? ' · 🖼️ comm reçue' : ('retenu' === ($note['status'] ?? '') && 'animation' === $p['role'] ? ' · 🖼️ comm en attente' : '')
            );
            if ('staff' !== $p['role'] && '' !== $p['animation']) {
                $block .= "\n> 🎤 " . str_replace("\n", ' ', $md($short($p['animation'], 300)));
            }
            if ('' !== $p['comment']) {
                $block .= "\n> 💬 " . str_replace("\n", ' ', $md($short($p['comment'], 300)));
            }
            if ('' !== ($note['note'] ?? '')) {
                $block .= "\n> 📝 CA : " . str_replace("\n", ' ', $md($short($note['note'], 300)));
            }
            $blocks[] = $block;
        }

        // Discord caps an embed at 4 096 characters: the rest is in WordPress.
        $body = $head;
        foreach ($blocks as $i => $block) {
            if (mb_strlen($body . "\n\n" . $block) > 3900) {
                $body .= sprintf("\n\n… et %d autre(s) : voir la convention dans WordPress.", count($blocks) - $i);
                break;
            }
            $body .= "\n\n" . $block;
        }
        if (! $people) {
            $body .= "\n\nPas encore de volontaire.";
        }

        return [
            'embeds' => [[
                'title' => '🎪 ' . $short($c['name'], 200),
                'description' => $body,
                'color' => $c['open'] ? 0xE8622F : 0x87685C,
                'footer' => ['text' => ($c['open'] ? 'Inscriptions ouvertes' : 'Équipe complète, inscriptions fermées') . ' · effacé 30 jours après la convention'],
            ]],
            // Mentions show who each person is without pinging anyone.
            'allowed_mentions' => ['parse' => []],
            'components' => $this->recap_components($id),
        ];
    }

    /**
     * Buttons under an organisers' recap, so the CA never has to type a
     * command: infos, announcement, closing, and a menu to rate volunteers.
     *
     * @return array<int,array<string,mixed>>
     */
    private function recap_components(int $id): array
    {
        $c = $this->convention($id);
        $rows = [[
            'type' => 1,
            'components' => [
                ['type' => 2, 'style' => 2, 'label' => '📝 Infos', 'custom_id' => 'nyconv:infos:' . $id],
                ['type' => 2, 'style' => 2, 'label' => '📣 Annonce', 'custom_id' => 'nyconv:news:' . $id],
                ['type' => 2, 'style' => $c['open'] ? 2 : 3, 'label' => $c['open'] ? '🔒 Fermer les inscriptions' : '🔓 Rouvrir les inscriptions', 'custom_id' => 'nyconv:toggle:' . $id],
                ['type' => 2, 'style' => 4, 'label' => '🗑️ Supprimer', 'custom_id' => 'nyconv:delete:' . $id],
            ],
        ]];
        $people = $this->volunteers($id);
        $notes = $this->notes($id);
        // Discord: 25 choices per menu, 5 rows per message. The buttons take
        // one row, so up to four menus: 100 volunteers.
        $chunks = array_chunk(array_slice($people, 0, 100), 25);
        foreach ($chunks as $n => $chunk) {
            $options = [];
            foreach ($chunk as $p) {
                $status = self::STATUSES[$notes[$p['discord_id']]['status'] ?? ''] ?? '';
                $options[] = [
                    'label' => mb_substr($p['name'], 0, 100) ?: 'Sans pseudo',
                    'value' => $p['discord_id'],
                    'description' => mb_substr((self::ROLES[$p['role']] ?? '') . ('' !== $this->days_text($id, $p['days']) ? ' · ' . $this->days_text($id, $p['days']) : '') . ' · ' . ('staff' === $p['role'] ? (self::TRAVEL[$p['travel']] ?? '') : 'à distance') . ('' !== $status ? ' · ' . $status : ''), 0, 100),
                ];
            }
            $placeholder = count($chunks) > 1
                ? sprintf('Noter un volontaire (%d à %d)…', $n * 25 + 1, $n * 25 + count($chunk))
                : 'Noter un volontaire…';
            $rows[] = ['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'nyconv:pick:' . $id . ':' . $n, 'placeholder' => $placeholder, 'options' => $options]]];
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function panel_message(): array
    {
        return [
            'embeds' => [[
                'title' => '🎪 Conventions · panneau du CA',
                'description' => "Ajoute une convention avec le bouton ci-dessous : un formulaire s'ouvre.\n\n"
                    . "Sous chaque récapitulatif : **📝 Infos** (description, lien, affiche et photos, visibles des adhérents), **📣 Annonce** (avec image si besoin), **🔒 Fermer / 🔓 Rouvrir**, **🗑️ Supprimer**, et le menu **Noter un volontaire…** (décision et note, visibles du CA seulement).\n\n"
                    . "Les animateurs retenus et prévenus reçoivent un bouton pour envoyer leurs infos pour la comm (images PNG du model, pronoms, langues) : elles arrivent dans ce salon, sous le récapitulatif.",
                'color' => 0xE8622F,
            ]],
            'components' => [[
                'type' => 1,
                'components' => [
                    ['type' => 2, 'style' => 1, 'label' => '➕ Nouvelle convention', 'custom_id' => 'nyconv:add'],
                    ['type' => 2, 'style' => 2, 'label' => '📋 Liste', 'custom_id' => 'nyconv:list'],
                ],
            ]],
        ];
    }

    /** The panel, then every recap again so they get their buttons. */
    public function refresh_discord(): void
    {
        $channel = (string) ($this->settings()['conventions_channel_id'] ?? '');
        if ('' === $channel) {
            return;
        }
        $discord = Nyassobi_Membership::instance();
        $posted = (array) get_option(self::PANEL_OPTION, []);
        $patched = $channel === ($posted['channel'] ?? '') && '' !== ($posted['message'] ?? '')
            && null !== $discord->discord_request('PATCH', '/channels/' . rawurlencode($channel) . '/messages/' . rawurlencode((string) $posted['message']), $this->panel_message());
        if (! $patched) {
            $new = $discord->discord_request('POST', '/channels/' . rawurlencode($channel) . '/messages', $this->panel_message());
            if (isset($new['id'])) {
                update_option(self::PANEL_OPTION, ['channel' => $channel, 'message' => (string) $new['id']], false);
                // Pinned so it stays easy to find; needs "Manage messages", optional.
                $discord->discord_request('PUT', '/channels/' . rawurlencode($channel) . '/pins/' . rawurlencode((string) $new['id']));
            }
        }
        foreach ($this->upcoming_ids() as $id) {
            $this->update_recap($id);
        }
    }

    public function update_recap(int $id): void
    {
        $channel = (string) ($this->settings()['conventions_channel_id'] ?? '');
        if ('' === $channel || ! $this->is_upcoming($id)) {
            return;
        }
        $discord = Nyassobi_Membership::instance();
        $message_id = $this->meta($id, self::META_MESSAGE);
        if ('' !== $message_id && null !== $discord->discord_request('PATCH', '/channels/' . rawurlencode($channel) . '/messages/' . rawurlencode($message_id), $this->recap_message($id))) {
            return;
        }
        // No message yet, or it was deleted by hand: post a new one.
        $posted = $discord->discord_request('POST', '/channels/' . rawurlencode($channel) . '/messages', $this->recap_message($id));
        if (isset($posted['id'])) {
            update_post_meta($id, self::META_MESSAGE, (string) $posted['id']);
        }
    }

    /**
     * @param int|\WP_Post|null $post
     */
    public function after_change(int $post_id, $post = null): void
    {
        $type = $post instanceof \WP_Post ? $post->post_type : get_post_type($post_id);
        if (self::POST_TYPE === $type) {
            $this->update_summary();
        }
    }

    /**
     * The status table everyone can see: per convention, how many staff and
     * animators offered to come, and whether the team is complete. No names:
     * those stay in the organisers' recap.
     *
     * @return array<string,mixed>
     */
    private function summary_message(): array
    {
        $md = static fn (string $t): string => Nyassobi_Membership::escape_markdown($t);
        $lines = [];
        $any_open = false;
        foreach ($this->upcoming_ids() as $id) {
            $c = $this->convention($id);
            $people = $this->volunteers($id);
            $counts = [];
            if ('animation' !== $c['needs']) {
                $counts[] = '👥 Staff : ' . count(array_filter($people, static fn ($p) => 'staff' === $p['role']));
            }
            if ('staff' !== $c['needs']) {
                $counts[] = '🎤 Animation : ' . count(array_filter($people, static fn ($p) => 'animation' === $p['role']));
            }
            if ('' !== $this->per_day($id, $people)) {
                $counts[] = '📆 ' . $this->per_day($id, $people);
            }
            $counts[] = $c['open'] ? '✅ Inscriptions ouvertes' : '🔒 Équipe complète';
            if ('' !== $this->meta($id, self::META_LINK)) {
                $counts[] = '🔗 [Site de la convention](' . $this->meta($id, self::META_LINK) . ')';
            }
            $any_open = $any_open || $c['open'];
            $lines[] = sprintf("**%s** · %s%s\n%s", $md($c['name']), $c['dates'], '' !== $c['city'] ? ' · ' . $md($c['city']) : '', implode(' · ', $counts));
        }

        $body = '';
        foreach ($lines as $i => $line) {
            if (mb_strlen($body . "\n\n" . $line) > 3700) {
                $body .= sprintf("\n\n… et %d autre(s) convention(s).", count($lines) - $i);
                break;
            }
            $body .= ('' === $body ? '' : "\n\n") . $line;
        }
        if ('' === $body) {
            $body = 'Aucune convention à venir pour le moment.';
        } elseif ($any_open) {
            $body .= "\n\nTu veux aider sur le stand ou proposer une animation ? Choisis la convention dans le menu ci-dessous, ou va sur " . $this->page_url();
        }

        $options = [];
        foreach ($this->upcoming_ids() as $id) {
            $c = $this->convention($id);
            if ($c['open'] && count($options) < 25) {
                $options[] = ['label' => mb_substr($c['name'], 0, 100), 'value' => (string) $id, 'description' => mb_substr($c['dates'] . ('' !== $c['city'] ? ' · ' . $c['city'] : ''), 0, 100)];
            }
        }

        return [
            'embeds' => [[
                'title' => '🎪 Conventions à venir',
                'description' => $body,
                'color' => 0xE8622F,
                'footer' => ['text' => 'Mis à jour automatiquement'],
            ]],
            'allowed_mentions' => ['parse' => []],
            'components' => $options ? [['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'nyvol:pick', 'placeholder' => '🙋 Me proposer pour une convention…', 'options' => $options]]]] : [],
        ];
    }

    public function update_summary(): void
    {
        $channel = (string) ($this->settings()['conventions_summary_channel_id'] ?? '');
        if ('' === $channel) {
            return;
        }
        $discord = Nyassobi_Membership::instance();
        $posted = (array) get_option(self::SUMMARY_OPTION, []);
        if ($channel === ($posted['channel'] ?? '') && '' !== ($posted['message'] ?? '')
            && null !== $discord->discord_request('PATCH', '/channels/' . rawurlencode($channel) . '/messages/' . rawurlencode((string) $posted['message']), $this->summary_message())) {
            return;
        }
        // First time, new channel, or the message was deleted by hand.
        $new = $discord->discord_request('POST', '/channels/' . rawurlencode($channel) . '/messages', $this->summary_message());
        if (isset($new['id'])) {
            update_option(self::SUMMARY_OPTION, ['channel' => $channel, 'message' => (string) $new['id']], false);
        }
    }

    /* ------------------------------------------------------------------
     * Slash commands, for the CA
     * ------------------------------------------------------------------ */

    /** @return array<int,array<string,mixed>> */
    private function command_definitions(): array
    {
        $convention = [['type' => 3, 'name' => 'convention', 'description' => 'La convention', 'required' => true, 'autocomplete' => true]];
        $needs = [];
        foreach (self::NEEDS as $value => $label) {
            $needs[] = ['name' => $label, 'value' => $value];
        }

        return [
            [
                'name' => 'convention-ajouter',
                'description' => 'Ajouter une convention où Nyassobi cherche du staff ou des animateurs',
                'options' => [
                    ['type' => 3, 'name' => 'nom', 'description' => 'Nom de la convention', 'required' => true, 'max_length' => 80],
                    ['type' => 3, 'name' => 'debut', 'description' => 'Premier jour, par exemple 03/10/2026', 'required' => true],
                    ['type' => 3, 'name' => 'ville', 'description' => 'Ville', 'required' => true, 'max_length' => 80],
                    ['type' => 3, 'name' => 'fin', 'description' => 'Dernier jour, si plus d\'une journée', 'required' => false],
                    ['type' => 3, 'name' => 'besoins', 'description' => 'Ce qu\'on cherche (staff et animation par défaut)', 'required' => false, 'choices' => $needs],
                ],
            ],
            ['name' => 'convention-fermer', 'description' => 'Équipe complète : ne plus accepter de volontaires', 'options' => $convention],
            ['name' => 'convention-rouvrir', 'description' => 'Accepter à nouveau des volontaires', 'options' => $convention],
            ['name' => 'convention-liste', 'description' => 'Les conventions à venir et leurs volontaires'],
            [
                'name' => 'convention-infos',
                'description' => 'Infos visibles des adhérents : description, lien, affiche ou photos',
                'options' => array_merge($convention, [
                    ['type' => 3, 'name' => 'texte', 'description' => 'Description (remplace la précédente ; « - » pour l\'effacer)', 'required' => false, 'max_length' => 2000],
                    ['type' => 3, 'name' => 'lien', 'description' => 'Site de la convention (« - » pour l\'effacer)', 'required' => false, 'max_length' => 300],
                    ['type' => 11, 'name' => 'image', 'description' => 'Affiche ou photo à ajouter', 'required' => false],
                    ['type' => 5, 'name' => 'retirer-images', 'description' => 'Retirer toutes les images', 'required' => false],
                ]),
            ],
            [
                'name' => 'convention-annonce',
                'description' => 'Publier une annonce pour les adhérents (page du site et salon public)',
                'options' => array_merge($convention, [
                    ['type' => 3, 'name' => 'texte', 'description' => 'L\'annonce', 'required' => true, 'max_length' => 1500],
                    ['type' => 11, 'name' => 'image', 'description' => 'Image jointe', 'required' => false],
                    ['type' => 5, 'name' => 'mentionner', 'description' => 'Mentionner le rôle Adhérent (oui par défaut)', 'required' => false],
                ]),
            ],
            [
                'name' => 'convention-note',
                'description' => 'Statut et note du CA sur un volontaire (visible du CA seulement)',
                'options' => array_merge($convention, [
                    ['type' => 6, 'name' => 'personne', 'description' => 'Le volontaire', 'required' => true],
                    ['type' => 3, 'name' => 'statut', 'description' => 'Décision', 'required' => false, 'choices' => array_map(static fn ($v, $l) => ['name' => $l, 'value' => $v], array_keys(self::STATUSES), self::STATUSES)],
                    ['type' => 3, 'name' => 'note', 'description' => 'Note (« - » pour l\'effacer)', 'required' => false, 'max_length' => 300],
                ]),
            ],
        ];
    }

    public function install_commands(): bool
    {
        $s = $this->settings();
        if ('' === ($s['discord_application_id'] ?? '') || '' === ($s['discord_guild_id'] ?? '')) {
            return false;
        }

        return null !== Nyassobi_Membership::instance()->discord_request(
            'PUT',
            '/applications/' . rawurlencode($s['discord_application_id']) . '/guilds/' . rawurlencode($s['discord_guild_id']) . '/commands',
            $this->command_definitions()
        );
    }

    /**
     * @param \WP_REST_Response|null $response
     * @param array<string,mixed>     $payload
     *
     * @return \WP_REST_Response|null
     */
    public function handle_command($response, array $payload)
    {
        $name = (string) ($payload['data']['name'] ?? '');
        if (null !== $response || ! in_array($name, self::COMMANDS, true)) {
            return $response;
        }
        $membership = Nyassobi_Membership::instance();
        $roles = (array) ($payload['member']['roles'] ?? []);
        if (! in_array($this->settings()['discord_board_role_id'] ?? '', $roles, true)) {
            return $membership->ephemeral(__('Seuls les membres du CA peuvent gérer les conventions.', 'nyassobi-wp-plugin'));
        }
        $options = [];
        $focused = '';
        foreach ((array) ($payload['data']['options'] ?? []) as $option) {
            $options[(string) $option['name']] = (string) ($option['value'] ?? '');
            if (! empty($option['focused'])) {
                $focused = (string) ($option['value'] ?? '');
            }
        }

        // Type 4: suggest conventions while the name is being typed.
        if (4 === (int) ($payload['type'] ?? 0)) {
            $want_open = ['convention-fermer' => true, 'convention-rouvrir' => false][$name] ?? null;
            $choices = [];
            foreach ($this->upcoming_ids() as $id) {
                $c = $this->convention($id);
                if ((null === $want_open || $c['open'] === $want_open) && ('' === $focused || false !== mb_stripos($c['name'], $focused))) {
                    $choices[] = ['name' => mb_substr($c['name'] . ' · ' . $c['dates'], 0, 100), 'value' => (string) $id];
                }
            }

            return new \WP_REST_Response(['type' => 8, 'data' => ['choices' => array_slice($choices, 0, 25)]], 200);
        }

        switch ($name) {
            case 'convention-ajouter':
                $id = $this->create($options['nom'] ?? '', $options['ville'] ?? '', $options['debut'] ?? '', $options['fin'] ?? '', $options['besoins'] ?? 'les-deux');
                if (is_string($id)) {
                    return $membership->ephemeral($id);
                }
                $c = $this->convention($id);
                return $membership->ephemeral(sprintf(__('Convention ajoutée : %1$s, %2$s. Elle apparaît sur la page Conventions du site, et son récapitulatif dans le salon des orgas.', 'nyassobi-wp-plugin'), $c['name'], $c['dates']));

            case 'convention-fermer':
            case 'convention-rouvrir':
                $id = (int) ($options['convention'] ?? 0);
                if (! $this->is_upcoming($id)) {
                    return $membership->ephemeral(__('Convention introuvable : choisis-la dans la liste proposée.', 'nyassobi-wp-plugin'));
                }
                $open = 'convention-rouvrir' === $name;
                $this->set_open($id, $open);
                return $membership->ephemeral(sprintf($open ? __('%s accepte à nouveau des volontaires.', 'nyassobi-wp-plugin') : __('%s : inscriptions fermées. Les volontaires déjà inscrits restent dans le récapitulatif.', 'nyassobi-wp-plugin'), $this->convention($id)['name']));

            case 'convention-infos':
            case 'convention-annonce':
            case 'convention-note':
                $id = (int) ($options['convention'] ?? 0);
                if (! $this->is_upcoming($id)) {
                    return $membership->ephemeral(__('Convention introuvable : choisis-la dans la liste proposée.', 'nyassobi-wp-plugin'));
                }
                $attachments = self::sent_files($payload, [$options['image'] ?? '']);
                if ($attachments) {
                    // Downloading an image can take longer than Discord waits:
                    // answer "thinking…" now, finish in the background.
                    return $this->later($name, $options + ['_attachments' => wp_json_encode($attachments)], $payload, false);
                }

                return $membership->ephemeral($this->run_ca_command($name, $id, $options, []) ?? self::done_text($name, $this->convention($id)['name']));

            default:
                $lines = [];
                foreach ($this->upcoming_ids() as $id) {
                    $c = $this->convention($id);
                    $lines[] = sprintf('• **%s** · %s%s · %d volontaire(s)%s', Nyassobi_Membership::escape_markdown($c['name']), $c['dates'], '' !== $c['city'] ? ' · ' . Nyassobi_Membership::escape_markdown($c['city']) : '', count($this->volunteers($id)), $c['open'] ? '' : ' · fermée');
                }
                return $membership->ephemeral($lines ? mb_substr(implode("\n", $lines), 0, 1900) : __('Aucune convention à venir.', 'nyassobi-wp-plugin'));
        }
    }

    /**
     * The CA commands and forms that add content. Shared by the immediate
     * answer and by finish_command() once images are downloaded.
     *
     * @param array<string,string> $options
     * @param int[]                $images Already in the media library; erased if refused.
     *
     * @return string|null Error message, or null once done.
     */
    private function run_ca_command(string $name, int $id, array $options, array $images): ?string
    {
        if ('convention-note' === $name) {
            return $this->set_note($id, (string) ($options['personne'] ?? ''), $options['statut'] ?? null, $options['note'] ?? null);
        }
        if ('convention-annonce' === $name) {
            // One image per announcement.
            foreach (array_slice($images, 1) as $extra) {
                wp_delete_attachment($extra, true);
            }
            $images = array_slice($images, 0, 1);
            $error = $this->add_news($id, (string) ($options['texte'] ?? ''), (int) ($images[0] ?? 0), ! isset($options['mentionner']) || '' !== $options['mentionner']);
        } else {
            $error = $this->set_info($id, $options['texte'] ?? null, $options['lien'] ?? null, $images, ! empty($options['retirer-images']) && 'false' !== $options['retirer-images']);
        }
        if (null !== $error) {
            foreach ($images as $image) {
                wp_delete_attachment($image, true);
            }
        }

        return $error;
    }

    private static function done_text(string $name, string $convention): string
    {
        switch ($name) {
            case 'convention-note':
                return sprintf(__('Note enregistrée pour %s, visible dans le récapitulatif des orgas.', 'nyassobi-wp-plugin'), $convention);
            case 'convention-annonce':
                return sprintf(__('Annonce publiée pour %s : sur la page Conventions du site et dans le salon des annonces.', 'nyassobi-wp-plugin'), $convention);
            case 'comm':
                return sprintf(__('✅ Merci ! Le CA a bien reçu tes infos pour la comm de %s. Tu peux les modifier avec le même bouton.', 'nyassobi-wp-plugin'), $convention);
        }

        return sprintf(__('Infos de %s mises à jour sur la page Conventions du site.', 'nyassobi-wp-plugin'), $convention);
    }

    /**
     * Files sent with a command or a form, as Discord describes them.
     *
     * @param array<string,mixed> $payload
     * @param string[]            $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private static function sent_files(array $payload, array $ids): array
    {
        $files = [];
        foreach ($ids as $aid) {
            $attachment = $payload['data']['resolved']['attachments'][$aid] ?? null;
            if (is_array($attachment)) {
                $files[] = array_intersect_key($attachment, array_flip(['url', 'filename', 'size', 'content_type']));
            }
        }

        return $files;
    }

    /**
     * Answers "thinking…" now and finishes in the background: downloading
     * images can take longer than the 3 seconds Discord waits.
     *
     * @param array<string,string> $options
     * @param array<string,mixed>  $payload
     */
    private function later(string $name, array $options, array $payload, bool $replace): \WP_REST_Response
    {
        wp_schedule_single_event(time(), self::ATTACHMENT_HOOK, [$name, $options, (string) ($payload['token'] ?? '')]);
        // The site gets few visits: start WP-Cron now.
        spawn_cron();

        // 6 edits the private message the form came from, 5 starts a new one.
        return new \WP_REST_Response($replace ? ['type' => 6] : ['type' => 5, 'data' => ['flags' => 64]], 200);
    }

    /**
     * Downloads the images sent on Discord and files them under the
     * convention, one at a time. All or nothing.
     *
     * @param array<int,mixed>     $attachments
     * @param array<string,string> $types
     *
     * @return int[]|string Attachment ids, or an error message.
     */
    private function store_attachments(int $id, array $attachments, array $types = self::IMAGE_TYPES)
    {
        $stored = [];
        foreach ($attachments as $attachment) {
            $image = is_array($attachment) ? $this->fetch_attachment($attachment) : __('Image illisible.', 'nyassobi-wp-plugin');
            $result = is_string($image) ? $image : $this->store_image($id, $image['bytes'], $image['name'], $types);
            if (is_string($result)) {
                foreach ($stored as $done) {
                    wp_delete_attachment($done, true);
                }

                return $result;
            }
            $stored[] = $result;
        }

        return $stored;
    }

    /**
     * Background part of a command or form with images: download them from
     * Discord, then replace Discord's "thinking…" with the result. A refused
     * form gets a « Corriger » button that opens it again, filled in.
     *
     * @param array<string,string> $options
     */
    public function finish_command(string $name, array $options, string $token): void
    {
        $attachments = json_decode((string) ($options['_attachments'] ?? ''), true);
        $kept = json_decode((string) ($options['_kept'] ?? ''), true);
        $user = (string) ($options['_user'] ?? '');
        $form = (string) ($options['_form'] ?? '');
        $options = array_diff_key($options, array_flip(['_attachments', '_kept', '_user', '_form']));
        $id = (int) ($options['convention'] ?? 0);

        $error = $this->is_upcoming($id) ? null : __('Convention introuvable.', 'nyassobi-wp-plugin');
        $images = [];
        if (null === $error && is_array($attachments) && $attachments) {
            $stored = $this->store_attachments($id, $attachments, 'comm' === $name ? self::COMM_TYPES : self::IMAGE_TYPES);
            if (is_string($stored)) {
                $error = $stored;
            } else {
                $images = $stored;
            }
        }
        if (null === $error) {
            $error = 'comm' === $name
                ? $this->set_comm($id, $user, (string) ($options['pronoms'] ?? ''), (string) ($options['langues'] ?? ''), $images)
                : $this->run_ca_command($name, $id, $options, $images);
        }

        $data = ['content' => $error ?? self::done_text($name, $this->convention($id)['name']), 'components' => [], 'allowed_mentions' => ['parse' => []]];
        if ('' !== $user && '' !== $form) {
            self::kept_form($user, $form, null !== $error && is_array($kept) ? $kept : []);
            if (null !== $error && is_array($kept)) {
                $data['content'] = '⚠️ ' . $error . ($attachments ? ' ' . __('Les images sont à remettre.', 'nyassobi-wp-plugin') : '');
                $data['components'] = self::fix_button($form);
            }
        }

        $app = (string) ($this->settings()['discord_application_id'] ?? '');
        if ('' !== $app && preg_match('/^[A-Za-z0-9._-]+$/', $token)) {
            wp_remote_request('https://discord.com/api/v10/webhooks/' . rawurlencode($app) . '/' . $token . '/messages/@original', [
                'method' => 'PATCH',
                'timeout' => 10,
                'user-agent' => 'DiscordBot (https://nyassobi.fr, 1.0)',
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($data),
            ]);
        }
    }

    /* ------------------------------------------------------------------
     * Buttons, menus and forms, for the CA
     * ------------------------------------------------------------------ */

    /**
     * One question of a form: its title, an optional hint under it, and the
     * field itself (text, menu or files).
     *
     * @param array<string,mixed> $component
     *
     * @return array<string,mixed>
     */
    private static function field(string $label, array $component, string $description = ''): array
    {
        $field = ['type' => 18, 'label' => mb_substr($label, 0, 45), 'component' => $component];
        if ('' !== $description) {
            $field['description'] = mb_substr($description, 0, 100);
        }

        return $field;
    }

    /** @return array<string,mixed> */
    private static function text(string $id, bool $long, bool $required, int $max, string $value = '', string $placeholder = ''): array
    {
        $input = ['type' => 4, 'custom_id' => $id, 'style' => $long ? 2 : 1, 'required' => $required, 'max_length' => $max];
        if ('' !== $value) {
            $input['value'] = mb_substr($value, 0, $max);
        }
        if ('' !== $placeholder) {
            $input['placeholder'] = mb_substr($placeholder, 0, 100);
        }

        return $input;
    }

    /**
     * A menu inside a form, with the current choice already selected.
     *
     * @param array<string,string> $choices value => label
     * @param string[]             $picked
     *
     * @return array<string,mixed>
     */
    private static function menu(string $id, array $choices, array $picked, int $max = 1): array
    {
        $options = [];
        foreach ($choices as $value => $label) {
            $options[] = ['label' => mb_substr($label, 0, 100), 'value' => (string) $value] + (in_array((string) $value, $picked, true) ? ['default' => true] : []);
        }

        return ['type' => 3, 'custom_id' => $id, 'options' => $options, 'min_values' => 1, 'max_values' => min($max, count($options)), 'required' => true];
    }

    /** Images sent straight from the form, downloaded afterwards. @return array<string,mixed> */
    private static function files(string $id, int $max): array
    {
        return ['type' => 19, 'custom_id' => $id, 'min_values' => 0, 'max_values' => $max, 'required' => false];
    }

    /** @param array<int,array<string,mixed>> $fields */
    private static function modal(string $id, string $title, array $fields): \WP_REST_Response
    {
        return new \WP_REST_Response(['type' => 9, 'data' => ['custom_id' => $id, 'title' => mb_substr($title, 0, 45), 'components' => $fields]], 200);
    }

    /**
     * A form sent back: each field by name. Text gives a string, menus and
     * files a list. Reads both Discord layouts (labels and old rows).
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,string|string[]>
     */
    private static function form_values(array $payload): array
    {
        $values = [];
        foreach ((array) ($payload['data']['components'] ?? []) as $row) {
            foreach (isset($row['component']) ? [$row['component']] : (array) ($row['components'] ?? []) as $field) {
                $values[(string) ($field['custom_id'] ?? '')] = isset($field['values']) ? array_map('strval', (array) $field['values']) : (string) ($field['value'] ?? '');
            }
        }

        return $values;
    }

    /** @param array<string,string|string[]> $values */
    private static function value(array $values, string $key, string $default = ''): string
    {
        $value = $values[$key] ?? $default;

        return is_array($value) ? (string) ($value[0] ?? $default) : $value;
    }

    /**
     * What a CA member typed in a refused form, to fill it in again when they
     * click « Corriger ». Kept a quarter of an hour, per person and form.
     *
     * @param array<string,string|string[]>|null $values null reads, [] forgets.
     *
     * @return array<string,string|string[]>
     */
    private static function kept_form(string $user, string $form, ?array $values = null): array
    {
        $key = 'nyassobi_conv_form_' . md5($user . ':' . $form);
        if (null === $values) {
            $kept = get_transient($key);

            return is_array($kept) ? $kept : [];
        }
        if ($values) {
            set_transient($key, $values, 15 * MINUTE_IN_SECONDS);
        } else {
            delete_transient($key);
        }

        return $values;
    }

    /**
     * $form is the custom_id of the button that opens the form: it also
     * names what kept_form() keeps.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function fix_button(string $form): array
    {
        return [['type' => 1, 'components' => [['type' => 2, 'style' => 1, 'label' => '✏️ Corriger', 'custom_id' => $form]]]];
    }

    /**
     * What the CA sees after picking a volunteer: the profile, and buttons
     * for the decision and a note.
     *
     * @return array{content:string,components:array<int,array<string,mixed>>}
     */
    private function volunteer_card(int $id, string $discord_id, string $notice = ''): array
    {
        $person = null;
        foreach ($this->volunteers($id) as $p) {
            if ($p['discord_id'] === $discord_id) {
                $person = $p;
            }
        }
        if (null === $person) {
            return ['content' => __('Cette personne ne s\'est pas proposée pour cette convention.', 'nyassobi-wp-plugin'), 'components' => []];
        }
        $note = $this->notes($id)[$discord_id] ?? ['status' => '', 'note' => ''];
        $md = static fn (string $t): string => Nyassobi_Membership::escape_markdown($t);
        $lines = [
            sprintf('**%s** <@%s> · %s', $md($person['name']), $discord_id, $md($this->convention($id)['name'])),
            sprintf('%s%s · %s%s', self::ROLES[$person['role']] ?? '', '' !== $this->days_text($id, $person['days']) ? ' · 📅 ' . $this->days_text($id, $person['days']) : '', self::travel_text($person), $person['slots'] ? ' · 🕐 ' . self::slots_text($person['slots']) : ''),
        ];
        if ('staff' !== $person['role'] && '' !== $person['animation']) {
            $lines[] = '🎤 ' . $md(mb_substr($person['animation'], 0, 500));
        }
        if ('' !== $person['comment']) {
            $lines[] = '💬 ' . $md(mb_substr($person['comment'], 0, 500));
        }
        $lines[] = 'Décision : ' . (self::STATUSES[$note['status']] ?? 'aucune')
            . (! empty($note['notified']) ? ' · ✉️ prévenu·e en message privé' : '');
        if ('' !== $note['note']) {
            $lines[] = '📝 ' . $md($note['note']);
        }
        $comm = $this->comm($id)[$discord_id] ?? null;
        $selected = $this->is_selected_animator($id, $discord_id);
        if (null !== $comm) {
            $link = $this->comm_link($comm);
            $lines[] = sprintf('🖼️ Comm : %s · %s · %d image(s)%s', $md($comm['pronouns']), $md($comm['languages']), count($comm['images']), '' !== $link ? ' · [voir les images](' . $link . ')' : '');
        } elseif ($selected) {
            $lines[] = '🖼️ Comm : pas encore reçue (images PNG du model, pronoms, langues)';
        }
        if ('' !== $notice) {
            $lines[] = $notice;
        }
        $base = 'nyconv:status:' . $id . ':' . $discord_id . ':';
        $buttons = [
            ['type' => 2, 'style' => 3, 'label' => '✅ Retenir', 'custom_id' => $base . 'retenu'],
            ['type' => 2, 'style' => 2, 'label' => '⏳ En attente', 'custom_id' => $base . 'attente'],
            ['type' => 2, 'style' => 4, 'label' => '❌ Écarter', 'custom_id' => $base . 'non'],
            ['type' => 2, 'style' => 1, 'label' => '📝 Note', 'custom_id' => 'nyconv:note:' . $id . ':' . $discord_id],
        ];
        // The private message only leaves on purpose, never on a misclick.
        if (in_array($note['status'], ['retenu', 'non'], true) && empty($note['notified'])) {
            $buttons[] = ['type' => 2, 'style' => 1, 'label' => '📨 Prévenir la personne', 'custom_id' => 'nyconv:notify:' . $id . ':' . $discord_id];
        }
        $rows = [['type' => 1, 'components' => $buttons]];
        // Already told without the request (or it got lost): ask again.
        if ($selected && null === $comm && ! empty($note['notified'])) {
            $rows[] = ['type' => 1, 'components' => [['type' => 2, 'style' => 2, 'label' => '🖼️ Redemander les infos pour la comm', 'custom_id' => 'nyconv:comm-ask:' . $id . ':' . $discord_id]]];
        }

        return ['content' => implode("\n", $lines), 'components' => $rows];
    }

    /**
     * @param \WP_REST_Response|null $response
     * @param array<string,mixed>     $payload
     *
     * @return \WP_REST_Response|null
     */
    public function handle_component($response, array $payload)
    {
        $custom_id = (string) ($payload['data']['custom_id'] ?? '');
        if (null !== $response || 0 !== strpos($custom_id, 'nyconv:')) {
            return $response;
        }
        $membership = Nyassobi_Membership::instance();
        if (! in_array($this->settings()['discord_board_role_id'] ?? '', (array) ($payload['member']['roles'] ?? []), true)) {
            return $membership->ephemeral(__('Seuls les membres du CA peuvent gérer les conventions.', 'nyassobi-wp-plugin'));
        }
        $parts = explode(':', $custom_id);
        $action = $parts[1] ?? '';
        $id = (int) ($parts[2] ?? 0);
        $discord_id = ctype_digit($parts[3] ?? '') ? $parts[3] : '';
        $user = (string) ($payload['member']['user']['id'] ?? '');
        $values = self::form_values($payload);

        if (! in_array($action, ['add', 'add-modal', 'list'], true) && ! $this->is_upcoming($id)) {
            return $membership->ephemeral(__('Cette convention n\'existe plus.', 'nyassobi-wp-plugin'));
        }
        $c = $id ? $this->convention($id) : null;
        $update = static fn (array $card): \WP_REST_Response => new \WP_REST_Response(['type' => 7, 'data' => $card + ['allowed_mentions' => ['parse' => []]]], 200);
        // A form reopened with « Corriger » answers in place of that private
        // message; any other form answers with a new private message (never
        // over the panel or a recap, which everyone sees).
        $from_private = 64 === (64 & (int) ($payload['message']['flags'] ?? 0));
        $answer = static fn (string $text, array $components = []): \WP_REST_Response => new \WP_REST_Response([
            'type' => $from_private ? 7 : 4,
            'data' => ['content' => $text, 'components' => $components, 'allowed_mentions' => ['parse' => []]] + ($from_private ? [] : ['flags' => 64]),
        ], 200);

        switch ($action) {
            case 'add':
                $kept = self::kept_form($user, 'nyconv:add');
                return self::modal('nyconv:add-modal', 'Nouvelle convention', [
                    self::field('Nom de la convention', self::text('nom', false, true, 80, self::value($kept, 'nom'))),
                    self::field('Ville', self::text('ville', false, true, 80, self::value($kept, 'ville'))),
                    self::field('Premier jour', self::text('debut', false, true, 10, self::value($kept, 'debut'), '03/10/2026'), 'Jour/mois/année'),
                    self::field('Dernier jour', self::text('fin', false, false, 10, self::value($kept, 'fin'), '04/10/2026'), 'À laisser vide pour une seule journée'),
                    self::field('On cherche', self::menu('besoins', self::NEEDS, [self::value($kept, 'besoins', 'les-deux')])),
                ]);

            case 'add-modal':
                $new = $this->create(self::value($values, 'nom'), self::value($values, 'ville'), self::value($values, 'debut'), self::value($values, 'fin'), self::value($values, 'besoins', 'les-deux'));
                if (is_string($new)) {
                    self::kept_form($user, 'nyconv:add', $values);
                    return $answer('⚠️ ' . $new, self::fix_button('nyconv:add'));
                }
                self::kept_form($user, 'nyconv:add', []);
                $made = $this->convention($new);
                return $answer(sprintf(__('Convention ajoutée : %1$s, %2$s. Son récapitulatif, avec ses boutons, vient d\'apparaître dans le salon des orgas.', 'nyassobi-wp-plugin'), $made['name'], $made['dates']));

            case 'list':
                $lines = [];
                foreach ($this->upcoming_ids() as $cid) {
                    $item = $this->convention($cid);
                    $lines[] = sprintf('• **%s** · %s · %d volontaire(s)%s', Nyassobi_Membership::escape_markdown($item['name']), $item['dates'], count($this->volunteers($cid)), $item['open'] ? '' : ' · fermée');
                }
                return $membership->ephemeral($lines ? mb_substr(implode("\n", $lines), 0, 1900) : __('Aucune convention à venir.', 'nyassobi-wp-plugin'));

            case 'infos':
                $kept = self::kept_form($user, 'nyconv:infos:' . $id);
                $online = count($this->images($id));
                $fields = [
                    self::field('Description', self::text('texte', true, false, 2000, $kept ? self::value($kept, 'texte') : $this->meta($id, self::META_DESCRIPTION), 'Horaires, emplacement du stand, ce qu\'on attend des bénévoles…'), 'Visible des adhérents sur la page Conventions du site'),
                    self::field('Site de la convention', self::text('lien', false, false, 300, $kept ? self::value($kept, 'lien') : $this->meta($id, self::META_LINK), 'https://')),
                    self::field('Ajouter une affiche ou des photos', self::files('images', self::MAX_IMAGES), sprintf('JPEG, PNG, WebP ou GIF, 8 Mo chacune, %d en tout au plus', self::MAX_IMAGES)),
                ];
                if ($online) {
                    $fields[] = self::field(sprintf('Images déjà en ligne : %d', $online), self::menu('anciennes', ['garder' => 'Les garder', 'retirer' => 'Les retirer toutes'], [self::value($kept, 'anciennes', 'garder')]));
                }
                return self::modal('nyconv:infos-modal:' . $id, 'Infos : ' . $c['name'], $fields);

            case 'news':
                $kept = self::kept_form($user, 'nyconv:news:' . $id);
                return self::modal('nyconv:news-modal:' . $id, 'Annonce : ' . $c['name'], [
                    self::field('L\'annonce', self::text('texte', true, true, 1500, self::value($kept, 'texte'), 'Les émojis du serveur s\'écrivent :NyassoHi: ; **gras** possible.'), 'Visible des adhérents, sur le site et dans le salon des annonces'),
                    self::field('Image (facultatif)', self::files('images', 1), 'JPEG, PNG, WebP ou GIF, 8 Mo au plus'),
                    self::field('Mentionner les adhérents ?', self::menu('mentionner', ['oui' => 'Oui, avec le rôle Adhérent', 'non' => 'Non, sans notification'], [self::value($kept, 'mentionner', 'oui')])),
                ]);

            case 'infos-modal':
            case 'news-modal':
                $infos = 'infos-modal' === $action;
                $form = 'nyconv:' . ($infos ? 'infos:' : 'news:') . $id;
                $name = $infos ? 'convention-infos' : 'convention-annonce';
                $options = $infos
                    ? ['texte' => self::value($values, 'texte'), 'lien' => self::value($values, 'lien'), 'retirer-images' => 'retirer' === self::value($values, 'anciennes') ? '1' : '']
                    : ['texte' => self::value($values, 'texte'), 'mentionner' => 'non' === self::value($values, 'mentionner') ? '' : '1'];
                // Images cannot be put back in a form: only the rest is kept.
                $kept = array_diff_key($values, ['images' => true]);
                $attachments = self::sent_files($payload, (array) ($values['images'] ?? []));
                if ($attachments) {
                    return $this->later($name, ['convention' => (string) $id, '_attachments' => wp_json_encode($attachments), '_user' => $user, '_form' => $form, '_kept' => wp_json_encode($kept)] + $options, $payload, $from_private);
                }
                $error = $this->run_ca_command($name, $id, $options, []);
                self::kept_form($user, $form, null !== $error ? $kept : []);
                return null !== $error ? $answer('⚠️ ' . $error, self::fix_button($form)) : $answer(self::done_text($name, $c['name']));

            case 'toggle':
                // Answered by updating the recap the button belongs to.
                update_post_meta($id, self::META_OPEN, $c['open'] ? '0' : '1');
                $this->update_summary();
                return new \WP_REST_Response(['type' => 7, 'data' => $this->recap_message($id)], 200);

            case 'delete':
                // Erasing is final: a private confirmation first.
                $count = count($this->volunteers($id));
                return new \WP_REST_Response(['type' => 4, 'data' => [
                    'flags' => 64,
                    'content' => sprintf(
                        __('Supprimer **%1$s** (%2$s) ? %3$d réponse(s) de volontaires, les infos, images et annonces seront effacées, ainsi que ses messages sur Discord. C\'est définitif.', 'nyassobi-wp-plugin'),
                        Nyassobi_Membership::escape_markdown($c['name']),
                        $c['dates'],
                        $count
                    ),
                    'components' => [[
                        'type' => 1,
                        'components' => [
                            ['type' => 2, 'style' => 4, 'label' => 'Oui, supprimer', 'custom_id' => 'nyconv:delete-ok:' . $id],
                            ['type' => 2, 'style' => 2, 'label' => 'Annuler', 'custom_id' => 'nyconv:delete-no:' . $id],
                        ],
                    ]],
                ]], 200);

            case 'delete-no':
                return $update(['content' => __('Suppression annulée.', 'nyassobi-wp-plugin'), 'components' => []]);

            case 'delete-ok':
                wp_delete_post($id, true);
                return $update(['content' => sprintf(__('%s est supprimée, avec ses réponses, infos et messages.', 'nyassobi-wp-plugin'), $c['name']), 'components' => []]);

            case 'pick':
                $picked = (string) (($payload['data']['values'] ?? [])[0] ?? '');
                $card = $this->volunteer_card($id, ctype_digit($picked) ? $picked : '');
                return new \WP_REST_Response(['type' => 4, 'data' => $card + ['flags' => 64, 'allowed_mentions' => ['parse' => []]]], 200);

            case 'status':
                $error = $this->set_note($id, $discord_id, (string) ($parts[4] ?? ''), null);
                return null !== $error ? $membership->ephemeral($error) : $update($this->volunteer_card($id, $discord_id));

            case 'notify':
                $sent = $this->notify_volunteer($id, $discord_id);
                return $update($this->volunteer_card($id, $discord_id, $sent ? '✉️ Message privé envoyé.' : '⚠️ Message privé impossible (messages privés fermés ou personne partie du serveur) : contacte-la directement.'));

            case 'note':
                return self::modal('nyconv:note-modal:' . $id . ':' . $discord_id, 'Note du CA', [
                    self::field('Note', self::text('note', true, false, 300, $this->notes($id)[$discord_id]['note'] ?? ''), 'Visible du CA seulement'),
                ]);

            case 'note-modal':
                $note = self::value($values, 'note');
                $error = $this->set_note($id, $discord_id, null, '' === trim($note) ? '-' : $note);
                return null !== $error ? $membership->ephemeral($error) : $update($this->volunteer_card($id, $discord_id));

            case 'comm-ask':
                $sent = $this->ask_comm($id, $discord_id);
                return $update($this->volunteer_card($id, $discord_id, $sent ? '✉️ Demande envoyée en message privé.' : '⚠️ Message privé impossible (messages privés fermés ou personne partie du serveur) : contacte-la directement.'));
        }

        return $membership->ephemeral(__('Action inconnue.', 'nyassobi-wp-plugin'));
    }

    /* ------------------------------------------------------------------
     * Volunteering from Discord, for members
     * ------------------------------------------------------------------ */

    /**
     * Choices being made in the private Discord form, before « Continuer ».
     *
     * @return array{role:string,days:string[],travel:string,slots:string[]}
     */
    private function draft(string $discord_id, int $id): array
    {
        $saved = get_transient('nyassobi_vol_' . $discord_id . '_' . $id);
        if (is_array($saved)) {
            return $saved;
        }
        $rid = $this->response_id($discord_id);
        $choice = $rid ? ($this->choices($rid)[$id] ?? null) : null;
        $needs = $this->convention($id)['needs'];
        $days = $this->days($id);

        return [
            'role' => (string) ($choice['role'] ?? ('les-deux' === $needs ? '' : $needs)),
            'days' => array_values((array) ($choice['days'] ?? (1 === count($days) ? $days : []))),
            'travel' => (string) ($choice['travel'] ?? ''),
            'slots' => array_values((array) ($choice['slots'] ?? [])),
        ];
    }

    private function save_draft(string $discord_id, int $id, array $draft): void
    {
        set_transient('nyassobi_vol_' . $discord_id . '_' . $id, $draft, 30 * MINUTE_IN_SECONDS);
    }

    /**
     * The private form: menus for role, days, travel and preferred hours,
     * redrawn after each choice so the person sees what they picked.
     *
     * @return array<string,mixed>
     */
    private function volunteer_form(string $discord_id, int $id, string $error = ''): array
    {
        $c = $this->convention($id);
        $draft = $this->draft($discord_id, $id);
        $rid = $this->response_id($discord_id);
        $already = $rid && isset($this->choices($rid)[$id]);
        $lines = [sprintf('🙋 **%s** · %s%s', Nyassobi_Membership::escape_markdown($c['name']), $c['dates'], '' !== $c['city'] ? ' · ' . Nyassobi_Membership::escape_markdown($c['city']) : '')];
        $lines[] = $already ? 'Tu es déjà proposé·e : change tes choix si besoin, puis **Continuer**.' : 'Choisis tes options, puis **Continuer**. Ça n\'engage à rien : le CA choisit l\'équipe et te recontacte.';
        // What is picked so far, in words: a menu only shows the start of a long choice.
        $several_days = count($this->days($id)) > 1;
        $summary = [
            'Rôle' => self::ROLES[$draft['role']] ?? '',
            'Jours' => $several_days ? $this->days_text($id, $draft['days']) : null,
            'Trajet' => 'staff' === $draft['role'] ? (self::TRAVEL[$draft['travel']] ?? '') : null,
            'Horaires' => 'animation' === $draft['role'] ? self::slots_text($draft['slots']) : null,
        ];
        $parts = [];
        foreach ($summary as $label => $text) {
            if (null !== $text) {
                $parts[] = $label . ' : ' . ('' !== $text ? $text : '*à choisir*');
            }
        }
        $lines[] = '📋 ' . implode(' · ', $parts);
        if ('animation' === $draft['role']) {
            $lines[] = '💻 Les animations se font à distance : pas de trajet à prévoir.';
        }
        if ('' !== $error) {
            $lines[] = '⚠️ ' . $error;
        }
        $select = static function (string $custom_id, string $placeholder, array $choices, array $picked, int $max = 1): array {
            $options = [];
            foreach ($choices as $value => $label) {
                $options[] = ['label' => mb_substr($label, 0, 100), 'value' => (string) $value] + (in_array((string) $value, $picked, true) ? ['default' => true] : []);
            }

            return ['type' => 1, 'components' => [['type' => 3, 'custom_id' => $custom_id, 'placeholder' => $placeholder, 'options' => $options, 'min_values' => 1, 'max_values' => min($max, count($options))]]];
        };
        $rows = [];
        if ('les-deux' === $c['needs']) {
            $rows[] = $select('nyvol:role:' . $id, 'Je viens pour…', self::ROLES, [$draft['role']]);
        }
        $days = $this->days($id);
        if (count($days) > 1) {
            $rows[] = $select('nyvol:days:' . $id, 'Les jours où je peux venir…', array_combine($days, array_map(static fn ($d) => self::day_label($d, true), $days)), $draft['days'], count($days));
        }
        if ('staff' === $draft['role']) {
            $rows[] = $select('nyvol:travel:' . $id, 'Mon temps de trajet jusqu\'au stand…', array_map('ucfirst', self::TRAVEL), [$draft['travel']]);
        }
        if ('animation' === $draft['role']) {
            $rows[] = $select('nyvol:slots:' . $id, 'Mes horaires préférés pour animer…', array_map('ucfirst', self::SLOTS), $draft['slots'], count(self::SLOTS));
        }
        $buttons = [['type' => 2, 'style' => 1, 'label' => 'Continuer', 'custom_id' => 'nyvol:next:' . $id]];
        if ($already) {
            $buttons[] = ['type' => 2, 'style' => 4, 'label' => 'Retirer ma proposition', 'custom_id' => 'nyvol:withdraw:' . $id];
        }
        $rows[] = ['type' => 1, 'components' => $buttons];

        return ['content' => implode("\n", $lines), 'components' => $rows, 'allowed_mentions' => ['parse' => []]];
    }

    /**
     * Saves this convention's choice, keeping the person's other conventions.
     *
     * @param array<string,mixed>|null $choice null withdraws from this convention.
     */
    private function save_one(array $session, int $id, ?array $choice, ?string $animation, ?string $comment): ?string
    {
        $rid = $this->response_id($session['id']);
        $all = $rid ? $this->choices($rid) : [];
        unset($all[$id]);
        if (null !== $choice) {
            $all[$id] = $choice;
        }
        $raw = [];
        foreach ($all as $cid => $c) {
            if ($this->is_upcoming((int) $cid)) {
                $raw[] = ['conventionId' => (int) $cid] + $c;
            }
        }

        return $this->save_response($session, $raw, $animation ?? ($rid ? $this->meta($rid, self::META_ANIMATION) : ''), $comment ?? ($rid ? $this->meta($rid, self::META_COMMENT) : ''));
    }

    /**
     * @param \WP_REST_Response|null $response
     * @param array<string,mixed>     $payload
     *
     * @return \WP_REST_Response|null
     */
    public function handle_volunteer($response, array $payload)
    {
        $custom_id = (string) ($payload['data']['custom_id'] ?? '');
        if (null !== $response || 0 !== strpos($custom_id, 'nyvol:')) {
            return $response;
        }
        $membership = Nyassobi_Membership::instance();
        $s = $this->settings();
        $roles = (array) ($payload['member']['roles'] ?? []);
        $user = (array) ($payload['member']['user'] ?? []);
        $discord_id = (string) ($user['id'] ?? '');
        // Discord vouches for who clicked and their roles: no sign-in needed.
        if (! ctype_digit($discord_id) || ! (in_array($s['discord_member_role_id'] ?? '', $roles, true) || in_array($s['discord_board_role_id'] ?? '', $roles, true))) {
            return $membership->ephemeral(__('Les conventions sont réservées aux adhérents : il faut le rôle Adhérent sur le serveur.', 'nyassobi-wp-plugin'));
        }
        $session = [
            'id' => $discord_id,
            'name' => sanitize_text_field((string) (($payload['member']['nick'] ?? '') ?: (($user['global_name'] ?? '') ?: ($user['username'] ?? '')))),
            'member' => true,
        ];
        $parts = explode(':', $custom_id);
        $action = $parts[1] ?? '';
        $id = 'pick' === $action ? (int) (($payload['data']['values'] ?? [])[0] ?? 0) : (int) ($parts[2] ?? 0);
        if (! $this->is_upcoming($id)) {
            return $membership->ephemeral(__('Cette convention n\'existe plus.', 'nyassobi-wp-plugin'));
        }
        $c = $this->convention($id);
        $rid = $this->response_id($discord_id);
        $already = $rid && isset($this->choices($rid)[$id]);
        $show = static fn (array $data, int $type): \WP_REST_Response => new \WP_REST_Response(['type' => $type, 'data' => $data + (4 === $type ? ['flags' => 64] : [])], 200);
        $values = array_map('strval', (array) ($payload['data']['values'] ?? []));
        $draft = $this->draft($discord_id, $id);

        switch ($action) {
            case 'start':
            case 'pick':
                if (! $c['open'] && ! $already) {
                    return $membership->ephemeral(sprintf(__('L\'équipe de %s est déjà complète. D\'autres conventions arrivent !', 'nyassobi-wp-plugin'), $c['name']));
                }
                return $show($this->volunteer_form($discord_id, $id), 4);

            case 'role':
            case 'days':
            case 'travel':
            case 'slots':
                if ('role' === $action) {
                    $draft['role'] = (string) ($values[0] ?? '');
                    if ('animation' !== $draft['role']) {
                        $draft['slots'] = [];
                    }
                } elseif ('travel' === $action) {
                    $draft['travel'] = (string) ($values[0] ?? '');
                } else {
                    $draft[$action] = $values;
                }
                $this->save_draft($discord_id, $id, $draft);
                return $show($this->volunteer_form($discord_id, $id), 7);

            case 'next':
                $missing = '' === $draft['role'] ? 'ton rôle' : ('staff' === $draft['role'] && '' === $draft['travel'] ? 'ton temps de trajet' : (! $draft['days'] ? 'au moins un jour' : ('animation' === $draft['role'] && ! $draft['slots'] ? 'tes horaires préférés' : '')));
                if ('' !== $missing) {
                    return $show($this->volunteer_form($discord_id, $id, 'Choisis ' . $missing . '.'), 7);
                }
                $choice = $rid ? ($this->choices($rid)[$id] ?? []) : [];
                // What was typed in a refused form comes first, then what is saved.
                $typed = static fn (string $key, string $saved): string => (string) ($draft[$key] ?? $saved);
                $fields = [];
                if ('staff' === $draft['role']) {
                    $fields[] = self::field('Moyen de transport', self::text('transport', false, false, 80, $typed('transport', (string) ($choice['transport'] ?? '')), 'Train, voiture, covoiturage…'), 'Facultatif');
                }
                if ('animation' === $draft['role']) {
                    $fields[] = self::field('L\'animation que tu proposes', self::text('animation', true, true, 1000, $typed('animation', $rid ? $this->meta($rid, self::META_ANIMATION) : ''), 'Karaoké, art, rig, quiz… tout est bien tant que c\'est interactif'));
                }
                $fields[] = self::field('Précisions ou questions', self::text('commentaire', true, false, 1000, $typed('comment', $rid ? $this->meta($rid, self::META_COMMENT) : '')), 'Facultatif');
                return self::modal('nyvol:modal:' . $id, mb_substr('Me proposer : ' . $c['name'], 0, 45), $fields);

            case 'modal':
                $form = self::form_values($payload);
                $texts = ['transport' => self::value($form, 'transport'), 'comment' => self::value($form, 'commentaire')] + (isset($form['animation']) ? ['animation' => self::value($form, 'animation')] : []);
                $error = $this->save_one($session, $id, ['role' => $draft['role'], 'days' => $draft['days'], 'travel' => $draft['travel'], 'slots' => $draft['slots'], 'transport' => $texts['transport']], $texts['animation'] ?? null, $texts['comment']);
                if (null !== $error) {
                    // Kept for the next try: nothing to type again.
                    $this->save_draft($discord_id, $id, $texts + $draft);
                    return $show($this->volunteer_form($discord_id, $id, $error), 7);
                }
                delete_transient('nyassobi_vol_' . $discord_id . '_' . $id);
                $done = [sprintf(__('✅ C\'est noté pour **%s**, merci ! Le CA choisit l\'équipe et te prévient en message privé. Tu peux modifier ou retirer ta proposition avec le même bouton.', 'nyassobi-wp-plugin'), Nyassobi_Membership::escape_markdown($c['name']))];
                $description = $this->meta($id, self::META_DESCRIPTION);
                if ('' !== $description) {
                    $short = mb_strlen($description) > 400 ? mb_substr($description, 0, 399) . '…' : $description;
                    $done[] = '> ' . str_replace("\n", "\n> ", Nyassobi_Membership::escape_markdown($short));
                }
                if ('' !== $this->meta($id, self::META_LINK)) {
                    $done[] = '🔗 Site de la convention : ' . $this->meta($id, self::META_LINK);
                }
                $done[] = '📣 Infos et annonces : ' . $this->page_url();
                return $show(['content' => implode("\n", $done), 'components' => []], 7);

            case 'withdraw':
                $error = $this->save_one($session, $id, null, null, null);
                delete_transient('nyassobi_vol_' . $discord_id . '_' . $id);
                return $show(['content' => $error ?? sprintf(__('Ta proposition pour %s est retirée.', 'nyassobi-wp-plugin'), $c['name']), 'components' => []], 7);
        }

        return $membership->ephemeral(__('Action inconnue.', 'nyassobi-wp-plugin'));
    }

    /* ------------------------------------------------------------------
     * What the CA adds: infos, images, announcements, notes
     * ------------------------------------------------------------------ */

    /** @return array<string,array{status:string,note:string}> */
    private function notes(int $id): array
    {
        $notes = get_post_meta($id, self::META_NOTES, true);

        return is_array($notes) ? $notes : [];
    }

    /** @return int[] */
    private function images(int $id): array
    {
        return array_values(array_filter(array_map('intval', (array) get_post_meta($id, self::META_IMAGES, true)), static fn (int $i): bool => $i > 0 && 'attachment' === get_post_type($i)));
    }

    /** @return array<int,array{id:string,date:int,text:string,image:int,message:string}> */
    private function news(int $id): array
    {
        $news = get_post_meta($id, self::META_NEWS, true);

        return is_array($news) ? array_values($news) : [];
    }

    private static function image_url(int $attachment): string
    {
        return (string) (wp_get_attachment_image_url($attachment, 'large') ?: wp_get_attachment_url($attachment));
    }

    /**
     * Checks the bytes themselves (never the announced type), then files the
     * image in the media library, attached to the convention.
     *
     * @param array<string,string> $types Accepted types: mime => extension.
     *
     * @return int|string Attachment id, or an error message.
     */
    private function store_image(int $convention_id, string $bytes, string $name, array $types = self::IMAGE_TYPES)
    {
        if ('' === $bytes || strlen($bytes) > self::IMAGE_MAX_BYTES) {
            return __('L\'image doit faire moins de 8 Mo.', 'nyassobi-wp-plugin');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! isset($types[$mime])) {
            return self::COMM_TYPES === $types
                ? __('Seules les images PNG sont acceptées (fond transparent de préférence).', 'nyassobi-wp-plugin')
                : __('Seules les images JPEG, PNG, WebP ou GIF sont acceptées.', 'nyassobi-wp-plugin');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = wp_tempnam($name);
        file_put_contents($tmp, $bytes);
        $base = sanitize_file_name((string) pathinfo($name, PATHINFO_FILENAME)) ?: 'image';
        $attachment = media_handle_sideload(['name' => $base . '.' . $types[$mime], 'tmp_name' => $tmp], $convention_id);
        if (is_wp_error($attachment)) {
            @unlink($tmp);
            error_log('[Nyassobi] Conventions : image refusée (' . $attachment->get_error_message() . ').');
            return __('L\'image n\'a pas pu être enregistrée.', 'nyassobi-wp-plugin');
        }

        return (int) $attachment;
    }

    /**
     * Only Discord's own file servers are fetched: the address comes from a
     * Discord payload, but must never make WordPress call anything else.
     *
     * @param array<string,mixed> $attachment
     *
     * @return array{bytes:string,name:string}|string
     */
    private function fetch_attachment(array $attachment)
    {
        $url = (string) ($attachment['url'] ?? '');
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ('https' !== wp_parse_url($url, PHP_URL_SCHEME) || ! in_array($host, ['cdn.discordapp.com', 'media.discordapp.net'], true)) {
            return __('Image illisible.', 'nyassobi-wp-plugin');
        }
        if ((int) ($attachment['size'] ?? 0) > self::IMAGE_MAX_BYTES) {
            return __('L\'image doit faire moins de 8 Mo.', 'nyassobi-wp-plugin');
        }
        $response = wp_remote_get($url, ['timeout' => 30, 'limit_response_size' => self::IMAGE_MAX_BYTES + 1, 'redirection' => 0]);
        if (200 !== (int) wp_remote_retrieve_response_code($response)) {
            return __('Discord n\'a pas fourni l\'image : réessaie.', 'nyassobi-wp-plugin');
        }

        return ['bytes' => (string) wp_remote_retrieve_body($response), 'name' => (string) ($attachment['filename'] ?? 'image')];
    }

    /**
     * Infos shown to members. null leaves a field as it is; "-" clears it.
     * Everything is checked before anything changes: a refused form leaves
     * the convention as it was, and the caller erases the added images.
     *
     * @param int[] $add_images
     */
    public function set_info(int $id, ?string $description, ?string $link, array $add_images, bool $clear_images): ?string
    {
        if (null !== $description) {
            $description = '-' === trim($description) ? '' : sanitize_textarea_field($description);
            if (mb_strlen($description) > 2000) {
                return __('La description doit tenir en 2 000 caractères.', 'nyassobi-wp-plugin');
            }
        }
        if (null !== $link) {
            $clear = '-' === trim($link) || '' === trim($link);
            $link = $clear ? '' : esc_url_raw(trim($link), ['http', 'https']);
            if (! $clear && ! preg_match('#^https?://#', $link)) {
                return __('Ce lien ne semble pas valide : il doit commencer par https://', 'nyassobi-wp-plugin');
            }
        }
        $online = $this->images($id);
        $kept = $clear_images ? [] : $online;
        if (count($kept) + count($add_images) > self::MAX_IMAGES) {
            return $kept
                ? sprintf(__('%1$d images au plus, et il y en a déjà %2$d : retire-les d\'abord.', 'nyassobi-wp-plugin'), self::MAX_IMAGES, count($kept))
                : sprintf(__('%d images au plus.', 'nyassobi-wp-plugin'), self::MAX_IMAGES);
        }

        if (null !== $description) {
            update_post_meta($id, self::META_DESCRIPTION, $description);
        }
        if (null !== $link) {
            update_post_meta($id, self::META_LINK, $link);
        }
        if ($clear_images) {
            foreach ($online as $image) {
                wp_delete_attachment($image, true);
            }
        }
        update_post_meta($id, self::META_IMAGES, array_merge($kept, $add_images));
        $this->update_summary();

        return null;
    }

    /**
     * Posts a message with image files attached, so they show even when the
     * WordPress address is not reachable by Discord (test setup).
     *
     * @param array<string,mixed> $payload
     * @param int[]               $attachments
     *
     * @return array<string,mixed>|null
     */
    private function discord_post_with_files(string $channel, array $payload, array $attachments): ?array
    {
        $path = '/channels/' . rawurlencode($channel) . '/messages';
        $files = [];
        foreach ($attachments as $attachment) {
            $file = $attachment ? (string) get_attached_file($attachment) : '';
            if ('' !== $file && is_readable($file)) {
                $files[] = [$attachment, $file];
            }
        }
        if (! $files) {
            return Nyassobi_Membership::instance()->discord_request('POST', $path, $payload);
        }
        $boundary = 'nyassobi' . bin2hex(random_bytes(8));
        $parts = '';
        $payload['attachments'] = [];
        foreach ($files as $i => [$attachment, $file]) {
            $name = sanitize_file_name(wp_basename($file));
            $payload['attachments'][] = ['id' => $i, 'filename' => $name];
            $parts .= "--$boundary\r\nContent-Disposition: form-data; name=\"files[$i]\"; filename=\"$name\"\r\nContent-Type: " . (string) get_post_mime_type($attachment) . "\r\n\r\n" . (string) file_get_contents($file) . "\r\n";
        }
        if (isset($payload['embeds'][0])) {
            $payload['embeds'][0]['image'] = ['url' => 'attachment://' . $payload['attachments'][0]['filename']];
        }
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"payload_json\"\r\nContent-Type: application/json\r\n\r\n" . wp_json_encode($payload) . "\r\n"
            . $parts . "--$boundary--\r\n";
        $response = wp_remote_post('https://discord.com/api/v10' . $path, [
            'timeout' => 60,
            'user-agent' => 'DiscordBot (https://nyassobi.fr, 1.0)',
            'headers' => ['Authorization' => 'Bot ' . ($this->settings()['discord_bot_token'] ?? ''), 'Content-Type' => 'multipart/form-data; boundary=' . $boundary],
            'body' => $body,
        ]);
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log(sprintf('[Nyassobi] Discord a répondu %d sur %s (image)', $code, $path));
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Where announcements go: their own channel, or else the public table's. */
    private function news_channel(): string
    {
        $s = $this->settings();

        return (string) (($s['conventions_news_channel_id'] ?? '') ?: ($s['conventions_summary_channel_id'] ?? ''));
    }

    /**
     * The server's own emojis, by name, to turn « :hypejam: » typed in a form
     * into the real emoji. Cached: they rarely change.
     *
     * @return array<string,string> name => <:name:id> or <a:name:id>
     */
    private function server_emojis(): array
    {
        $cached = get_transient('nyassobi_conv_emojis');
        if (is_array($cached)) {
            return $cached;
        }
        $emojis = [];
        $guild = (string) ($this->settings()['discord_guild_id'] ?? '');
        $list = '' !== $guild ? Nyassobi_Membership::instance()->discord_request('GET', '/guilds/' . rawurlencode($guild) . '/emojis') : null;
        foreach ((array) $list as $emoji) {
            if (isset($emoji['name'], $emoji['id'])) {
                $emojis[(string) $emoji['name']] = sprintf('<%s:%s:%s>', empty($emoji['animated']) ? '' : 'a', $emoji['name'], $emoji['id']);
            }
        }
        set_transient('nyassobi_conv_emojis', $emojis, HOUR_IN_SECONDS);

        return $emojis;
    }

    /** Same text for the website: no emoji codes, mentions or Discord markup. */
    private static function plain_text(string $text, array $emojis): string
    {
        $text = (string) preg_replace('/<a?:\w+:\d+>|<@[&!]?\d+>|<#\d+>/', '', $text);
        $text = (string) preg_replace_callback('/:(\w{2,32}):/', static fn ($m) => isset($emojis[$m[1]]) ? '' : $m[0], $text);
        $text = str_replace(['**', '__', '~~', '`'], '', $text);

        return trim((string) preg_replace('/[ \t]+(\n|$)/', '$1', (string) preg_replace('/ {2,}/', ' ', $text)));
    }

    /**
     * An announcement for members, written like the association's own: a
     * plain message mentioning the member role, on Discord, and the same
     * text on the site.
     */
    public function add_news(int $id, string $text, int $image, bool $ping = true): ?string
    {
        $text = trim(sanitize_textarea_field($text));
        if ('' === $text && ! $image) {
            return __('L\'annonce est vide.', 'nyassobi-wp-plugin');
        }
        if (mb_strlen($text) > 1500) {
            return __('L\'annonce doit tenir en 1 500 caractères.', 'nyassobi-wp-plugin');
        }
        $emojis = $this->server_emojis();
        $item = ['id' => bin2hex(random_bytes(4)), 'date' => time(), 'text' => self::plain_text($text, $emojis), 'image' => $image, 'message' => '', 'channel' => ''];
        $channel = $this->news_channel();
        if ('' !== $channel) {
            $c = $this->convention($id);
            // CA's own markup is kept (bold, line breaks); only typed :emoji:
            // codes are turned into the server's emojis.
            $content = (string) preg_replace_callback('/(?<![<\w]):(\w{2,32}):(?!\d)/', static fn ($m) => $emojis[$m[1]] ?? $m[0], $text);
            if ($c['open']) {
                $content .= "\n\n👉 Pour te proposer : le bouton ci-dessous, ou " . $this->page_url();
            }
            $role = (string) ($this->settings()['discord_member_role_id'] ?? '');
            $mention = $ping && '' !== $role;
            if ($mention) {
                $content .= "\n\n<@&" . $role . '>';
            }
            $posted = $this->discord_post_with_files($channel, [
                'content' => mb_substr($content, 0, 2000),
                'components' => $c['open'] ? [['type' => 1, 'components' => [['type' => 2, 'style' => 1, 'label' => '🙋 Je me propose', 'custom_id' => 'nyvol:start:' . $id]]]] : [],
                // Only the member role may ring, never @everyone typed by mistake.
                'allowed_mentions' => $mention ? ['roles' => [$role]] : ['parse' => []],
            ], $image ? [$image] : []);
            $item['message'] = (string) ($posted['id'] ?? '');
            $item['channel'] = $channel;
        }
        $news = $this->news($id);
        $news[] = $item;
        update_post_meta($id, self::META_NEWS, $news);

        return null;
    }

    private function remove_news(int $id, string $news_id): void
    {
        $default = (string) ($this->settings()['conventions_summary_channel_id'] ?? '');
        $kept = [];
        foreach ($this->news($id) as $item) {
            if ($item['id'] !== $news_id) {
                $kept[] = $item;
                continue;
            }
            if ($item['image']) {
                wp_delete_attachment((int) $item['image'], true);
            }
            $channel = (string) (($item['channel'] ?? '') ?: $default);
            if ('' !== $channel && '' !== $item['message']) {
                Nyassobi_Membership::instance()->discord_request('DELETE', '/channels/' . rawurlencode($channel) . '/messages/' . rawurlencode($item['message']));
            }
        }
        update_post_meta($id, self::META_NEWS, $kept);
    }

    /**
     * Tells a volunteer the CA's decision, in a private message from the bot.
     */
    private function notify_volunteer(int $id, string $discord_id): bool
    {
        $notes = $this->notes($id);
        $status = $notes[$discord_id]['status'] ?? '';
        $person = array_column($this->volunteers($id), null, 'discord_id')[$discord_id] ?? null;
        if (null === $person || ! in_array($status, ['retenu', 'non'], true)) {
            return false;
        }
        $c = $this->convention($id);
        $where = $c['dates'] . ('' !== $c['city'] ? ', ' . $c['city'] : '');
        $days = $this->days_text($id, $person['days']);
        $text = 'retenu' === $status
            ? sprintf("🎉 Bonne nouvelle ! Tu es retenu·e pour **%s** (%s), en %s%s.\nLe CA te recontacte bientôt avec les détails : horaires, rendez-vous, défraiement.\nInfos et annonces : %s\n\n— Le CA de Nyassobi", $c['name'], $where, 'staff' === $person['role'] ? 'staff du stand' : 'animation', '' !== $days ? ' (' . $days . ')' : '', $this->page_url())
            : sprintf("Merci beaucoup pour ta proposition pour **%s** (%s) ! Cette fois, on n'a pas pu te retenir dans l'équipe, mais d'autres conventions arrivent : n'hésite surtout pas à te proposer à nouveau.\n%s\n\n— Le CA de Nyassobi", $c['name'], $where, $this->page_url());

        $message = ['content' => $text, 'allowed_mentions' => ['parse' => []]];
        if ($this->is_selected_animator($id, $discord_id) && ! isset($this->comm($id)[$discord_id])) {
            $message = $this->comm_request($id, $text);
        }
        $sent = $this->send_dm($discord_id, $message);
        if ($sent) {
            $notes[$discord_id]['notified'] = $status;
            update_post_meta($id, self::META_NOTES, $notes);
            $this->update_recap($id);
        }

        return $sent;
    }

    /** CA only. null leaves a field as it is; "-" clears the note. */
    public function set_note(int $id, string $discord_id, ?string $status, ?string $note): ?string
    {
        $known = array_column($this->volunteers($id), 'name', 'discord_id');
        if (! isset($known[$discord_id])) {
            return __('Cette personne ne s\'est pas proposée pour cette convention.', 'nyassobi-wp-plugin');
        }
        $notes = $this->notes($id);
        $entry = $notes[$discord_id] ?? ['status' => '', 'note' => ''];
        if (null !== $status && '' !== $status) {
            $new = isset(self::STATUSES[$status]) ? $status : '';
            if ($new !== ($entry['status'] ?? '')) {
                // A new decision has not been told to the person yet.
                unset($entry['notified']);
            }
            $entry['status'] = $new;
        }
        if (null !== $note) {
            $entry['note'] = '-' === trim($note) ? '' : mb_substr(sanitize_text_field($note), 0, 300);
        }
        $notes[$discord_id] = $entry;
        update_post_meta($id, self::META_NOTES, $notes);
        $this->update_recap($id);

        return null;
    }

    /** @param array<string,mixed> $message */
    private function send_dm(string $discord_id, array $message): bool
    {
        $discord = Nyassobi_Membership::instance();
        $dm = $discord->discord_request('POST', '/users/@me/channels', ['recipient_id' => $discord_id]);

        return isset($dm['id']) && null !== $discord->discord_request('POST', '/channels/' . rawurlencode((string) $dm['id']) . '/messages', $message);
    }

    /* ------------------------------------------------------------------
     * Communication: what the selected animators send
     * ------------------------------------------------------------------ */

    /** @return array<string,array{pronouns:string,languages:string,images:int[],date:int,messages:string[]}> */
    private function comm(int $id): array
    {
        $comm = get_post_meta($id, self::META_COMM, true);

        return is_array($comm) ? $comm : [];
    }

    /** Only animators the CA kept are asked: staff need nothing for the communication. */
    private function is_selected_animator(int $id, string $discord_id): bool
    {
        if (! ctype_digit($discord_id) || 'retenu' !== ($this->notes($id)[$discord_id]['status'] ?? '')) {
            return false;
        }
        foreach ($this->volunteers($id) as $p) {
            if ($p['discord_id'] === $discord_id) {
                return 'animation' === $p['role'];
            }
        }

        return false;
    }

    /** Link to the message with the images, in the organisers' channel. */
    private function comm_link(array $entry): string
    {
        $guild = (string) ($this->settings()['discord_guild_id'] ?? '');
        $channel = (string) ($this->settings()['conventions_channel_id'] ?? '');
        $message = (string) (((array) ($entry['messages'] ?? []))[0] ?? '');

        return '' !== $guild && '' !== $channel && '' !== $message ? sprintf('https://discord.com/channels/%s/%s/%s', $guild, $channel, $message) : '';
    }

    /**
     * The private message asking for the communication infos, with the
     * button that opens the form.
     *
     * @return array<string,mixed>
     */
    private function comm_request(int $id, string $before = ''): array
    {
        $text = sprintf(
            "Pour préparer la comm de **%s**, il nous faut :\n• des images PNG de ton model (fond transparent de préférence)\n• tes pronoms\n• les langues que tu parles\nLe bouton ci-dessous ouvre le formulaire, tu peux y revenir pour corriger. Tout est effacé 30 jours après la convention.",
            Nyassobi_Membership::escape_markdown($this->convention($id)['name'])
        );

        return [
            'content' => ('' !== $before ? $before . "\n\n" : '') . $text,
            'components' => [['type' => 1, 'components' => [['type' => 2, 'style' => 1, 'label' => '🖼️ Envoyer mes infos pour la comm', 'custom_id' => 'nycomm:open:' . $id]]]],
            'allowed_mentions' => ['parse' => []],
        ];
    }

    /** For a person already told they were kept, without the request. */
    private function ask_comm(int $id, string $discord_id): bool
    {
        return $this->is_selected_animator($id, $discord_id) && $this->send_dm($discord_id, $this->comm_request($id));
    }

    /**
     * Saves what an animator sent. New images replace the previous ones; the
     * message in the organisers' channel is posted again.
     *
     * @param int[] $images Already in the media library; erased if refused.
     */
    private function set_comm(int $id, string $discord_id, string $pronouns, string $languages, array $images): ?string
    {
        $all = $this->comm($id);
        $old = $all[$discord_id] ?? null;
        $pronouns = mb_substr(sanitize_text_field($pronouns), 0, 40);
        $languages = mb_substr(sanitize_text_field($languages), 0, 100);
        $error = null;
        if (! $this->is_selected_animator($id, $discord_id)) {
            $error = __('Ce formulaire est réservé aux animateurs retenus pour cette convention.', 'nyassobi-wp-plugin');
        } elseif ('' === $pronouns || '' === $languages) {
            $error = __('Indique tes pronoms et les langues que tu parles.', 'nyassobi-wp-plugin');
        } elseif (! $images && empty($old['images'])) {
            $error = __('Envoie au moins une image PNG de ton model.', 'nyassobi-wp-plugin');
        }
        if (null !== $error) {
            foreach ($images as $image) {
                wp_delete_attachment($image, true);
            }

            return $error;
        }
        if ($images && null !== $old) {
            foreach ($old['images'] as $gone) {
                wp_delete_attachment((int) $gone, true);
            }
        }
        $all[$discord_id] = [
            'pronouns' => $pronouns,
            'languages' => $languages,
            'images' => $images ?: $old['images'],
            'date' => time(),
            'messages' => $old['messages'] ?? [],
        ];
        update_post_meta($id, self::META_COMM, $all);
        $this->post_comm($id, $discord_id);
        $this->update_recap($id);

        return null;
    }

    /**
     * The infos and images in the organisers' channel, as a reply to the
     * convention's recap. Images go in groups below Discord's upload limit.
     */
    private function post_comm(int $id, string $discord_id): void
    {
        $all = $this->comm($id);
        $entry = $all[$discord_id] ?? null;
        $channel = (string) ($this->settings()['conventions_channel_id'] ?? '');
        if (null === $entry) {
            return;
        }
        $discord = Nyassobi_Membership::instance();
        foreach ((array) ($entry['messages'] ?? []) as $old) {
            if ('' !== $channel) {
                $discord->discord_request('DELETE', '/channels/' . rawurlencode($channel) . '/messages/' . rawurlencode((string) $old));
            }
        }
        $entry['messages'] = [];
        if ('' !== $channel) {
            $md = static fn (string $t): string => Nyassobi_Membership::escape_markdown($t);
            $name = array_column($this->volunteers($id), 'name', 'discord_id')[$discord_id] ?? '';
            $text = sprintf("🖼️ **Infos pour la comm** · %s\n**%s** <@%s> · pronoms : %s · langues : %s", $md($this->convention($id)['name']), $md($name), $discord_id, $md($entry['pronouns']), $md($entry['languages']));
            $groups = [[]];
            $size = 0;
            foreach ($entry['images'] as $image) {
                $bytes = (int) @filesize((string) get_attached_file((int) $image));
                if ($groups[count($groups) - 1] && $size + $bytes > self::DISCORD_UPLOAD_BYTES) {
                    $groups[] = [];
                    $size = 0;
                }
                $groups[count($groups) - 1][] = (int) $image;
                $size += $bytes;
            }
            $recap = $this->meta($id, self::META_MESSAGE);
            foreach ($groups as $n => $group) {
                $payload = ['content' => 0 === $n ? $text : '', 'allowed_mentions' => ['parse' => []]];
                if (0 === $n && '' !== $recap) {
                    $payload['message_reference'] = ['message_id' => $recap, 'fail_if_not_exists' => false];
                }
                $posted = $this->discord_post_with_files($channel, $payload, $group);
                if (null === $posted && 0 === $n) {
                    // Images refused by Discord (too heavy): the text still goes.
                    $posted = $discord->discord_request('POST', '/channels/' . rawurlencode($channel) . '/messages', ['content' => $text . "\nImages trop lourdes pour Discord : elles sont dans WordPress, fiche de la convention.", 'allowed_mentions' => ['parse' => []]]);
                }
                if (isset($posted['id'])) {
                    $entry['messages'][] = (string) $posted['id'];
                }
            }
        }
        $all[$discord_id] = $entry;
        update_post_meta($id, self::META_COMM, $all);
    }

    /** When someone withdraws: their images and the CA's message go too. */
    private function forget_comm(int $id, string $discord_id): void
    {
        $all = $this->comm($id);
        if (! isset($all[$discord_id])) {
            return;
        }
        foreach ($all[$discord_id]['images'] as $image) {
            wp_delete_attachment((int) $image, true);
        }
        $channel = (string) ($this->settings()['conventions_channel_id'] ?? '');
        foreach ((array) ($all[$discord_id]['messages'] ?? []) as $message) {
            if ('' !== $channel) {
                Nyassobi_Membership::instance()->discord_request('DELETE', '/channels/' . rawurlencode($channel) . '/messages/' . rawurlencode((string) $message));
            }
        }
        unset($all[$discord_id]);
        update_post_meta($id, self::META_COMM, $all);
    }

    /**
     * The button in the private message, and the form it opens. Clicked in
     * private messages, so no roles: being kept by the CA is the check.
     *
     * @param \WP_REST_Response|null $response
     * @param array<string,mixed>     $payload
     *
     * @return \WP_REST_Response|null
     */
    public function handle_comm($response, array $payload)
    {
        $custom_id = (string) ($payload['data']['custom_id'] ?? '');
        if (null !== $response || 0 !== strpos($custom_id, 'nycomm:')) {
            return $response;
        }
        $membership = Nyassobi_Membership::instance();
        $user = (string) ($payload['user']['id'] ?? ($payload['member']['user']['id'] ?? ''));
        $parts = explode(':', $custom_id);
        $action = $parts[1] ?? '';
        $id = (int) ($parts[2] ?? 0);
        if (! $this->is_upcoming($id)) {
            return $membership->ephemeral(__('Cette convention est terminée ou n\'existe plus.', 'nyassobi-wp-plugin'));
        }
        if (! $this->is_selected_animator($id, $user)) {
            return $membership->ephemeral(__('Ce formulaire est réservé aux animateurs retenus pour cette convention.', 'nyassobi-wp-plugin'));
        }
        $form = 'nycomm:open:' . $id;

        if ('open' === $action) {
            $kept = self::kept_form($user, $form);
            $entry = $this->comm($id)[$user] ?? [];
            $count = count((array) ($entry['images'] ?? []));
            $upload = self::files('images', self::COMM_MAX_IMAGES);
            if (! $count) {
                $upload = ['min_values' => 1, 'required' => true] + $upload;
            }

            return self::modal('nycomm:send:' . $id, 'Infos pour la comm', [
                self::field('Tes pronoms', self::text('pronoms', false, true, 40, self::value($kept, 'pronoms', (string) ($entry['pronouns'] ?? '')), 'elle, il, iel…')),
                self::field('Les langues que tu parles', self::text('langues', false, true, 100, self::value($kept, 'langues', (string) ($entry['languages'] ?? '')), 'Français, anglais…')),
                self::field('Images PNG de ton model', $upload, $count
                    ? sprintf('Tu en as déjà envoyé %d : en mettre de nouvelles les remplace', $count)
                    : sprintf('Fond transparent de préférence, %d au plus, 8 Mo chacune', self::COMM_MAX_IMAGES)),
            ]);
        }
        if ('send' === $action) {
            $values = self::form_values($payload);
            $kept = ['pronoms' => self::value($values, 'pronoms'), 'langues' => self::value($values, 'langues')];

            return $this->later('comm', $kept + [
                'convention' => (string) $id,
                '_attachments' => wp_json_encode(self::sent_files($payload, (array) ($values['images'] ?? []))),
                '_user' => $user,
                '_form' => $form,
                '_kept' => wp_json_encode($kept),
            ], $payload, false);
        }

        return $membership->ephemeral(__('Action inconnue.', 'nyassobi-wp-plugin'));
    }

    /* ------------------------------------------------------------------
     * Signing in with Discord
     * ------------------------------------------------------------------ */

    /** Same Discord application and return address as the membership role button. */
    private function login_ready(): bool
    {
        $s = $this->settings();
        foreach (['discord_client_secret', 'discord_application_id', 'discord_guild_id', 'discord_member_role_id', 'discord_bot_token'] as $key) {
            if ('' === trim((string) ($s[$key] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    private function page_url(string $fragment = ''): string
    {
        return $this->settings()['site_url'] . '/conventions' . ('' !== $fragment ? '#' . $fragment : '');
    }

    public function register_rest_routes(): void
    {
        register_rest_route(Nyassobi_Membership::REST_NAMESPACE, '/conventions/connexion', [
            'methods' => 'GET',
            'callback' => [$this, 'route_login'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function route_login(): void
    {
        if (! $this->login_ready()) {
            wp_redirect($this->page_url('erreur=config'), 302, 'Nyassobi');
            exit;
        }
        // Signed instead of stored: an anonymous visitor hitting this address
        // in a loop must not be able to fill the database.
        $time = (string) time();
        $state = 'c' . $time . '-' . self::state_signature($time);
        wp_redirect('https://discord.com/oauth2/authorize?' . http_build_query([
            'client_id' => $this->settings()['discord_application_id'],
            'response_type' => 'code',
            'redirect_uri' => rest_url(Nyassobi_Membership::REST_NAMESPACE . '/retour/discord'),
            'scope' => 'identify',
            'state' => $state,
            // Skips Discord's screen for people who already authorized once.
            'prompt' => 'none',
        ], '', '&', PHP_QUERY_RFC3986), 302, 'Nyassobi');
        exit;
    }

    private static function state_signature(string $time): string
    {
        return substr(hash_hmac('sha256', 'nyassobi-conventions|' . $time, wp_salt('auth')), 0, 32);
    }

    /** True for a state made by route_login() less than 15 minutes ago. */
    public static function is_login_state(string $state): bool
    {
        if (! preg_match('/^c(\d{10})-([a-f0-9]{32})$/', $state, $m)) {
            return false;
        }

        return hash_equals(self::state_signature($m[1]), $m[2]) && time() - (int) $m[1] <= 15 * MINUTE_IN_SECONDS && (int) $m[1] <= time() + 60;
    }

    /**
     * Called by the shared Discord return route. The session key goes back in
     * the address fragment, which browsers never send to any server.
     */
    public function finish_login(string $code, bool $cancelled): string
    {
        if ($cancelled || '' === $code) {
            return $this->page_url('erreur=annule');
        }
        $s = $this->settings();
        $agent = 'DiscordBot (https://nyassobi.fr, 1.0)';
        $response = wp_remote_post('https://discord.com/api/v10/oauth2/token', [
            'timeout' => 10,
            'user-agent' => $agent,
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => rest_url(Nyassobi_Membership::REST_NAMESPACE . '/retour/discord'),
                'client_id' => $s['discord_application_id'],
                'client_secret' => $s['discord_client_secret'],
            ],
        ]);
        $access = (string) (json_decode((string) wp_remote_retrieve_body($response), true)['access_token'] ?? '');
        if ('' === $access) {
            error_log(sprintf('[Nyassobi] Conventions : connexion Discord refusée (%d).', (int) wp_remote_retrieve_response_code($response)));
            return $this->page_url('erreur=discord');
        }
        $me = json_decode((string) wp_remote_retrieve_body(wp_remote_get('https://discord.com/api/v10/users/@me', [
            'timeout' => 10,
            'user-agent' => $agent,
            'headers' => ['Authorization' => 'Bearer ' . $access],
        ])), true);
        wp_remote_post('https://discord.com/api/v10/oauth2/token/revoke', [
            'timeout' => 5,
            'user-agent' => $agent,
            'body' => ['token' => $access, 'token_type_hint' => 'access_token', 'client_id' => $s['discord_application_id'], 'client_secret' => $s['discord_client_secret']],
        ]);
        $user_id = (string) ($me['id'] ?? '');
        if (! ctype_digit($user_id)) {
            return $this->page_url('erreur=discord');
        }

        // Membership is read on the server itself, with the bot: the person
        // cannot claim it.
        $member = Nyassobi_Membership::instance()->discord_request('GET', '/guilds/' . rawurlencode($s['discord_guild_id']) . '/members/' . $user_id);
        $roles = (array) ($member['roles'] ?? []);
        $is_member = in_array($s['discord_member_role_id'], $roles, true) || in_array($s['discord_board_role_id'] ?? '', $roles, true);

        $key = bin2hex(random_bytes(24));
        set_transient(self::SESSION_PREFIX . $key, [
            'id' => $user_id,
            'name' => sanitize_text_field((string) (($member['nick'] ?? '') ?: (($me['global_name'] ?? '') ?: ($me['username'] ?? '')))),
            'member' => $is_member,
        ], self::SESSION_SECONDS);

        return $this->page_url('session=' . $key);
    }

    /** @return array{id:string,name:string,member:bool}|null */
    private function session(string $key): ?array
    {
        if (! preg_match('/^[a-f0-9]{48}$/', $key)) {
            return null;
        }
        $session = get_transient(self::SESSION_PREFIX . $key);

        return is_array($session) ? $session : null;
    }

    /* ------------------------------------------------------------------
     * GraphQL, for the site's Conventions page
     * ------------------------------------------------------------------ */

    public function register_graphql(): void
    {
        if (! function_exists('register_graphql_object_type')) {
            return;
        }
        register_graphql_object_type('NyassobiConventionDay', [
            'fields' => [
                'date' => ['type' => 'String'],
                'label' => ['type' => 'String', 'description' => 'samedi 17 octobre'],
            ],
        ]);
        register_graphql_object_type('NyassobiConventionNews', [
            'fields' => [
                'date' => ['type' => 'String'],
                'text' => ['type' => 'String'],
                'image' => ['type' => 'String'],
            ],
        ]);
        register_graphql_object_type('NyassobiConvention', [
            'fields' => [
                'days' => ['type' => ['list_of' => 'NyassobiConventionDay']],
                'description' => ['type' => 'String'],
                'link' => ['type' => 'String'],
                'images' => ['type' => ['list_of' => 'String']],
                'news' => ['type' => ['list_of' => 'NyassobiConventionNews'], 'description' => __('Annonces du CA, la plus récente d\'abord.', 'nyassobi-wp-plugin')],
                'id' => ['type' => ['non_null' => 'Int']],
                'name' => ['type' => ['non_null' => 'String']],
                'city' => ['type' => 'String'],
                'dates' => ['type' => 'String'],
                'startDate' => ['type' => 'String'],
                'endDate' => ['type' => 'String'],
                'needs' => ['type' => 'String', 'description' => 'les-deux | staff | animation'],
                'open' => ['type' => 'Boolean'],
            ],
        ]);
        register_graphql_field('RootQuery', 'nyassobiConventions', [
            'type' => ['list_of' => 'NyassobiConvention'],
            'description' => __('Conventions à venir où Nyassobi cherche du staff ou des animateurs.', 'nyassobi-wp-plugin'),
            'resolve' => function (): array {
                return array_map(function (int $id): array {
                    $c = $this->convention($id);
                    $news = array_map(static fn (array $n): array => [
                        'date' => wp_date('j F Y', (int) $n['date']),
                        'text' => $n['text'],
                        'image' => $n['image'] ? self::image_url((int) $n['image']) : null,
                    ], array_reverse($this->news($id)));

                    return [
                        'id' => $id, 'name' => $c['name'], 'city' => $c['city'], 'dates' => $c['dates'], 'startDate' => $c['start'], 'endDate' => $c['end'], 'needs' => $c['needs'], 'open' => $c['open'],
                        'days' => array_map(static fn (string $d): array => ['date' => $d, 'label' => self::day_label($d, true)], $this->days($id)),
                        'description' => $this->meta($id, self::META_DESCRIPTION),
                        'link' => $this->meta($id, self::META_LINK),
                        'images' => array_map([self::class, 'image_url'], $this->images($id)),
                        'news' => $news,
                    ];
                }, $this->upcoming_ids());
            },
        ]);
        register_graphql_field('RootQuery', 'nyassobiConventionsLoginUrl', [
            'type' => 'String',
            'description' => __('Adresse de connexion avec Discord, ou rien si elle n\'est pas configurée.', 'nyassobi-wp-plugin'),
            'resolve' => fn (): ?string => $this->login_ready() ? rest_url(Nyassobi_Membership::REST_NAMESPACE . '/conventions/connexion') : null,
        ]);

        register_graphql_object_type('NyassobiConventionChoice', [
            'fields' => [
                'conventionId' => ['type' => ['non_null' => 'Int']],
                'role' => ['type' => 'String'],
                'travel' => ['type' => 'String'],
                'transport' => ['type' => 'String'],
                'days' => ['type' => ['list_of' => 'String']],
                'slots' => ['type' => ['list_of' => 'String']],
            ],
        ]);
        register_graphql_object_type('NyassobiConventionSession', [
            'fields' => [
                'name' => ['type' => 'String'],
                'member' => ['type' => ['non_null' => 'Boolean']],
                'choices' => ['type' => ['list_of' => 'NyassobiConventionChoice']],
                'animation' => ['type' => 'String'],
                'comment' => ['type' => 'String'],
            ],
        ]);
        register_graphql_field('RootQuery', 'nyassobiConventionSession', [
            'type' => 'NyassobiConventionSession',
            'args' => ['session' => ['type' => ['non_null' => 'String']]],
            'resolve' => function ($root, array $args): ?array {
                $session = $this->session((string) ($args['session'] ?? ''));
                if (null === $session) {
                    return null;
                }
                $rid = $session['member'] ? $this->response_id($session['id']) : 0;
                $choices = [];
                foreach ($rid ? $this->choices($rid) : [] as $cid => $choice) {
                    if ($this->is_upcoming((int) $cid)) {
                        $choices[] = ['conventionId' => (int) $cid, 'days' => $choice['days'] ?? $this->days((int) $cid), 'slots' => $choice['slots'] ?? []] + $choice;
                    }
                }

                return [
                    'name' => $session['name'],
                    'member' => (bool) $session['member'],
                    'choices' => $choices,
                    'animation' => $rid ? $this->meta($rid, self::META_ANIMATION) : '',
                    'comment' => $rid ? $this->meta($rid, self::META_COMMENT) : '',
                ];
            },
        ]);

        if (! function_exists('register_graphql_mutation')) {
            return;
        }
        register_graphql_input_type('NyassobiConventionChoiceInput', [
            'fields' => [
                'conventionId' => ['type' => ['non_null' => 'Int']],
                'role' => ['type' => ['non_null' => 'String']],
                'travel' => ['type' => ['non_null' => 'String']],
                'transport' => ['type' => 'String'],
                'days' => ['type' => ['list_of' => 'String']],
                'slots' => ['type' => ['list_of' => 'String']],
            ],
        ]);
        register_graphql_mutation('submitNyassobiConventionResponse', [
            'inputFields' => [
                'session' => ['type' => ['non_null' => 'String']],
                'choices' => ['type' => ['non_null' => ['list_of' => 'NyassobiConventionChoiceInput']]],
                'animation' => ['type' => 'String'],
                'comment' => ['type' => 'String'],
            ],
            'outputFields' => [
                'success' => ['type' => ['non_null' => 'Boolean'], 'resolve' => static fn ($p): bool => (bool) ($p['success'] ?? false)],
                'message' => ['type' => ['non_null' => 'String'], 'resolve' => static fn ($p): string => (string) ($p['message'] ?? '')],
            ],
            'mutateAndGetPayload' => function (array $input): array {
                $session = $this->session((string) ($input['session'] ?? ''));
                if (null === $session) {
                    return ['success' => false, 'message' => __('Ta connexion a expiré : reconnecte-toi avec Discord.', 'nyassobi-wp-plugin')];
                }
                if (! $session['member']) {
                    return ['success' => false, 'message' => __('Ce formulaire est réservé aux adhérents.', 'nyassobi-wp-plugin')];
                }
                $error = $this->save_response($session, (array) ($input['choices'] ?? []), (string) ($input['animation'] ?? ''), (string) ($input['comment'] ?? ''));
                if (null !== $error) {
                    return ['success' => false, 'message' => $error];
                }

                return ['success' => true, 'message' => [] === (array) ($input['choices'] ?? [])
                    ? __('Ta réponse est retirée. Merci de nous avoir prévenus !', 'nyassobi-wp-plugin')
                    : __('C\'est noté, merci ! Le CA te recontacte sur Discord. Tu peux modifier ta réponse ici jusqu\'à la convention.', 'nyassobi-wp-plugin')];
            },
        ]);
    }

    /* ------------------------------------------------------------------
     * WordPress admin
     * ------------------------------------------------------------------ */

    public function register_metaboxes(): void
    {
        add_meta_box('nyassobi_conv_details', __('Dates et besoins', 'nyassobi-wp-plugin'), [$this, 'render_details'], self::POST_TYPE, 'normal', 'high');
        add_meta_box('nyassobi_conv_infos', __('Infos pour les adhérents', 'nyassobi-wp-plugin'), [$this, 'render_infos'], self::POST_TYPE, 'normal');
        add_meta_box('nyassobi_conv_news', __('Annonces', 'nyassobi-wp-plugin'), [$this, 'render_news'], self::POST_TYPE, 'normal');
        add_meta_box('nyassobi_conv_volunteers', __('Volontaires', 'nyassobi-wp-plugin'), [$this, 'render_volunteers'], self::POST_TYPE, 'normal');
    }

    public function render_details(\WP_Post $post): void
    {
        $c = $this->convention($post->ID);
        $is_new = '' === $c['start'];
        wp_nonce_field('nyassobi_conv_save', 'nyassobi_conv_nonce');
        echo '<table class="form-table" role="presentation"><tbody>';
        printf('<tr><th scope="row"><label for="nyassobi_conv_debut">%s</label></th><td><input type="date" id="nyassobi_conv_debut" name="nyassobi_conv_debut" value="%s" required></td></tr>', esc_html__('Premier jour', 'nyassobi-wp-plugin'), esc_attr($c['start']));
        printf('<tr><th scope="row"><label for="nyassobi_conv_fin">%s</label></th><td><input type="date" id="nyassobi_conv_fin" name="nyassobi_conv_fin" value="%s"> <span class="description">%s</span></td></tr>', esc_html__('Dernier jour', 'nyassobi-wp-plugin'), esc_attr($is_new ? '' : $c['end']), esc_html__('Vide pour une seule journée.', 'nyassobi-wp-plugin'));
        printf('<tr><th scope="row"><label for="nyassobi_conv_ville">%s</label></th><td><input type="text" id="nyassobi_conv_ville" name="nyassobi_conv_ville" value="%s" class="regular-text" maxlength="80"></td></tr>', esc_html__('Ville', 'nyassobi-wp-plugin'), esc_attr($c['city']));
        echo '<tr><th scope="row"><label for="nyassobi_conv_besoins">' . esc_html__('On cherche', 'nyassobi-wp-plugin') . '</label></th><td><select id="nyassobi_conv_besoins" name="nyassobi_conv_besoins">';
        foreach (self::NEEDS as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($c['needs'], $value, false), esc_html($label));
        }
        echo '</select></td></tr>';
        printf('<tr><th scope="row">%s</th><td><label><input type="checkbox" name="nyassobi_conv_ouverte" value="1"%s> %s</label></td></tr>', esc_html__('Inscriptions', 'nyassobi-wp-plugin'), checked($is_new || $c['open'], true, false), esc_html__('Ouvertes (décocher quand l\'équipe est complète)', 'nyassobi-wp-plugin'));
        echo '</tbody></table>';
    }

    public function render_infos(\WP_Post $post): void
    {
        echo '<p class="description">' . esc_html__('Visible sur la page Conventions du site. Le CA peut aussi le faire sur Discord, avec le bouton 📝 Infos sous le récapitulatif.', 'nyassobi-wp-plugin') . '</p>';
        printf('<p><label for="nyassobi_conv_description"><strong>%s</strong></label><br><textarea id="nyassobi_conv_description" name="nyassobi_conv_description" rows="5" class="large-text" maxlength="2000">%s</textarea></p>', esc_html__('Description (horaires, stand, ce qu\'on attend des bénévoles…)', 'nyassobi-wp-plugin'), esc_textarea($this->meta($post->ID, self::META_DESCRIPTION)));
        printf('<p><label for="nyassobi_conv_lien"><strong>%s</strong></label><br><input type="url" id="nyassobi_conv_lien" name="nyassobi_conv_lien" value="%s" class="large-text" placeholder="https://"></p>', esc_html__('Site de la convention', 'nyassobi-wp-plugin'), esc_attr($this->meta($post->ID, self::META_LINK)));
        $images = $this->images($post->ID);
        if ($images) {
            echo '<div style="display:flex;flex-wrap:wrap;gap:12px">';
            foreach ($images as $image) {
                printf('<label style="display:grid;gap:4px;text-align:center"><img src="%s" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:6px"><span><input type="checkbox" name="nyassobi_conv_retirer[]" value="%d"> %s</span></label>', esc_url((string) wp_get_attachment_image_url($image, 'thumbnail')), $image, esc_html__('Retirer', 'nyassobi-wp-plugin'));
            }
            echo '</div>';
        }
        printf('<p><label for="nyassobi_conv_images"><strong>%s</strong></label><br><input type="file" id="nyassobi_conv_images" name="nyassobi_conv_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple> <span class="description">%s</span></p>', esc_html__('Ajouter une affiche ou des photos', 'nyassobi-wp-plugin'), esc_html(sprintf(__('%d images au plus, 8 Mo chacune.', 'nyassobi-wp-plugin'), self::MAX_IMAGES)));
    }

    public function render_news(\WP_Post $post): void
    {
        $news = array_reverse($this->news($post->ID));
        if ($news) {
            echo '<table class="widefat striped"><tbody>';
            foreach ($news as $item) {
                printf('<tr><td style="width:120px">%s</td><td>%s%s</td><td style="width:90px"><label><input type="checkbox" name="nyassobi_conv_annonce_retirer[]" value="%s"> %s</label></td></tr>',
                    esc_html(wp_date('j M Y', (int) $item['date'])),
                    nl2br(esc_html($item['text'])),
                    $item['image'] ? sprintf('<br><img src="%s" alt="" style="max-width:160px;margin-top:6px;border-radius:6px">', esc_url((string) wp_get_attachment_image_url((int) $item['image'], 'medium'))) : '',
                    esc_attr($item['id']),
                    esc_html__('Supprimer', 'nyassobi-wp-plugin')
                );
            }
            echo '</tbody></table>';
        }
        printf('<p><label for="nyassobi_conv_annonce"><strong>%s</strong></label><br><textarea id="nyassobi_conv_annonce" name="nyassobi_conv_annonce" rows="3" class="large-text" maxlength="1500"></textarea></p>', esc_html__('Nouvelle annonce', 'nyassobi-wp-plugin'));
        printf('<p><input type="file" name="nyassobi_conv_annonce_image" accept="image/jpeg,image/png,image/webp,image/gif"> <span class="description">%s</span></p>', esc_html__('Image facultative. Publiée à l\'enregistrement, sur le site et dans le salon des annonces de Discord.', 'nyassobi-wp-plugin'));
        printf('<p><label><input type="checkbox" name="nyassobi_conv_annonce_ping" value="1" checked> %s</label></p>', esc_html__('Mentionner le rôle Adhérent (notification pour tous les adhérents)', 'nyassobi-wp-plugin'));
    }

    public function render_volunteers(\WP_Post $post): void
    {
        $people = $this->volunteers($post->ID);
        if (! $people) {
            echo '<p>' . esc_html__('Pas encore de volontaire.', 'nyassobi-wp-plugin') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr>';
        $notes = $this->notes($post->ID);
        foreach ([__('Pseudo', 'nyassobi-wp-plugin'), __('Rôle', 'nyassobi-wp-plugin'), __('Jours', 'nyassobi-wp-plugin'), __('Trajet', 'nyassobi-wp-plugin'), __('Transport', 'nyassobi-wp-plugin'), __('Horaires préférés', 'nyassobi-wp-plugin'), __('Animation proposée', 'nyassobi-wp-plugin'), __('Commentaire', 'nyassobi-wp-plugin'), __('Décision du CA', 'nyassobi-wp-plugin'), __('Note du CA', 'nyassobi-wp-plugin'), __('Infos pour la comm', 'nyassobi-wp-plugin')] as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        $comm = $this->comm($post->ID);
        foreach ($people as $p) {
            $note = $notes[$p['discord_id']] ?? ['status' => '', 'note' => ''];
            $entry = $comm[$p['discord_id']] ?? null;
            $comm_cell = '';
            if (null !== $entry) {
                $comm_cell = esc_html(sprintf(__('Pronoms : %1$s · Langues : %2$s', 'nyassobi-wp-plugin'), $entry['pronouns'], $entry['languages'])) . '<br>';
                foreach ($entry['images'] as $image) {
                    $comm_cell .= sprintf('<a href="%s" target="_blank" rel="noopener"><img src="%s" alt="" style="width:64px;height:64px;object-fit:contain;margin:4px 4px 0 0;background:#eee;border-radius:4px"></a>', esc_url((string) wp_get_attachment_url((int) $image)), esc_url((string) wp_get_attachment_image_url((int) $image, 'thumbnail')));
                }
            } elseif ($this->is_selected_animator($post->ID, $p['discord_id'])) {
                $comm_cell = esc_html__('En attente', 'nyassobi-wp-plugin');
            }
            $select = '<select name="nyassobi_conv_notes[' . esc_attr($p['discord_id']) . '][status]"><option value="">—</option>';
            foreach (self::STATUSES as $value => $label) {
                $select .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), selected($note['status'], $value, false), esc_html($label));
            }
            $select .= '</select>';
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><input type="text" name="nyassobi_conv_notes[%s][note]" value="%s" maxlength="300" class="regular-text"></td><td>%s</td></tr>', esc_html($p['name']), esc_html(self::ROLES[$p['role']] ?? $p['role']), esc_html($this->days_text($post->ID, $p['days']) ?: '—'), esc_html('staff' === $p['role'] ? (self::TRAVEL[$p['travel']] ?? $p['travel']) : 'à distance'), esc_html($p['transport']), esc_html(self::slots_text($p['slots'])), 'staff' !== $p['role'] ? esc_html($p['animation']) : '', esc_html($p['comment']), $select, esc_attr($p['discord_id']), esc_attr($note['note']), $comm_cell);
        }
        echo '</tbody></table>';
        printf('<p class="description">%s</p>', esc_html__('Décision et note restent internes au CA : elles apparaissent dans le récapitulatif des orgas, jamais pour le volontaire.', 'nyassobi-wp-plugin'));
        printf('<p><a class="button" href="%s">%s</a></p>', esc_url(wp_nonce_url(admin_url('admin-post.php?action=nyassobi_conventions_export&convention=' . $post->ID), 'nyassobi_conv_export_' . $post->ID)), esc_html__('Exporter pour Excel', 'nyassobi-wp-plugin'));
    }

    public function save_convention(int $post_id, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE || ! isset($_POST['nyassobi_conv_nonce']) || ! wp_verify_nonce((string) $_POST['nyassobi_conv_nonce'], 'nyassobi_conv_save') || ! current_user_can(Nyassobi_Membership::CAP_ADHESIONS)) {
            return;
        }
        $start = self::parse_date((string) wp_unslash($_POST['nyassobi_conv_debut'] ?? '')) ?? '';
        $end = self::parse_date((string) wp_unslash($_POST['nyassobi_conv_fin'] ?? '')) ?? $start;
        update_post_meta($post_id, self::META_START, $start);
        update_post_meta($post_id, self::META_END, max($start, $end));
        update_post_meta($post_id, self::META_CITY, mb_substr(sanitize_text_field((string) wp_unslash($_POST['nyassobi_conv_ville'] ?? '')), 0, 80));
        $needs = (string) ($_POST['nyassobi_conv_besoins'] ?? '');
        update_post_meta($post_id, self::META_NEEDS, isset(self::NEEDS[$needs]) ? $needs : 'les-deux');
        update_post_meta($post_id, self::META_OPEN, empty($_POST['nyassobi_conv_ouverte']) ? '0' : '1');

        $upload = static function (string $field, int $index = -1): ?array {
            $f = $_FILES[$field] ?? null;
            if (! is_array($f)) {
                return null;
            }
            $error = $index >= 0 ? ($f['error'][$index] ?? 4) : ($f['error'] ?? 4);
            $tmp = $index >= 0 ? ($f['tmp_name'][$index] ?? '') : ($f['tmp_name'] ?? '');
            $name = $index >= 0 ? ($f['name'][$index] ?? 'image') : ($f['name'] ?? 'image');
            if (UPLOAD_ERR_OK !== (int) $error || ! is_uploaded_file((string) $tmp)) {
                return null;
            }

            return ['bytes' => (string) file_get_contents((string) $tmp), 'name' => (string) $name];
        };
        $retirer = array_map('intval', (array) ($_POST['nyassobi_conv_retirer'] ?? []));
        $images = array_values(array_diff($this->images($post_id), $retirer));
        foreach (array_intersect($this->images($post_id), $retirer) as $gone) {
            wp_delete_attachment($gone, true);
        }
        update_post_meta($post_id, self::META_IMAGES, $images);
        $new_images = [];
        foreach (array_keys((array) ($_FILES['nyassobi_conv_images']['name'] ?? [])) as $i) {
            $file = $upload('nyassobi_conv_images', (int) $i);
            $stored = $file ? $this->store_image($post_id, $file['bytes'], $file['name']) : null;
            if (is_int($stored)) {
                $new_images[] = $stored;
            }
        }
        if (null !== $this->set_info($post_id, (string) wp_unslash($_POST['nyassobi_conv_description'] ?? ''), (string) wp_unslash($_POST['nyassobi_conv_lien'] ?? ''), $new_images, false)) {
            // Refused (bad link, too many images): nothing changed, so the
            // new files must not stay in the media library.
            foreach ($new_images as $image) {
                wp_delete_attachment($image, true);
            }
        }

        $notes = $this->notes($post_id);
        foreach ((array) ($_POST['nyassobi_conv_notes'] ?? []) as $discord_id => $entry) {
            if (ctype_digit((string) $discord_id) && is_array($entry)) {
                $status = (string) ($entry['status'] ?? '');
                $status = isset(self::STATUSES[$status]) ? $status : '';
                $before = $notes[(string) $discord_id] ?? [];
                // « Prévenu·e » stays, unless the decision changed.
                $notes[(string) $discord_id] = [
                    'status' => $status,
                    'note' => mb_substr(sanitize_text_field((string) wp_unslash($entry['note'] ?? '')), 0, 300),
                ] + ($status === ($before['status'] ?? '') && ! empty($before['notified']) ? ['notified' => $before['notified']] : []);
            }
        }
        update_post_meta($post_id, self::META_NOTES, $notes);

        foreach ((array) ($_POST['nyassobi_conv_annonce_retirer'] ?? []) as $news_id) {
            $this->remove_news($post_id, sanitize_key((string) $news_id));
        }
        $text = (string) wp_unslash($_POST['nyassobi_conv_annonce'] ?? '');
        $file = $upload('nyassobi_conv_annonce_image');
        if ('' !== trim($text) || $file) {
            $stored = $file ? $this->store_image($post_id, $file['bytes'], $file['name']) : 0;
            $this->add_news($post_id, $text, is_int($stored) ? $stored : 0, ! empty($_POST['nyassobi_conv_annonce_ping']));
        }

        if ('publish' === $post->post_status) {
            $this->update_recap($post_id);
        }
        $this->update_summary();
    }

    /** @param array<string,string> $columns */
    public function admin_columns(array $columns): array
    {
        return ['cb' => $columns['cb'] ?? '', 'title' => __('Convention', 'nyassobi-wp-plugin'), 'nyassobi_dates' => __('Dates', 'nyassobi-wp-plugin'), 'nyassobi_ville' => __('Ville', 'nyassobi-wp-plugin'), 'nyassobi_volontaires' => __('Volontaires', 'nyassobi-wp-plugin'), 'nyassobi_etat' => __('Inscriptions', 'nyassobi-wp-plugin')];
    }

    public function render_admin_column(string $column, int $post_id): void
    {
        $c = $this->convention($post_id);
        $values = [
            'nyassobi_dates' => $c['dates'],
            'nyassobi_ville' => $c['city'],
            'nyassobi_volontaires' => (string) count($this->volunteers($post_id)),
            'nyassobi_etat' => $c['open'] ? __('ouvertes', 'nyassobi-wp-plugin') : __('fermées', 'nyassobi-wp-plugin'),
        ];
        echo esc_html($values[$column] ?? '');
    }

    /** The commands are installed once, from the conventions list. */
    public function commands_notice(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || 'edit-' . self::POST_TYPE !== $screen->id || ! current_user_can(Nyassobi_Membership::CAP_ADHESIONS)) {
            return;
        }
        if (isset($_GET['nyassobi_commandes'])) {
            $ok = '1' === $_GET['nyassobi_commandes'];
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $ok ? 'success' : 'error', esc_html($ok ? __('Commandes installées, et panneau du CA publié dans le salon des orgas.', 'nyassobi-wp-plugin') : __('Discord a refusé l\'installation : vérifiez l\'ID de l\'application et du serveur dans Adhésions > Réglages.', 'nyassobi-wp-plugin')));
        }
        $s = $this->settings();
        $missing = '' === ($s['conventions_channel_id'] ?? '') ? __('Renseignez le salon des orgas dans Adhésions > Réglages pour recevoir les récapitulatifs. ', 'nyassobi-wp-plugin') : '';
        printf(
            '<div class="notice notice-info"><p>%s%s <a class="button" href="%s">%s</a></p></div>',
            esc_html($missing),
            esc_html__('Les membres du CA peuvent aussi gérer les conventions depuis Discord.', 'nyassobi-wp-plugin'),
            esc_url(wp_nonce_url(admin_url('admin-post.php?action=nyassobi_conventions_commands'), 'nyassobi_conv_commands')),
            esc_html__('Installer ou mettre à jour les commandes et le panneau Discord', 'nyassobi-wp-plugin')
        );
    }

    public function handle_install_commands(): void
    {
        if (! current_user_can(Nyassobi_Membership::CAP_ADHESIONS) || ! check_admin_referer('nyassobi_conv_commands')) {
            wp_die(esc_html__('Action non autorisée.', 'nyassobi-wp-plugin'));
        }
        $ok = $this->install_commands();
        $this->refresh_discord();
        wp_safe_redirect(add_query_arg('nyassobi_commandes', $ok ? '1' : '0', admin_url('edit.php?post_type=' . self::POST_TYPE)));
        exit;
    }

    public function export(): void
    {
        $id = (int) ($_GET['convention'] ?? 0);
        if (! current_user_can(Nyassobi_Membership::CAP_ADHESIONS) || ! check_admin_referer('nyassobi_conv_export_' . $id) || self::POST_TYPE !== get_post_type($id)) {
            wp_die(esc_html__('Action non autorisée.', 'nyassobi-wp-plugin'));
        }
        $notes = $this->notes($id);
        $comm = $this->comm($id);
        $rows = [['Pseudo', 'Rôle', 'Jours', 'Trajet', 'Transport', 'Horaires préférés', 'Animation proposée', 'Commentaire', 'Décision du CA', 'Note du CA', 'Pronoms', 'Langues', 'Images pour la comm']];
        foreach ($this->volunteers($id) as $p) {
            $note = $notes[$p['discord_id']] ?? [];
            $entry = $comm[$p['discord_id']] ?? ['pronouns' => '', 'languages' => '', 'images' => []];
            $rows[] = [$p['name'], self::ROLES[$p['role']] ?? $p['role'], implode(', ', array_map([self::class, 'day_label'], $p['days'])), 'staff' === $p['role'] ? (self::TRAVEL[$p['travel']] ?? $p['travel']) : 'à distance', $p['transport'], self::slots_text($p['slots']), 'staff' !== $p['role'] ? $p['animation'] : '', $p['comment'], self::STATUSES[$note['status'] ?? ''] ?? '', (string) ($note['note'] ?? ''), $entry['pronouns'], $entry['languages'], implode(' ', array_map(static fn ($image): string => (string) wp_get_attachment_url((int) $image), $entry['images']))];
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="volontaires-' . sanitize_file_name(get_the_title($id)) . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            // A cell starting with = + - @ would be run as a formula by Excel.
            $row = array_map(static fn (string $cell): string => preg_match('/^[=+\-@]/', $cell) ? "'" . $cell : $cell, $row);
            fputcsv($out, $row, ';', '"', '\\', "\r\n");
        }
        fclose($out);
        exit;
    }

    /* ------------------------------------------------------------------
     * Erasure
     * ------------------------------------------------------------------ */

    /** A deleted convention takes its Discord recap and its answers with it. */
    public function on_delete(int $post_id): void
    {
        if (self::POST_TYPE !== get_post_type($post_id)) {
            return;
        }
        $channel = (string) ($this->settings()['conventions_channel_id'] ?? '');
        $message = $this->meta($post_id, self::META_MESSAGE);
        if ('' !== $channel && '' !== $message) {
            Nyassobi_Membership::instance()->discord_request('DELETE', '/channels/' . rawurlencode($channel) . '/messages/' . rawurlencode($message));
        }
        foreach ($this->news($post_id) as $item) {
            $this->remove_news($post_id, $item['id']);
        }
        foreach (array_keys($this->comm($post_id)) as $discord_id) {
            $this->forget_comm($post_id, (string) $discord_id);
        }
        // Posters, photos, announcement and model images go with the convention.
        foreach (get_children(['post_parent' => $post_id, 'post_type' => 'attachment', 'fields' => 'ids']) as $attachment) {
            wp_delete_attachment((int) $attachment, true);
        }
        foreach (get_posts(['post_type' => self::RESPONSE_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1]) as $rid) {
            $choices = $this->choices((int) $rid);
            if (! isset($choices[$post_id])) {
                continue;
            }
            unset($choices[$post_id]);
            if ($choices) {
                update_post_meta((int) $rid, self::META_CHOICES, $choices);
            } else {
                wp_delete_post((int) $rid, true);
            }
        }
    }

    /** Daily: everything about a convention goes 30 days after it ends. */
    public function purge(): void
    {
        $ids = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 50,
            'meta_query' => [['key' => self::META_END, 'value' => wp_date('Y-m-d', time() - self::RETENTION_DAYS * DAY_IN_SECONDS), 'compare' => '<']],
        ]);
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }
        // Also drops conventions that just ended from the public table.
        $this->update_summary();
    }
}
