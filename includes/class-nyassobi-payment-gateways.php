<?php
/**
 * Clients for the two ways to pay the membership fee: HelloAsso Checkout
 * (card, no fee for the association) and PayPal Orders v2.
 *
 * They only talk to the payment APIs; what a payment means for a membership
 * request is decided in Nyassobi_Membership.
 *
 * @package NyassobiWPPlugin
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/*
 * HelloAsso answers 404 to any User-Agent starting with "WordPress", which is
 * what wp_remote_*() sends by default: every payment call names itself instead.
 */
const NYASSOBI_PAYMENT_USER_AGENT = 'Nyassobi-Adhesions/1.0 (+https://nyassobi.fr)';

final class Nyassobi_HelloAsso
{
    private const TOKEN_TRANSIENT = 'nyassobi_helloasso_access';
    private const REFRESH_OPTION = 'nyassobi_helloasso_refresh';

    /** @var array<string,string> */
    private $settings;

    /**
     * @param array<string,string> $settings
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function is_configured(): bool
    {
        return '' !== ($this->settings['helloasso_client_id'] ?? '')
            && '' !== ($this->settings['helloasso_client_secret'] ?? '')
            && '' !== ($this->settings['helloasso_org_slug'] ?? '');
    }

    private function base(): string
    {
        return '1' === ($this->settings['helloasso_sandbox'] ?? '') ? 'https://api.helloasso-sandbox.com' : 'https://api.helloasso.com';
    }

    /**
     * HelloAsso forbids asking for a new token on every call: the access
     * token is cached for its 30 minutes, then renewed with the refresh token.
     */
    private function access_token(): ?string
    {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }

        $refresh = (string) get_option(self::REFRESH_OPTION, '');
        $body = '' !== $refresh
            ? ['grant_type' => 'refresh_token', 'refresh_token' => $refresh]
            : ['grant_type' => 'client_credentials', 'client_id' => $this->settings['helloasso_client_id'], 'client_secret' => $this->settings['helloasso_client_secret']];
        $token = $this->request_token($body);

        // A refresh token unused for 30 days expires: start over with the keys.
        if (null === $token && '' !== $refresh) {
            delete_option(self::REFRESH_OPTION);
            $token = $this->request_token(['grant_type' => 'client_credentials', 'client_id' => $this->settings['helloasso_client_id'], 'client_secret' => $this->settings['helloasso_client_secret']]);
        }

        return $token;
    }

    /**
     * @param array<string,string> $body
     */
    private function request_token(array $body): ?string
    {
        $response = wp_remote_post($this->base() . '/oauth2/token', ['timeout' => 10, 'user-agent' => NYASSOBI_PAYMENT_USER_AGENT, 'body' => $body]);
        $data = self::decode($response);
        if (empty($data['access_token'])) {
            error_log('[Nyassobi] HelloAsso : jeton refusé.');
            return null;
        }
        set_transient(self::TOKEN_TRANSIENT, (string) $data['access_token'], max(60, (int) ($data['expires_in'] ?? 1800) - 120));
        if (! empty($data['refresh_token'])) {
            update_option(self::REFRESH_OPTION, (string) $data['refresh_token'], false);
        }

        return (string) $data['access_token'];
    }

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array{code:int,data:array<string,mixed>}
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $token = $this->access_token();
        if (null === $token) {
            return ['code' => 0, 'data' => []];
        }
        $response = wp_remote_request(
            $this->base() . '/v5' . $path,
            [
                'method' => $method,
                'timeout' => 12,
                'user-agent' => NYASSOBI_PAYMENT_USER_AGENT,
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => null !== $body ? wp_json_encode($body) : null,
            ]
        );
        if (401 === (int) wp_remote_retrieve_response_code($response)) {
            delete_transient(self::TOKEN_TRANSIENT);
        }

        return ['code' => (int) wp_remote_retrieve_response_code($response), 'data' => self::decode($response)];
    }

    /**
     * Creates a checkout and returns [intent id, redirect URL]. The redirect
     * URL is only valid for 15 minutes, which is why it is created at click
     * time and never written in an email.
     *
     * Nothing about the person is sent: the payer types their own details on
     * HelloAsso (often a parent paying for a minor), and the link with the
     * membership request goes through the metadata only.
     *
     * @param array{amount_cents:int,item:string,return_url:string,back_url:string,error_url:string,metadata:array<string,mixed>} $checkout
     *
     * @return array{id:string,url:string}|null
     */
    public function create_checkout(array $checkout): ?array
    {
        $body = [
            'totalAmount' => $checkout['amount_cents'],
            'initialAmount' => $checkout['amount_cents'],
            'itemName' => mb_substr($checkout['item'], 0, 250),
            'backUrl' => $checkout['back_url'],
            'errorUrl' => $checkout['error_url'],
            'returnUrl' => $checkout['return_url'],
            'containsDonation' => false,
            'metadata' => $checkout['metadata'],
        ];
        $path = '/organizations/' . rawurlencode($this->settings['helloasso_org_slug']) . '/checkout-intents';
        $result = $this->call('POST', $path, $body);

        if (empty($result['data']['id']) || empty($result['data']['redirectUrl'])) {
            error_log(sprintf('[Nyassobi] HelloAsso : checkout refusé (%d).', $result['code']));
            return null;
        }

        return ['id' => (string) $result['data']['id'], 'url' => (string) $result['data']['redirectUrl']];
    }

    /** True once HelloAsso has an order for this checkout, i.e. the payment is authorized. */
    public function is_paid(string $intent_id): bool
    {
        $result = $this->call('GET', '/organizations/' . rawurlencode($this->settings['helloasso_org_slug']) . '/checkout-intents/' . rawurlencode($intent_id));

        return 200 === $result['code'] && ! empty($result['data']['order']['id']);
    }

    /**
     * @param array|\WP_Error $response
     *
     * @return array<string,mixed>
     */
    private static function decode($response): array
    {
        if (is_wp_error($response)) {
            return [];
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : [];
    }
}

