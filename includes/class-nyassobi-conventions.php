<?php
/**
 * Staff and animators for the conventions where Nyassobi holds a stand.
 *
 * Flow: the CA lists the season's conventions (in WordPress, or with slash
 * commands on Discord). Members sign in on the site with Discord, which
 * proves they hold the "Adhérent" role, and say for each convention whether
 * they can come as staff, to run an animation, or both, with their travel
 * time. Each convention has a recap message in a private Discord channel,
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
     * @return array<int,array{discord_id:string,name:string,role:string,travel:string,transport:string,animation:string,comment:string,since:int}>
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
            $travel = (string) ($raw['travel'] ?? '');
            if (! isset(self::TRAVEL[$travel])) {
                return sprintf(__('Indique ton temps de trajet pour %s.', 'nyassobi-wp-plugin'), $convention['name']);
            }
            $transport = sanitize_text_field((string) ($raw['transport'] ?? ''));
            if (mb_strlen($transport) > 80) {
                return __('Le moyen de transport doit tenir en 80 caractères.', 'nyassobi-wp-plugin');
            }
            $choices[$cid] = ['role' => $role, 'travel' => $travel, 'transport' => $transport];
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
        }
        foreach (array_unique(array_merge(array_keys($before), array_keys($choices))) as $cid) {
            $this->update_recap((int) $cid);
        }
        $this->update_summary();

        return null;
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

        $blocks = [];
        foreach ($people as $p) {
            $note = $notes[$p['discord_id']] ?? [];
            $block = sprintf('%s**%s** <@%s> · %s · 🚗 %s%s',
                isset(self::STATUSES[$note['status'] ?? '']) ? mb_substr(self::STATUSES[$note['status']], 0, 1) . ' ' : '',
                $md($p['name']),
                $p['discord_id'],
                self::ROLES[$p['role']] ?? $p['role'],
                self::TRAVEL[$p['travel']] ?? $p['travel'],
                '' !== $p['transport'] ? ', ' . $md($p['transport']) : ''
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
        if ($people) {
            $notes = $this->notes($id);
            $options = [];
            foreach (array_slice($people, 0, 25) as $p) {
                $status = self::STATUSES[$notes[$p['discord_id']]['status'] ?? ''] ?? '';
                $options[] = [
                    'label' => mb_substr($p['name'], 0, 100) ?: 'Sans pseudo',
                    'value' => $p['discord_id'],
                    'description' => mb_substr((self::ROLES[$p['role']] ?? '') . ' · ' . (self::TRAVEL[$p['travel']] ?? '') . ('' !== $status ? ' · ' . $status : ''), 0, 100),
                ];
            }
            $rows[] = ['type' => 1, 'components' => [['type' => 3, 'custom_id' => 'nyconv:pick:' . $id, 'placeholder' => 'Noter un volontaire…', 'options' => $options]]];
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
                    . "Sous chaque récapitulatif : **📝 Infos** (description et lien, visibles des adhérents), **📣 Annonce**, **🔒 Fermer / 🔓 Rouvrir**, **🗑️ Supprimer**, et le menu **Noter un volontaire…** (décision et note, visibles du CA seulement).\n\n"
                    . "Pour ajouter une affiche ou des photos : `/convention-infos` avec l'image en pièce jointe, ou WordPress.",
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
            $body .= "\n\nTu veux aider sur le stand ou proposer une animation ? Propose-toi ici : " . $this->page_url();
        }

        return [
            'embeds' => [[
                'title' => '🎪 Conventions à venir',
                'description' => $body,
                'color' => 0xE8622F,
                'footer' => ['text' => 'Mis à jour automatiquement'],
            ]],
            'allowed_mentions' => ['parse' => []],
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
                $attachment = $payload['data']['resolved']['attachments'][$options['image'] ?? ''] ?? null;
                if (is_array($attachment)) {
                    // Downloading an image can take longer than Discord waits:
                    // answer "thinking…" now, finish in the background.
                    $token = (string) ($payload['token'] ?? '');
                    wp_schedule_single_event(time(), self::ATTACHMENT_HOOK, [$name, $options + ['_attachment' => wp_json_encode(array_intersect_key($attachment, array_flip(['url', 'filename', 'size', 'content_type'])))], $token]);
                    spawn_cron();

                    return new \WP_REST_Response(['type' => 5, 'data' => ['flags' => 64]], 200);
                }

                return $membership->ephemeral($this->run_ca_command($name, $id, $options, null));

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
     * The three CA commands that add content. Shared by the immediate answer
     * and by finish_command() when an image had to be downloaded first.
     *
     * @param array<string,string>               $options
     * @param array{bytes:string,name:string}|null $image
     */
    private function run_ca_command(string $name, int $id, array $options, ?array $image): string
    {
        $c = $this->convention($id);
        $image_id = 0;
        if (null !== $image) {
            $stored = $this->store_image($id, $image['bytes'], $image['name']);
            if (is_string($stored)) {
                return $stored;
            }
            $image_id = $stored;
        }

        if ('convention-note' === $name) {
            $error = $this->set_note($id, (string) ($options['personne'] ?? ''), $options['statut'] ?? null, $options['note'] ?? null);

            return $error ?? sprintf(__('Note enregistrée pour %s, visible dans le récapitulatif des orgas.', 'nyassobi-wp-plugin'), $c['name']);
        }
        if ('convention-annonce' === $name) {
            $error = $this->add_news($id, (string) ($options['texte'] ?? ''), $image_id, ! isset($options['mentionner']) || '' !== $options['mentionner']);

            return $error ?? sprintf(__('Annonce publiée pour %s : sur la page Conventions du site et dans le salon des annonces.', 'nyassobi-wp-plugin'), $c['name']);
        }

        $error = $this->set_info($id, $options['texte'] ?? null, $options['lien'] ?? null, $image_id ? [$image_id] : [], ! empty($options['retirer-images']) && 'false' !== $options['retirer-images']);

        return $error ?? sprintf(__('Infos de %s mises à jour sur la page Conventions du site.', 'nyassobi-wp-plugin'), $c['name']);
    }

    /**
     * Background part of a command with an image: download it from Discord,
     * then replace Discord's "thinking…" with the result.
     *
     * @param array<string,string> $options
     */
    public function finish_command(string $name, array $options, string $token): void
    {
        $attachment = json_decode((string) ($options['_attachment'] ?? ''), true);
        unset($options['_attachment']);
        $id = (int) ($options['convention'] ?? 0);
        $image = is_array($attachment) ? $this->fetch_attachment($attachment) : __('Image illisible.', 'nyassobi-wp-plugin');
        $result = ! $this->is_upcoming($id)
            ? __('Convention introuvable.', 'nyassobi-wp-plugin')
            : (is_string($image) ? $image : $this->run_ca_command($name, $id, $options, $image));

        $app = (string) ($this->settings()['discord_application_id'] ?? '');
        if ('' !== $app && preg_match('/^[A-Za-z0-9._-]+$/', $token)) {
            wp_remote_request('https://discord.com/api/v10/webhooks/' . rawurlencode($app) . '/' . $token . '/messages/@original', [
                'method' => 'PATCH',
                'timeout' => 10,
                'user-agent' => 'DiscordBot (https://nyassobi.fr, 1.0)',
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode(['content' => $result, 'allowed_mentions' => ['parse' => []]]),
            ]);
        }
    }

    /* ------------------------------------------------------------------
     * Buttons, menus and forms, for the CA
     * ------------------------------------------------------------------ */

    /** @return array<string,mixed> */
    private static function text_input(string $id, string $label, bool $long, bool $required, int $max, string $value = '', string $placeholder = ''): array
    {
        $input = ['type' => 4, 'custom_id' => $id, 'label' => mb_substr($label, 0, 45), 'style' => $long ? 2 : 1, 'required' => $required, 'max_length' => $max];
        if ('' !== $value) {
            $input['value'] = mb_substr($value, 0, $max);
        }
        if ('' !== $placeholder) {
            $input['placeholder'] = mb_substr($placeholder, 0, 100);
        }

        return ['type' => 1, 'components' => [$input]];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function modal(string $id, string $title, array $rows): \WP_REST_Response
    {
        return new \WP_REST_Response(['type' => 9, 'data' => ['custom_id' => $id, 'title' => mb_substr($title, 0, 45), 'components' => $rows]], 200);
    }

    /** « staff », « animation », « les deux »… as typed in a form. */
    private static function parse_needs(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $staff = false !== strpos($text, 'staff');
        $anim = false !== strpos($text, 'anim');
        if ('' === $text || false !== strpos($text, 'deux') || ($staff && $anim)) {
            return 'les-deux';
        }

        return $anim ? 'animation' : ($staff ? 'staff' : 'les-deux');
    }

    /**
     * What the CA sees after picking a volunteer: the profile, and buttons
     * for the decision and a note.
     *
     * @return array{content:string,components:array<int,array<string,mixed>>}
     */
    private function volunteer_card(int $id, string $discord_id): array
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
            sprintf('%s · 🚗 %s%s', self::ROLES[$person['role']] ?? '', self::TRAVEL[$person['travel']] ?? '', '' !== $person['transport'] ? ', ' . $md($person['transport']) : ''),
        ];
        if ('staff' !== $person['role'] && '' !== $person['animation']) {
            $lines[] = '🎤 ' . $md(mb_substr($person['animation'], 0, 500));
        }
        if ('' !== $person['comment']) {
            $lines[] = '💬 ' . $md(mb_substr($person['comment'], 0, 500));
        }
        $lines[] = 'Décision : ' . (self::STATUSES[$note['status']] ?? 'aucune');
        if ('' !== $note['note']) {
            $lines[] = '📝 ' . $md($note['note']);
        }
        $base = 'nyconv:status:' . $id . ':' . $discord_id . ':';

        return [
            'content' => implode("\n", $lines),
            'components' => [[
                'type' => 1,
                'components' => [
                    ['type' => 2, 'style' => 3, 'label' => '✅ Retenir', 'custom_id' => $base . 'retenu'],
                    ['type' => 2, 'style' => 2, 'label' => '⏳ En attente', 'custom_id' => $base . 'attente'],
                    ['type' => 2, 'style' => 4, 'label' => '❌ Écarter', 'custom_id' => $base . 'non'],
                    ['type' => 2, 'style' => 1, 'label' => '📝 Note', 'custom_id' => 'nyconv:note:' . $id . ':' . $discord_id],
                ],
            ]],
        ];
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

        // A form sent back: its fields by name (both Discord layouts).
        $values = [];
        foreach ((array) ($payload['data']['components'] ?? []) as $row) {
            foreach (isset($row['component']) ? [$row['component']] : (array) ($row['components'] ?? []) as $field) {
                $values[(string) ($field['custom_id'] ?? '')] = (string) ($field['value'] ?? '');
            }
        }

        if (! in_array($action, ['add', 'add-modal', 'list'], true) && ! $this->is_upcoming($id)) {
            return $membership->ephemeral(__('Cette convention n\'existe plus.', 'nyassobi-wp-plugin'));
        }
        $c = $id ? $this->convention($id) : null;
        $update = static fn (array $card): \WP_REST_Response => new \WP_REST_Response(['type' => 7, 'data' => $card + ['allowed_mentions' => ['parse' => []]]], 200);

        switch ($action) {
            case 'add':
                return self::modal('nyconv:add-modal', 'Nouvelle convention', [
                    self::text_input('nom', 'Nom de la convention', false, true, 80),
                    self::text_input('ville', 'Ville', false, true, 80),
                    self::text_input('debut', 'Premier jour', false, true, 10, '', '03/10/2026'),
                    self::text_input('fin', 'Dernier jour (si plusieurs jours)', false, false, 10, '', '04/10/2026'),
                    self::text_input('besoins', 'On cherche : staff, animation ou les deux', false, false, 20, 'les deux'),
                ]);

            case 'add-modal':
                $new = $this->create($values['nom'] ?? '', $values['ville'] ?? '', $values['debut'] ?? '', $values['fin'] ?? '', self::parse_needs($values['besoins'] ?? ''));
                if (is_string($new)) {
                    return $membership->ephemeral($new . ' ' . __('Reclique sur « Nouvelle convention » pour recommencer.', 'nyassobi-wp-plugin'));
                }
                $made = $this->convention($new);
                return $membership->ephemeral(sprintf(__('Convention ajoutée : %1$s, %2$s. Son récapitulatif, avec ses boutons, vient d\'apparaître dans ce salon.', 'nyassobi-wp-plugin'), $made['name'], $made['dates']));

            case 'list':
                $lines = [];
                foreach ($this->upcoming_ids() as $cid) {
                    $item = $this->convention($cid);
                    $lines[] = sprintf('• **%s** · %s · %d volontaire(s)%s', Nyassobi_Membership::escape_markdown($item['name']), $item['dates'], count($this->volunteers($cid)), $item['open'] ? '' : ' · fermée');
                }
                return $membership->ephemeral($lines ? mb_substr(implode("\n", $lines), 0, 1900) : __('Aucune convention à venir.', 'nyassobi-wp-plugin'));

            case 'infos':
                return self::modal('nyconv:infos-modal:' . $id, 'Infos : ' . $c['name'], [
                    self::text_input('texte', 'Description (visible des adhérents)', true, false, 2000, $this->meta($id, self::META_DESCRIPTION), 'Horaires, emplacement du stand, ce qu\'on attend des bénévoles…'),
                    self::text_input('lien', 'Site de la convention', false, false, 300, $this->meta($id, self::META_LINK), 'https://'),
                ]);

            case 'infos-modal':
                $error = $this->set_info($id, $values['texte'] ?? '', $values['lien'] ?? '', [], false);
                return $membership->ephemeral($error ?? sprintf(__('Infos de %s mises à jour sur la page Conventions du site.', 'nyassobi-wp-plugin'), $c['name']));

            case 'news':
                return self::modal('nyconv:news-modal:' . $id, 'Annonce : ' . $c['name'], [
                    self::text_input('texte', 'L\'annonce (visible des adhérents)', true, true, 1500, '', 'Les émojis du serveur s\'écrivent :NyassoHi: ; **gras** possible.'),
                    self::text_input('mentionner', 'Mentionner les adhérents ? (oui / non)', false, false, 3, 'oui'),
                ]);

            case 'news-modal':
                $error = $this->add_news($id, $values['texte'] ?? '', 0, 0 !== strpos(mb_strtolower(trim($values['mentionner'] ?? 'oui')), 'n'));
                return $membership->ephemeral($error ?? sprintf(__('Annonce publiée pour %s : sur la page Conventions du site et dans le salon des annonces.', 'nyassobi-wp-plugin'), $c['name']));

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

            case 'note':
                return self::modal('nyconv:note-modal:' . $id . ':' . $discord_id, 'Note du CA', [
                    self::text_input('note', 'Note (visible du CA seulement)', true, false, 300, $this->notes($id)[$discord_id]['note'] ?? ''),
                ]);

            case 'note-modal':
                $error = $this->set_note($id, $discord_id, null, '' === trim($values['note'] ?? '') ? '-' : (string) $values['note']);
                return null !== $error ? $membership->ephemeral($error) : $update($this->volunteer_card($id, $discord_id));
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
     * @return int|string Attachment id, or an error message.
     */
    private function store_image(int $convention_id, string $bytes, string $name)
    {
        if ('' === $bytes || strlen($bytes) > self::IMAGE_MAX_BYTES) {
            return __('L\'image doit faire moins de 8 Mo.', 'nyassobi-wp-plugin');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
        if (! isset(self::IMAGE_TYPES[$mime])) {
            return __('Seules les images JPEG, PNG, WebP ou GIF sont acceptées.', 'nyassobi-wp-plugin');
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = wp_tempnam($name);
        file_put_contents($tmp, $bytes);
        $base = sanitize_file_name((string) pathinfo($name, PATHINFO_FILENAME)) ?: 'image';
        $attachment = media_handle_sideload(['name' => $base . '.' . self::IMAGE_TYPES[$mime], 'tmp_name' => $tmp], $convention_id);
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
            update_post_meta($id, self::META_DESCRIPTION, $description);
        }
        if (null !== $link) {
            $clear = '-' === trim($link) || '' === trim($link);
            $clean = $clear ? '' : esc_url_raw(trim($link), ['http', 'https']);
            if (! $clear && ! preg_match('#^https?://#', $clean)) {
                return __('Ce lien ne semble pas valide : il doit commencer par https://', 'nyassobi-wp-plugin');
            }
            update_post_meta($id, self::META_LINK, $clean);
        }
        $images = $this->images($id);
        if ($clear_images) {
            foreach ($images as $image) {
                wp_delete_attachment($image, true);
            }
            $images = [];
        }
        foreach ($add_images as $image) {
            if (count($images) >= self::MAX_IMAGES) {
                wp_delete_attachment($image, true);
                return sprintf(__('%d images au plus : retire-en d\'abord.', 'nyassobi-wp-plugin'), self::MAX_IMAGES);
            }
            $images[] = $image;
        }
        update_post_meta($id, self::META_IMAGES, $images);
        $this->update_summary();

        return null;
    }

    /**
     * Posts a message with an image file attached, so it shows even when the
     * WordPress address is not reachable by Discord (test setup).
     *
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>|null
     */
    private function discord_post_with_image(string $channel, array $payload, int $attachment): ?array
    {
        $path = '/channels/' . rawurlencode($channel) . '/messages';
        $file = $attachment ? (string) get_attached_file($attachment) : '';
        if ('' === $file || ! is_readable($file)) {
            return Nyassobi_Membership::instance()->discord_request('POST', $path, $payload);
        }
        $name = sanitize_file_name(wp_basename($file));
        $payload['attachments'] = [['id' => 0, 'filename' => $name]];
        if (isset($payload['embeds'][0])) {
            $payload['embeds'][0]['image'] = ['url' => 'attachment://' . $name];
        }
        $boundary = 'nyassobi' . bin2hex(random_bytes(8));
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"payload_json\"\r\nContent-Type: application/json\r\n\r\n" . wp_json_encode($payload) . "\r\n"
            . "--$boundary\r\nContent-Disposition: form-data; name=\"files[0]\"; filename=\"$name\"\r\nContent-Type: " . (string) get_post_mime_type($attachment) . "\r\n\r\n" . (string) file_get_contents($file) . "\r\n--$boundary--\r\n";
        $response = wp_remote_post('https://discord.com/api/v10' . $path, [
            'timeout' => 20,
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
                $content .= "\n\n👉 Pour te proposer : " . $this->page_url();
            }
            $role = (string) ($this->settings()['discord_member_role_id'] ?? '');
            $mention = $ping && '' !== $role;
            if ($mention) {
                $content .= "\n\n<@&" . $role . '>';
            }
            $posted = $this->discord_post_with_image($channel, [
                'content' => mb_substr($content, 0, 2000),
                // Only the member role may ring, never @everyone typed by mistake.
                'allowed_mentions' => $mention ? ['roles' => [$role]] : ['parse' => []],
            ], $image);
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
            $entry['status'] = isset(self::STATUSES[$status]) ? $status : '';
        }
        if (null !== $note) {
            $entry['note'] = '-' === trim($note) ? '' : mb_substr(sanitize_text_field($note), 0, 300);
        }
        $notes[$discord_id] = $entry;
        update_post_meta($id, self::META_NOTES, $notes);
        $this->update_recap($id);

        return null;
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
        $state = bin2hex(random_bytes(16));
        set_transient(Nyassobi_Membership_Payment::DISCORD_STATE_PREFIX . $state, ['type' => 'conventions'], 15 * MINUTE_IN_SECONDS);
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
        register_graphql_object_type('NyassobiConventionNews', [
            'fields' => [
                'date' => ['type' => 'String'],
                'text' => ['type' => 'String'],
                'image' => ['type' => 'String'],
            ],
        ]);
        register_graphql_object_type('NyassobiConvention', [
            'fields' => [
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
                        $choices[] = ['conventionId' => (int) $cid] + $choice;
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
        echo '<p class="description">' . esc_html__('Visible sur la page Conventions du site. Le CA peut aussi le faire sur Discord avec /convention-infos.', 'nyassobi-wp-plugin') . '</p>';
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
        foreach ([__('Pseudo', 'nyassobi-wp-plugin'), __('Rôle', 'nyassobi-wp-plugin'), __('Trajet', 'nyassobi-wp-plugin'), __('Transport', 'nyassobi-wp-plugin'), __('Animation proposée', 'nyassobi-wp-plugin'), __('Commentaire', 'nyassobi-wp-plugin'), __('Décision du CA', 'nyassobi-wp-plugin'), __('Note du CA', 'nyassobi-wp-plugin')] as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($people as $p) {
            $note = $notes[$p['discord_id']] ?? ['status' => '', 'note' => ''];
            $select = '<select name="nyassobi_conv_notes[' . esc_attr($p['discord_id']) . '][status]"><option value="">—</option>';
            foreach (self::STATUSES as $value => $label) {
                $select .= sprintf('<option value="%s"%s>%s</option>', esc_attr($value), selected($note['status'], $value, false), esc_html($label));
            }
            $select .= '</select>';
            printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><input type="text" name="nyassobi_conv_notes[%s][note]" value="%s" maxlength="300" class="regular-text"></td></tr>', esc_html($p['name']), esc_html(self::ROLES[$p['role']] ?? $p['role']), esc_html(self::TRAVEL[$p['travel']] ?? $p['travel']), esc_html($p['transport']), 'staff' !== $p['role'] ? esc_html($p['animation']) : '', esc_html($p['comment']), $select, esc_attr($p['discord_id']), esc_attr($note['note']));
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
        $this->set_info($post_id, (string) wp_unslash($_POST['nyassobi_conv_description'] ?? ''), (string) wp_unslash($_POST['nyassobi_conv_lien'] ?? ''), $new_images, false);

        $notes = $this->notes($post_id);
        foreach ((array) ($_POST['nyassobi_conv_notes'] ?? []) as $discord_id => $entry) {
            if (ctype_digit((string) $discord_id) && is_array($entry)) {
                $status = (string) ($entry['status'] ?? '');
                $notes[(string) $discord_id] = [
                    'status' => isset(self::STATUSES[$status]) ? $status : '',
                    'note' => mb_substr(sanitize_text_field((string) wp_unslash($entry['note'] ?? '')), 0, 300),
                ];
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
        $rows = [['Pseudo', 'Rôle', 'Trajet', 'Transport', 'Animation proposée', 'Commentaire']];
        foreach ($this->volunteers($id) as $p) {
            $rows[] = [$p['name'], self::ROLES[$p['role']] ?? $p['role'], self::TRAVEL[$p['travel']] ?? $p['travel'], $p['transport'], 'staff' !== $p['role'] ? $p['animation'] : '', $p['comment']];
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
        // Posters, photos and announcement images go with the convention.
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
