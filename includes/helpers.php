<?php

class PanelError extends Exception
{
    public $code_name;
    public $status;
    public $detail;

    function __construct($code_name, $status = 400, $detail = null)
    {
        parent::__construct($code_name);
        $this->code_name = $code_name;
        $this->status = $status;
        $this->detail = $detail;
    }
}

function fail($code, $status = 400, $detail = null)
{
    throw new PanelError($code, $status, $detail);
}

function e($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function strip_slashes_deep($value)
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = strip_slashes_deep($item);
        }
        return $value;
    }
    return stripslashes($value);
}

function undo_magic_quotes()
{
    if (!function_exists('get_magic_quotes_gpc') || version_compare(PHP_VERSION, '5.4.0', '>=')) {
        return;
    }
    if (!@get_magic_quotes_gpc()) {
        return;
    }
    $_GET = strip_slashes_deep($_GET);
    $_POST = strip_slashes_deep($_POST);
    $_COOKIE = strip_slashes_deep($_COOKIE);
}

function load_config()
{
    $GLOBALS['config'] = null;
    if (is_file(CONFIG_FILE)) {
        $config = include CONFIG_FILE;
        if (is_array($config) && !empty($config['installed'])) {
            $GLOBALS['config'] = $config;
        }
    }
}

function is_installed()
{
    return $GLOBALS['config'] !== null;
}

function config_value($key, $default = null)
{
    $config = $GLOBALS['config'];
    if (!is_array($config) || !array_key_exists($key, $config)) {
        return $default;
    }
    return $config[$key];
}

function save_config($config)
{
    $text = "<?php\n\nreturn " . var_export($config, true) . ";\n";
    $temporary = CONFIG_FILE . '.' . getmypid() . '.tmp';
    if (@file_put_contents($temporary, $text) === false) {
        return false;
    }
    @chmod($temporary, 0600);
    if (is_file(CONFIG_FILE)) {
        @unlink(CONFIG_FILE);
    }
    if (!@rename($temporary, CONFIG_FILE)) {
        @unlink($temporary);
        return false;
    }
    $GLOBALS['config'] = $config;
    return true;
}

function data_dir_writable()
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    return is_dir(DATA_DIR) && is_writable(DATA_DIR);
}

function text_value($value, $max = 255)
{
    if ($value === null || is_array($value)) {
        return '';
    }
    $text = preg_replace('/[\x00-\x1f\x7f]/', ' ', (string)$value);
    $text = trim($text);
    return cut_text($text, $max);
}

function cut_text($text, $max)
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $max, 'UTF-8');
    }
    if (strlen($text) <= $max) {
        return $text;
    }
    $text = substr($text, 0, $max);
    return preg_replace('/[\x80-\xBF]+$|[\xC0-\xFF][\x80-\xBF]*$/', '', $text);
}

function first_letter($text)
{
    if (function_exists('mb_substr')) {
        return mb_strtoupper(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8');
    }
    if (preg_match('/^./u', $text, $match)) {
        return strtoupper($match[0]);
    }
    return '?';
}

function int_value($value, $fallback = 0)
{
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value) && preg_match('/^\s*-?\d+/', $value)) {
        return (int)$value;
    }
    if (is_float($value)) {
        return (int)$value;
    }
    return $fallback;
}

function clamp($value, $min, $max)
{
    return max($min, min($max, $value));
}

function query_param($name, $default = '')
{
    return isset($_GET[$name]) && !is_array($_GET[$name]) ? $_GET[$name] : $default;
}

function post_param($name, $default = '')
{
    return isset($_POST[$name]) ? $_POST[$name] : $default;
}

function request_body()
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $body = array();
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
    return $body;
}

function body_value($name, $default = null)
{
    $body = request_body();
    return array_key_exists($name, $body) ? $body[$name] : $default;
}

function is_post()
{
    return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
}

function client_ip()
{
    if (config_value('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (valid_ip($ip)) {
            return $ip;
        }
    }
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function valid_ip($text)
{
    return filter_var($text, FILTER_VALIDATE_IP) !== false;
}

function is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (config_value('trust_proxy') && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }
    return false;
}

function is_local_host()
{
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, array('localhost', '127.0.0.1', '[::1]'));
}

function base_path()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    return rtrim($dir, '/') . '/';
}

function page_url($page = '', $params = array())
{
    $query = array();
    if ($page !== '' && $page !== 'home') {
        $query['page'] = $page;
    }
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $query[$key] = $value;
    }
    $url = base_path();
    if (count($query) > 0) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