final class Nyassobi_PayPal
{
    private const TOKEN_TRANSIENT = 'nyassobi_paypal_access';

    /** @var array<string,string> */
    private $settings;

    /**
     * @param array<string,string> $settings
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    /** Off until the association has a PayPal Business account and fills its keys in. */
    public function is_configured(): bool
    {
        return '' !== ($this->settings['paypal_client_id'] ?? '') && '' !== ($this->settings['paypal_client_secret'] ?? '');
    }

    private function base(): string
    {
        return '1' === ($this->settings['paypal_sandbox'] ?? '') ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function access_token(): ?string
    {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if (is_string($cached) && '' !== $cached) {
            return $cached;
        }
        $response = wp_remote_post(
            $this->base() . '/v1/oauth2/token',
            [
                'timeout' => 10,
                'user-agent' => NYASSOBI_PAYMENT_USER_AGENT,
                'headers' => ['Authorization' => 'Basic ' . base64_encode($this->settings['paypal_client_id'] . ':' . $this->settings['paypal_client_secret'])],
                'body' => ['grant_type' => 'client_credentials'],
            ]
        );
        $data = self::decode($response);
        if (empty($data['access_token'])) {
            error_log('[Nyassobi] PayPal : jeton refusé.');
            return null;
        }
        set_transient(self::TOKEN_TRANSIENT, (string) $data['access_token'], max(60, (int) ($data['expires_in'] ?? 3600) - 120));

        return (string) $data['access_token'];
    }

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array{code:int,data:array<string,mixed>}
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $token = $this->access_token();
        if (null === $token) {
            return ['code' => 0, 'data' => []];
        }
        $response = wp_remote_request(
            $this->base() . $path,
            [
                'method' => $method,
                'timeout' => 15,
                'user-agent' => NYASSOBI_PAYMENT_USER_AGENT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    // Retrying a capture after a timeout must not charge twice.
                    'PayPal-Request-Id' => wp_generate_uuid4(),
                ],
                'body' => null !== $body ? wp_json_encode($body) : ('POST' === $method ? '{}' : null),
            ]
        );

        return ['code' => (int) wp_remote_retrieve_response_code($response), 'data' => self::decode($response)];
    }

    /**
     * @param array{amount_cents:int,item:string,reference:string,return_url:string,cancel_url:string} $order
     *
     * @return array{id:string,url:string}|null
     */
    public function create_order(array $order): ?array
    {
        $result = $this->call(
            'POST',
            '/v2/checkout/orders',
            [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $order['reference'],
                    'custom_id' => $order['reference'],
                    'description' => mb_substr($order['item'], 0, 127),
                    'amount' => ['currency_code' => 'EUR', 'value' => number_format($order['amount_cents'] / 100, 2, '.', '')],
                ]],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'brand_name' => 'Nyassobi',
                            'locale' => 'fr-FR',
                            'shipping_preference' => 'NO_SHIPPING',
                            'user_action' => 'PAY_NOW',
                            'return_url' => $order['return_url'],
                            'cancel_url' => $order['cancel_url'],
                        ],
                    ],
                ],
            ]
        );
        $url = '';
        foreach ((array) ($result['data']['links'] ?? []) as $link) {
            if (in_array($link['rel'] ?? '', ['payer-action', 'approve'], true)) {
                $url = (string) $link['href'];
            }
        }
        if (empty($result['data']['id']) || '' === $url) {
            error_log(sprintf('[Nyassobi] PayPal : commande refusée (%d).', $result['code']));
            return null;
        }

        return ['id' => (string) $result['data']['id'], 'url' => $url];
    }

    /**
     * Takes the money once the payer has approved on PayPal. Without this
     * call nothing is charged, even after approval.
     */
    public function capture(string $order_id, string $reference): bool
    {
        $result = $this->call('POST', '/v2/checkout/orders/' . rawurlencode($order_id) . '/capture');
        // Already captured (page reloaded): check the order instead.
        if (422 === $result['code']) {
            $result = $this->call('GET', '/v2/checkout/orders/' . rawurlencode($order_id));
        }
        $unit = $result['data']['purchase_units'][0] ?? [];
        // The order can be COMPLETED while its capture is still PENDING
        // (payment under review at PayPal): the money is not there yet.
        $capture = $unit['payments']['captures'][0] ?? [];

        return 'COMPLETED' === ($result['data']['status'] ?? '')
            && 'COMPLETED' === ($capture['status'] ?? '')
            && $reference === (string) ($unit['reference_id'] ?? '');
    }

    /**
     * @param array|\WP_Error $response
     *
     * @return array<string,mixed>
     */
    private static function decode($response): array
    {
        if (is_wp_error($response)) {
            return [];
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

        return is_array($decoded) ? $decoded : [];
    }
}
