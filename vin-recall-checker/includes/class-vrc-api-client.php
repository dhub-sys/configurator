<?php
if (!defined('ABSPATH')) exit;

/**
 * Client for the manufacturer's IAS (Integration Adaptation Service).
 *
 *  1. GET  {token_url}  with app-id + AES-encrypted app-secret  → token (valid 5 hours)
 *  2. POST {api_base_url}/v1/eu/dms/getUnfinishedTechnologyActivityByVin {"vin": "..."}
 *     with headers APP-ID and APP-TOKEN → unfinished campaigns and labour minutes.
 */
final class VRC_Api_Client {
    const TOKEN_TRANSIENT = 'vrc_access_token';
    /** The token lives 18000 s (5 h); refresh it half an hour early. */
    const TOKEN_TTL = 16200;
    /** Waits before each retry on network errors / 5xx, per the IAS backoff recommendation. */
    const RETRY_DELAYS = [1, 2];

    /** @var array */
    private $s;

    public function __construct(?array $settings = null) {
        $this->s = $settings ?? VRC_Settings::all();
    }

    /**
     * AES/CBC/PKCS5Padding, key = IV, hex output — same as the Java (Hutool) sample
     * `new AES(Mode.CBC, Padding.PKCS5Padding, key, iv).encryptHex(appSecret)`.
     *
     * @return string|WP_Error
     */
    public static function encrypt_secret(string $plain, string $key) {
        $bits = strlen($key) * 8;
        if (!in_array($bits, [128, 192, 256], true)) {
            return new WP_Error('vrc_aes_key', sprintf('Το κλειδί AES πρέπει να έχει 16, 24 ή 32 χαρακτήρες (έχει %d).', strlen($key)));
        }
        if (!function_exists('openssl_encrypt')) {
            return new WP_Error('vrc_openssl', 'Η επέκταση OpenSSL της PHP δεν είναι διαθέσιμη στον server.');
        }
        $cipher = openssl_encrypt($plain, 'AES-' . $bits . '-CBC', $key, OPENSSL_RAW_DATA, substr($key, 0, 16));
        if ($cipher === false) return new WP_Error('vrc_aes', 'Αποτυχία κρυπτογράφησης του App Secret.');
        return bin2hex($cipher);
    }

    /** Transient name tied to the credentials, so changing them never reuses an old token. */
    private function token_key(): string {
        return self::TOKEN_TRANSIENT . '_' . md5($this->s['token_url'] . '|' . $this->s['app_id'] . '|' . $this->s['app_secret']);
    }

    public function forget_token(): void {
        delete_transient($this->token_key());
    }

    /** @return string|WP_Error */
    public function get_token(bool $force = false) {
        if (!$force) {
            $cached = get_transient($this->token_key());
            if (is_string($cached) && $cached !== '') return $cached;
        }
        $token = $this->request_token();
        if (!is_wp_error($token)) set_transient($this->token_key(), $token, self::TOKEN_TTL);
        return $token;
    }

    /** @return string|WP_Error */
    private function request_token() {
        if (!VRC_Settings::is_configured($this->s)) {
            return new WP_Error('vrc_not_configured', 'Δεν έχουν συμπληρωθεί όλα τα στοιχεία σύνδεσης (Token URL, API base URL, App ID, App Secret).');
        }
        $secret = $this->s['app_secret'];
        if (!$this->s['secret_is_encrypted']) {
            $secret = self::encrypt_secret($secret, $this->s['aes_key']);
            if (is_wp_error($secret)) return $secret;
        }

        $url = $this->s['token_url'];
        $headers = ['Accept' => 'application/json'];
        $mode = $this->s['token_param_mode'];
        if ($mode === 'headers' || $mode === 'both') {
            $headers['app-id'] = $this->s['app_id'];
            $headers['app-secret'] = $secret;
        }
        if ($mode === 'query' || $mode === 'both') {
            $url = add_query_arg(['app-id' => rawurlencode($this->s['app_id']), 'app-secret' => rawurlencode($secret)], $url);
        }

        $response = $this->http('GET', $url, ['headers' => $headers]);
        if (is_wp_error($response)) return $response;

        $body = $response['body'];
        if (is_array($body) && isset($body['code']) && (int) $body['code'] === 0 && is_string($body['data'] ?? null) && $body['data'] !== '') {
            return $body['data'];
        }
        $msg = is_array($body) && !empty($body['msg']) ? (string) $body['msg'] : 'Μη αναμενόμενη απάντηση';
        $error = new WP_Error('vrc_token', sprintf('Αποτυχία λήψης token (HTTP %d): %s', $response['status'], $msg), ['raw' => $response['raw']]);
        VRC_Log::add('token', $error->get_error_message());
        return $error;
    }