function api_url($action, $params = array())
{
    $params = array_merge(array('action' => $action), $params);
    return base_path() . 'api.php?' . http_build_query($params);
}

function asset_url($file)
{
    $path = ROOT_DIR . '/assets/' . $file;
    $version = is_file($path) ? filemtime($path) : VERDICT_VERSION;
    return base_path() . 'assets/' . $file . '?v=' . $version;
}

function redirect_to($url)
{
    header('Location: ' . $url);
    exit;
}

function send_json($data, $status = 200)
{
    if ($status !== 200) {
        header('HTTP/1.1 ' . $status . ' ' . status_text($status));
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function status_text($status)
{
    $texts = array(
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        409 => 'Conflict',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable'
    );
    return isset($texts[$status]) ? $texts[$status] : 'Error';
}

function random_bytes_string($length)
{
    if (function_exists('random_bytes')) {
        return random_bytes($length);
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $bytes = openssl_random_pseudo_bytes($length);
        if ($bytes !== false && strlen($bytes) === $length) {
            return $bytes;
        }
    }
    if (@is_readable('/dev/urandom')) {
        $handle = @fopen('/dev/urandom', 'rb');
        if ($handle) {
            $bytes = fread($handle, $length);
            fclose($handle);
            if (strlen($bytes) === $length) {
                return $bytes;
            }
        }
    }
    $bytes = '';
    for ($i = 0; $i < $length; $i++) {
        $bytes .= chr(mt_rand(0, 255));
    }
    return $bytes;
}

function random_hex($length)
{
    return substr(bin2hex(random_bytes_string((int)ceil($length / 2))), 0, $length);
}

function same_text($known, $given)
{
    $known = (string)$known;
    $given = (string)$given;
    if (strlen($known) !== strlen($given)) {
        return false;
    }
    $result = 0;
    for ($i = 0; $i < strlen($known); $i++) {
        $result |= ord($known[$i]) ^ ord($given[$i]);
    }
    return $result === 0;
}

function http_get($url, $timeout = 5)
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Verdict/' . VERDICT_VERSION);
        if (!ini_get('open_basedir')) {
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
        }
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false) {
            return null;
        }
        return array('status' => (int)$status, 'body' => $body);
    }

    if (!ini_get('allow_url_fopen')) {
        return null;
    }
    $context = stream_context_create(array('http' => array(
        'timeout' => $timeout,
        'ignore_errors' => true,
        'header' => "User-Agent: Verdict/" . VERDICT_VERSION . "\r\n"
    )));
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }
    $status = 200;
    if (isset($http_response_header) && preg_match('/^HTTP\/\S+\s+(\d+)/', $http_response_header[0], $match)) {
        $status = (int)$match[1];
    }
    return array('status' => $status, 'body' => $body);
}

function http_get_many($urls, $timeout = 5)
{
    $results = array();
    if (!function_exists('curl_multi_init')) {
        foreach ($urls as $key => $url) {
            $results[$key] = http_get($url, $timeout);
        }
        return $results;
    }

    $multi = curl_multi_init();
    $handles = array();
    foreach ($urls as $key => $url) {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Verdict/' . VERDICT_VERSION);
        if (!ini_get('open_basedir')) {
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_MAXREDIRS, 3);
        }
        curl_multi_add_handle($multi, $curl);
        $handles[$key] = $curl;
    }

    $running = 0;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) {
            curl_multi_select($multi, 0.5);
        }
    } while ($running > 0 && $status == CURLM_OK);

    foreach ($handles as $key => $curl) {
        $body = curl_multi_getcontent($curl);
        $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $results[$key] = ($body === null || $body === false || $code == 0) ? null : array('status' => (int)$code, 'body' => $body);
        curl_multi_remove_handle($multi, $curl);
        curl_close($curl);
    }
    curl_multi_close($multi);
    return $results;
}

function flash_toast($text, $kind = 'ok')
{
    if (!isset($_SESSION['toasts'])) {
        $_SESSION['toasts'] = array();
    }
    $_SESSION['toasts'][] = array('text' => $text, 'kind' => $kind);
}

function take_toasts()
{
    $toasts = isset($_SESSION['toasts']) ? $_SESSION['toasts'] : array();
    unset($_SESSION['toasts']);
    return $toasts;
}

function paginate($total, $page, $per_page)
{
    $pages = max(1, (int)ceil($total / $per_page));
    $page = clamp($page, 1, $pages);
    return array(
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'limit' => $per_page,
        'offset' => ($page - 1) * $per_page
    );
}