    /**
     * @return array|WP_Error {
     *     @type string    $vin
     *     @type bool|null $vin_exist      false = VIN unknown to iDMS, null = not returned
     *     @type int       $count          open (unfinished) campaigns
     *     @type float     $labor_minutes  total labour for them, in minutes
     *     @type string    $tx_id
     * }
     */
    public function check_vin(string $vin, bool $include_raw = false) {
        $token = $this->get_token();
        if (is_wp_error($token)) return $token;

        $response = $this->query($vin, $token);
        // An expired/revoked token is rejected by the gateway: refresh it once and retry.
        if (!is_wp_error($response) && $this->is_auth_failure($response)) {
            $this->forget_token();
            $token = $this->get_token(true);
            if (is_wp_error($token)) return $token;
            $response = $this->query($vin, $token);
        }
        if (is_wp_error($response)) {
            VRC_Log::add('vin', VRC_Log::mask_vin($vin) . ': ' . $response->get_error_message());
            return $response;
        }

        $body = $response['body'];
        $payload = is_array($body) && is_array($body['data'] ?? null) ? ($body['data']['payload'] ?? null) : null;
        if ($response['status'] !== 200 || !is_array($body) || (int) ($body['code'] ?? -1) !== 0 || !is_array($payload)) {
            $msg = '';
            if (is_array($body)) {
                $msg = (string) (($body['data']['message'] ?? '') ?: ($body['msg'] ?? ''));
            }
            $error = new WP_Error('vrc_query', sprintf('Σφάλμα ερωτήματος VIN (HTTP %d): %s', $response['status'], $msg !== '' ? $msg : 'Μη αναμενόμενη απάντηση'), ['raw' => $response['raw']]);
            VRC_Log::add('vin', VRC_Log::mask_vin($vin) . ': ' . $error->get_error_message());
            return $error;
        }

        $result = [
            'vin'           => (string) ($payload['vin'] ?? $vin),
            'vin_exist'     => isset($payload['vinExist']) ? (bool) $payload['vinExist'] : null,
            'count'         => max(0, (int) ($payload['unfinishedCount'] ?? 0)),
            'labor_minutes' => max(0.0, (float) ($payload['totalLaborMinutes'] ?? 0)),
            'tx_id'         => (string) ($body['txId'] ?? ''),
        ];
        if ($include_raw) $result['raw'] = $response['raw'];
        return $result;
    }

    /** @return array|WP_Error */
    private function query(string $vin, string $token) {
        $url = VRC_Settings::query_url($this->s);
        return $this->http('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept'       => 'application/json',
                'APP-ID'       => $this->s['app_id'],
                'APP-TOKEN'    => $token,
            ],
            'body' => wp_json_encode(['vin' => $vin]),
        ]);
    }

    private function is_auth_failure(array $response): bool {
        if (in_array($response['status'], [401, 403], true)) return true;
        // Gateway-level rejection: non-zero code without a business "data" object.
        $body = $response['body'];
        return is_array($body) && isset($body['code']) && (int) $body['code'] !== 0 && !is_array($body['data'] ?? null);
    }

    /**
     * HTTPS request with retries on network errors, 429 and 5xx.
     *
     * @return array|WP_Error ['status' => int, 'body' => array|null, 'raw' => string]
     */
    private function http(string $method, string $url, array $args) {
        if (stripos($url, 'https://') !== 0) {
            return new WP_Error('vrc_https', 'Η διεύθυνση πρέπει να ξεκινά με https:// (το IAS δεν δέχεται HTTP).');
        }
        $args += [
            'method'      => $method,
            'timeout'     => max(3, (int) $this->s['timeout']),
            'redirection' => 0,
            'user-agent'  => 'VIN-Recall-Checker/' . VRC_VERSION . '; ' . home_url('/'),
        ];

        $attempt = 0;
        while (true) {
            $res = wp_remote_request($url, $args);
            $status = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
            $retryable = is_wp_error($res) || $status === 429 || $status >= 500;
            if (!$retryable || $attempt >= count(self::RETRY_DELAYS)) break;
            sleep(self::RETRY_DELAYS[$attempt]);
            $attempt++;
        }

        if (is_wp_error($res)) {
            $error = new WP_Error('vrc_http', 'Αποτυχία σύνδεσης με τον server του κατασκευαστή: ' . $res->get_error_message());
            VRC_Log::add('http', $error->get_error_message() . ' [' . preg_replace('/\?.*/', '', $url) . ']');
            return $error;
        }
        $raw = (string) wp_remote_retrieve_body($res);
        $body = json_decode($raw, true);
        return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => $raw];
    }
}
