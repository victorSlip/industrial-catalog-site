<?php
/* =========================================================
   Painel Nordtec — funções de apoio
   Compatível com PHP 7.4+
   ========================================================= */

declare(strict_types=1);

date_default_timezone_set('Europe/Zurich');

define('SITE_ROOT', dirname(__DIR__));
define('DATA_FILE', SITE_ROOT . '/data/site.json');
define('BACKUP_DIR', SITE_ROOT . '/data/backups');
define('IMG_DIR', SITE_ROOT . '/img/machines');
define('PRIVATE_DIR', __DIR__ . '/private');
define('CONFIG_FILE', PRIVATE_DIR . '/config.php');
define('ATTEMPTS_FILE', PRIVATE_DIR . '/attempts.json');
define('SETTINGS_FILE', PRIVATE_DIR . '/settings.json');       // configurações que não devem ser públicas
define('MESSAGES_FILE', PRIVATE_DIR . '/messages.json');       // mensagens recebidas pelo formulário
define('CONTACT_RATE_FILE', PRIVATE_DIR . '/contact-rate.json');
define('MESSAGES_TO_KEEP', 500);
define('CONTACT_MAX_PER_HOUR', 5);                             // por visitante (IP)
define('MAIL_LOG_FILE', PRIVATE_DIR . '/mail-log.json');       // registro dos últimos e-mails enviados
define('MAIL_LOG_KEEP', 100);

// Idiomas de tradução do conteúdo (o inglês é o idioma principal)
const TR_LANGS = ['de' => 'Deutsch', 'pt' => 'Português'];
const ALL_LANGS = ['en' => 'English', 'de' => 'Deutsch', 'pt' => 'Português'];

define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024);   // 10 MB por imagem
define('MAX_IMAGE_SIDE', 1600);                 // imagens maiores são reduzidas (se o servidor tiver GD)
define('BACKUPS_TO_KEEP', 30);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS', 15 * 60);

/* ---------- Sessão e segurança ---------- */

function start_session(): void
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_name('catalog_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => dirname($_SERVER['SCRIPT_NAME']) . '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self'");
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('Sessão expirada. Volte e tente novamente.');
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin']);
}

function is_configured(): bool
{
    return is_file(CONFIG_FILE);
}

function load_config(): array
{
    $config = include CONFIG_FILE;
    return is_array($config) ? $config : [];
}

function save_password(string $password): void
{
    if (!is_dir(PRIVATE_DIR)) {
        mkdir(PRIVATE_DIR, 0750, true);
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $php = "<?php\n// Gerado pelo painel. Para redefinir a senha, apague este arquivo e acesse /admin novamente.\nreturn " . var_export(['password_hash' => $hash], true) . ";\n";
    write_file_atomic(CONFIG_FILE, $php);
}

/* Limite de tentativas de login por IP */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function load_attempts(): array
{
    if (!is_file(ATTEMPTS_FILE)) return [];
    $data = json_decode((string)file_get_contents(ATTEMPTS_FILE), true);
    return is_array($data) ? $data : [];
}

function login_locked_for(): int
{
    $a = load_attempts()[client_ip()] ?? null;
    if (!$a) return 0;
    $left = (int)($a['until'] ?? 0) - time();
    return $left > 0 ? $left : 0;
}

function register_login_failure(): void
{
    $all = load_attempts();
    $ip = client_ip();
    $now = time();
    // limpa registros antigos
    foreach ($all as $k => $v) {
        if (($v['last'] ?? 0) < $now - 86400) unset($all[$k]);
    }
    $entry = $all[$ip] ?? ['count' => 0, 'until' => 0, 'last' => 0];
    $entry['count']++;
    $entry['last'] = $now;
    if ($entry['count'] >= MAX_LOGIN_ATTEMPTS) {
        $entry['until'] = $now + LOCKOUT_SECONDS;
        $entry['count'] = 0;
    }
    $all[$ip] = $entry;
    write_file_atomic(ATTEMPTS_FILE, json_encode($all));
}

function clear_login_failures(): void
{
    $all = load_attempts();
    unset($all[client_ip()]);
    write_file_atomic(ATTEMPTS_FILE, json_encode($all));
}

/* ---------- Arquivos JSON privados (configurações, mensagens) ---------- */

function read_json_file(string $path, $default = [])
{
    if (!is_file($path)) return $default;
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : $default;
}

function write_json_file(string $path, $data): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0750, true);
    write_file_atomic($path, to_json($data));
}

function load_settings(): array
{
    $s = read_json_file(SETTINGS_FILE) + [
        'form_recipient' => '', 'reply_copy' => [], 'responsible' => '',
        'smtp_host' => '', 'smtp_port' => 465, 'smtp_secure' => 'ssl', 'smtp_user' => '', 'smtp_pass' => '', 'smtp_noverify' => false,
        'captcha_provider' => '', 'captcha_site_key' => '', 'captcha_secret' => '',
    ];
    if (!is_array($s['reply_copy'])) $s['reply_copy'] = [];
    return $s;
}

function save_settings(array $settings): void
{
    write_json_file(SETTINGS_FILE, $settings);
}

function load_messages(): array
{
    return read_json_file(MESSAGES_FILE);
}

function save_messages(array $messages): void
{
    write_json_file(MESSAGES_FILE, array_slice($messages, 0, MESSAGES_TO_KEEP));
}

function unread_messages_count(): int
{
    return count(array_filter(load_messages(), function ($m) { return empty($m['read']); }));
}

/* ---------- Envio de e-mail (função mail() do PHP da hospedagem) ---------- */

/* Domínio do site sem "www." / "dev." — usado no remetente no-reply@dominio */
function site_domain(): string
{
    $host = strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), PHP_URL_HOST));
    $host = preg_replace('/^(www|dev)\./', '', $host);
    return $host !== '' ? $host : 'localhost';
}

function site_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* Remove quebras de linha (impede injeção de cabeçalhos) */
function header_safe(string $v): string
{
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v));
}

function mime_header(string $text): string
{
    $text = header_safe($text);
    return function_exists('mb_encode_mimeheader') ? mb_encode_mimeheader($text, 'UTF-8', 'B') : $text;
}

/*
 * Envia um e-mail de texto simples.
 * O remetente é sempre no-reply@<domínio do site> (evita cair no spam);
 * as respostas vão para $replyTo. $bcc recebe uma cópia oculta.
 */
/* Último erro de envio (mostrado no botão "Enviar e-mail de teste") */
$GLOBALS['mail_last_error'] = '';

/*
 * Caixa de e-mail da empresa = a conta configurada no SMTP (ex.: info@example.com).
 * Ela envia todos os e-mails do site, recebe os avisos do formulário,
 * recebe as respostas dos clientes e guarda cópia das respostas enviadas pelo painel.
 * Sem SMTP configurado, não há caixa: as mensagens ficam só no painel.
 */
function company_inbox(array $settings): string
{
    return smtp_configured($settings) && filter_var($settings['smtp_user'], FILTER_VALIDATE_EMAIL) ? $settings['smtp_user'] : '';
}

/* Domínio permitido para o e-mail do responsável: o da conta de envio (ex.: example.com) */
function company_domain(array $settings): string
{
    $inbox = company_inbox($settings);
    return $inbox !== '' ? strtolower(substr(strrchr($inbox, '@'), 1)) : site_domain();
}

/*
 * E-mail do responsável pelo atendimento (só do domínio da empresa).
 * Recebe os avisos do formulário e as respostas dos clientes.
 * Vazio = usa a própria conta de envio.
 */
function responsible_email(array $settings): string
{
    $r = strtolower(trim((string)($settings['responsible'] ?? '')));
    if ($r !== '' && filter_var($r, FILTER_VALIDATE_EMAIL) && str_ends_with_domain($r, company_domain($settings))) return $r;
    return company_inbox($settings);
}

function str_ends_with_domain(string $email, string $domain): bool
{
    return $domain !== '' && strtolower(substr(strrchr($email, '@'), 1)) === strtolower($domain);
}

function smtp_configured(array $settings): bool
{
    return ($settings['smtp_host'] ?? '') !== '' && ($settings['smtp_user'] ?? '') !== '' && ($settings['smtp_pass'] ?? '') !== '';
}

/*
 * Envia um e-mail de texto simples.
 * - Com SMTP configurado no painel (Contato → Servidor de envio): entra na conta de e-mail
 *   com usuário e senha e envia por ela (remetente = essa conta). É o método mais confiável.
 * - Sem SMTP: usa a função mail() do PHP, com remetente no-reply@<domínio do site>.
 * As respostas do destinatário vão para $replyTo; $bcc recebe cópias ocultas.
 */
function send_mail(string $to, string $subject, string $body, string $fromName, string $replyTo = '', string $replyName = '', array $bcc = []): bool
{
    $GLOBALS['mail_last_error'] = '';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['mail_last_error'] = 'Endereço de destino inválido.';
        return false;
    }
    // cópias ocultas: só e-mails válidos, sem repetir e sem o próprio destinatário
    $copies = [];
    foreach ($bcc as $addr) {
        $addr = trim((string)$addr);
        if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) && strcasecmp($addr, $to) !== 0 && !in_array(strtolower($addr), array_map('strtolower', $copies), true)) {
            $copies[] = $addr;
        }
    }
    $settings = load_settings();
    $smtp = smtp_configured($settings);
    $from = $smtp ? $settings['smtp_user'] : 'no-reply@' . site_domain();
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['mail_last_error'] = 'O usuário do SMTP precisa ser um e-mail completo (ex.: site@example.com).';
        return false;
    }

    $headers = [
        'From: ' . mime_header($fromName) . ' <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Nordtec website',
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . ($replyName !== '' ? mime_header($replyName) . ' ' : '') . '<' . $replyTo . '>';
    }

    if ($smtp) {
        $ok = smtp_send($settings, $from, $to, $copies, $subject, $body, $headers);
        mail_log('SMTP ' . $settings['smtp_host'] . ':' . $settings['smtp_port'], $from, $to, $copies, $subject, $ok);
        return $ok;
    }

    if ($copies) $headers[] = 'Bcc: ' . implode(', ', $copies);
    $GLOBALS['mail_last_response'] = '';
    $ok = @mail($to, mime_header($subject), $body, implode("\r\n", $headers), '-f' . $from);
    if (!$ok) {
        $err = error_get_last();
        $GLOBALS['mail_last_error'] = 'A função mail() do servidor recusou o envio' . ($err ? ': ' . $err['message'] : '.');
    }
    mail_log('mail() do servidor', $from, $to, $copies, $subject, $ok);
    return $ok;
}

/* Guarda os últimos envios (com a resposta do servidor) para diagnóstico no painel */
function mail_log(string $method, string $from, string $to, array $bcc, string $subject, bool $ok): void
{
    try {
        $log = read_json_file(MAIL_LOG_FILE);
        array_unshift($log, [
            'date' => date('c'),
            'method' => $method,
            'from' => $from,
            'to' => $to,
            'bcc' => array_values($bcc),
            'subject' => $subject,
            'ok' => $ok,
            'server' => $ok ? ($GLOBALS['mail_last_response'] ?? '') : ($GLOBALS['mail_last_error'] ?? ''),
        ]);
        write_json_file(MAIL_LOG_FILE, array_slice($log, 0, MAIL_LOG_KEEP));
    } catch (Throwable $e) {
        // o registro nunca pode impedir o envio
    }
}

/* ---------- Cliente SMTP simples (sem bibliotecas externas) ---------- */

function smtp_send(array $s, string $from, string $to, array $bcc, string $subject, string $body, array $headers): bool
{
    $host = trim($s['smtp_host']);
    $port = (int)($s['smtp_port'] ?? 465) ?: 465;
    $secure = $s['smtp_secure'] ?? 'ssl';            // ssl (465) | tls (587) | none
    $verify = empty($s['smtp_noverify']);
    $timeout = 20;

    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => $verify,
        'verify_peer_name' => $verify,
        'allow_self_signed' => !$verify,
        'SNI_enabled' => true,
        'peer_name' => $host,
    ]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        if ($errstr === '' && $secure !== 'none') $errstr = 'falha na conexão segura — provavelmente o certificado não corresponde a este servidor';
        $GLOBALS['mail_last_error'] = "Não foi possível conectar em $host:$port ($errstr). Confira o servidor, a porta e a segurança"
            . ($verify ? '; se o erro mencionar certificado, marque "Não verificar o certificado".' : '.');
        return false;
    }
    stream_set_timeout($fp, $timeout);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;   // última linha da resposta
        }
        return $data;
    };
    $cmd = function (string $command, array $okCodes, string $label) use ($fp, $read) {
        if ($command !== '') fwrite($fp, $command . "\r\n");
        $res = $read();
        $code = (int)substr($res, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException("$label: " . trim($res ?: 'sem resposta do servidor'));
        }
        return $res;
    };

    try {
        $cmd('', [220], 'Conexão');
        $ehloHost = site_domain();
        $ehlo = $cmd('EHLO ' . $ehloHost, [250], 'EHLO');
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220], 'STARTTLS');
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                throw new RuntimeException('Não foi possível ativar a conexão segura (TLS)' . ($verify ? '; tente marcar "Não verificar o certificado".' : '.'));
            }
            $ehlo = $cmd('EHLO ' . $ehloHost, [250], 'EHLO');
        }

        // autenticação: PLAIN se o servidor oferecer, senão LOGIN
        if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ehlo)) {
            $cmd('AUTH PLAIN ' . base64_encode("\0" . $s['smtp_user'] . "\0" . $s['smtp_pass']), [235], 'Login (usuário ou senha incorretos?)');
        } else {
            $cmd('AUTH LOGIN', [334], 'Login');
            $cmd(base64_encode($s['smtp_user']), [334], 'Login (usuário)');
            $cmd(base64_encode($s['smtp_pass']), [235], 'Login (usuário ou senha incorretos?)');
        }

        $cmd('MAIL FROM:<' . $from . '>', [250], 'Remetente');
        $rcptLog = [];
        foreach (array_merge([$to], $bcc) as $rcpt) {
            $rcptLog[$rcpt] = trim($cmd('RCPT TO:<' . $rcpt . '>', [250, 251], 'Destinatário ' . $rcpt));
        }
        $cmd('DATA', [354], 'DATA');

        $all = array_merge([
            'Date: ' . date('r'),
            'To: <' . $to . '>',
            'Subject: ' . mime_header($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . site_domain() . '>',
        ], $headers);
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $text = preg_replace('/^\./m', '..', $text);                 // "dot-stuffing" exigido pelo SMTP
        $message = implode("\r\n", $all) . "\r\n\r\n" . str_replace("\n", "\r\n", $text) . "\r\n.";
        $GLOBALS['mail_last_response'] = trim($cmd($message, [250], 'Envio'));
        $GLOBALS['mail_last_rcpt'] = $rcptLog;
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return true;
    } catch (RuntimeException $ex) {
        $GLOBALS['mail_last_error'] = 'SMTP – ' . $ex->getMessage();
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return false;
    }
}

/* ---------- CAPTCHA (Google reCAPTCHA v2 ou Cloudflare Turnstile) ---------- */

function captcha_enabled(array $s): bool
{
    return in_array($s['captcha_provider'] ?? '', ['recaptcha', 'turnstile'], true)
        && ($s['captcha_site_key'] ?? '') !== '' && ($s['captcha_secret'] ?? '') !== '';
}

/* POST simples para outro site (usa cURL se existir; senão, file_get_contents) */
function http_post_form(string $url, array $fields): ?string
{
    $body = http_build_query($fields);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $res = curl_exec($ch);
        curl_close($ch);
        return is_string($res) ? $res : null;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body, 'timeout' => 10]]);
    $res = @file_get_contents($url, false, $ctx);
    return is_string($res) ? $res : null;
}

/* Confere com o Google/Cloudflare se o visitante passou na verificação */
function captcha_verify(array $s, string $token, string $ip): bool
{
    if ($token === '') return false;
    $url = $s['captcha_provider'] === 'recaptcha'
        ? 'https://www.google.com/recaptcha/api/siteverify'
        : 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    $res = http_post_form($url, ['secret' => $s['captcha_secret'], 'response' => $token, 'remoteip' => $ip]);
    $json = $res ? json_decode($res, true) : null;
    return is_array($json) && !empty($json['success']);
}

/* ---------- Mensagens entre páginas ---------- */

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $query = ''): void
{
    header('Location: index.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

/* ---------- Dados do site (data/site.json) ---------- */

function write_file_atomic(string $path, string $content): void
{
    $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar ' . basename($path) . '. Verifique as permissões da pasta.');
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Não foi possível substituir ' . basename($path) . '.');
    }
}

function load_data(): array
{
    $data = json_decode((string)@file_get_contents(DATA_FILE), true);
    if (!is_array($data)) {
        throw new RuntimeException('O arquivo data/site.json não foi encontrado ou está corrompido. Restaure um backup de data/backups.');
    }
    $data += ['contact' => [], 'categories' => [], 'machines' => []];
    return $data;
}

/* Salva os dados guardando antes uma cópia de segurança */
function save_data(array $data): void
{
    // gera o JSON ANTES de mexer em qualquer arquivo: se falhar, nada é alterado
    $json = to_json($data);
    if (!is_dir(BACKUP_DIR)) {
        mkdir(BACKUP_DIR, 0750, true);
    }
    if (is_file(DATA_FILE)) {
        copy(DATA_FILE, BACKUP_DIR . '/site-' . date('Y-m-d_H-i-s') . '-' . bin2hex(random_bytes(2)) . '.json');
        $backups = glob(BACKUP_DIR . '/site-*.json') ?: [];
        sort($backups);
        while (count($backups) > BACKUPS_TO_KEEP) {
            @unlink(array_shift($backups));
        }
    }
    write_file_atomic(DATA_FILE, $json . "\n");
}

function find_index(array $list, string $id): int
{
    foreach ($list as $i => $item) {
        if (($item['id'] ?? null) === $id) return $i;
    }
    return -1;
}

/* Transforma "Polish X4 Pro" em "polish-x4-pro" */
function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ß'=>'ss'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim((string)$text, '-');
    return $text !== '' ? substr($text, 0, 60) : 'item';
}

function unique_id(array $list, string $base): string
{
    $id = $base;
    $n = 2;
    while (find_index($list, $id) !== -1) {
        $id = $base . '-' . $n++;
    }
    return $id;
}

function valid_id(string $id): bool
{
    return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $id);
}

/*
 * Garante texto em UTF-8 válido. Texto em outra codificação (ex.: Windows-1252,
 * comum em programas antigos) é convertido, preservando os acentos.
 * Sem isso, um único caractere inválido faz o json_encode falhar.
 */
function clean_utf8(string $v): string
{
    if (function_exists('mb_check_encoding')) {
        if (mb_check_encoding($v, 'UTF-8')) return $v;
        return mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    if (preg_match('//u', $v)) return $v;
    $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $v);
    return $converted !== false ? $converted : '';
}

/* Texto qualquer vindo do formulário (string, limpa e cortada no tamanho máximo) */
function clean_text($v, int $max = 500): string
{
    if (!is_string($v)) return '';
    $v = trim(str_replace("\r", '', clean_utf8($v)));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max);
}

function post_text(string $key, int $max = 500): string
{
    return clean_text($_POST[$key] ?? '', $max);
}

/* Lista de e-mails digitada em um campo (separados por vírgula, ponto e vírgula, espaço ou linha) */
function parse_email_list(string $text): array
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $text) as $addr) {
        if ($addr !== '' && !in_array(strtolower($addr), array_map('strtolower', $out), true)) $out[] = $addr;
    }
    return $out;
}

/* Converte para JSON; se falhar, lança erro em vez de gravar um arquivo vazio */
function to_json($data): string
{
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($data, $flags);
    if ($json === false || $json === '') {
        throw new RuntimeException('Não foi possível salvar: algum texto tem caracteres inválidos (' . json_last_error_msg() . '). Nada foi alterado.');
    }
    return $json;
}

/* Uma linha por item */
function lines(string $text): array
{
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

/* "Rótulo: valor" por linha -> [[rótulo, valor], ...] */
function parse_specs(string $text): array
{
    $out = [];
    foreach (lines($text) as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            $out[] = [$line, ''];
        } else {
            $out[] = [trim(substr($line, 0, $pos)), trim(substr($line, $pos + 1))];
        }
    }
    return $out;
}

function specs_to_text(array $specs): string
{
    return implode("\n", array_map(function ($row) {
        return ($row[0] ?? '') . ': ' . ($row[1] ?? '');
    }, $specs));
}

/* ---------- Imagens ---------- */

function machine_dir(string $id): string
{
    if (!valid_id($id)) throw new RuntimeException('Identificador inválido.');
    return IMG_DIR . '/' . $id;
}

function valid_filename(string $name): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,100}$/', $name) && strpos($name, '..') === false;
}

/* Normaliza $_FILES de um campo múltiplo em uma lista */
function uploaded_files(string $field): array
{
    if (empty($_FILES[$field])) return [];
    $f = $_FILES[$field];
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $out[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/*
 * Valida e grava uma imagem enviada. Devolve o nome do arquivo salvo,
 * ou null se nenhum arquivo foi enviado. Lança exceção se o arquivo for inválido.
 */
function save_uploaded_image(array $file, string $machineId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no envio da imagem "' . $file['name'] . '" (o arquivo pode ser grande demais).');
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('A imagem "' . $file['name'] . '" passa de 10 MB.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Envio inválido.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($types[$info['mime']])) {
        throw new RuntimeException('"' . $file['name'] . '" não é uma imagem JPG, PNG ou WebP.');
    }
    $ext = $types[$info['mime']];
    $dir = machine_dir($machineId);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Não foi possível salvar a imagem. Verifique as permissões da pasta img/machines.');
    }
    @chmod($dest, 0644);
    return optimize_image($dest, $info['mime']);
}

/* O servidor consegue gravar WebP? (extensão GD com suporte a WebP) */
function can_write_webp(): bool
{
    if (!extension_loaded('gd') || !function_exists('imagewebp')) return false;
    $gd = gd_info();
    return !empty($gd['WebP Support']);
}

/*
 * Deixa a foto leve para o site: corrige a rotação de fotos de celular,
 * reduz para no máximo MAX_IMAGE_SIDE px e converte para WebP.
 * Devolve o nome final do arquivo. Se o servidor não tiver GD/WebP,
 * a foto fica como foi enviada.
 */
function optimize_image(string $path, string $mime): string
{
    $name = basename($path);
    if (!extension_loaded('gd')) return $name;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($path); break;
        case 'image/png':  $src = @imagecreatefrompng($path); break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false; break;
        default: $src = false;
    }
    if (!$src) return $name;
    if (!imageistruecolor($src)) imagepalettetotruecolor($src);

    // fotos de celular: a rotação vem só na informação EXIF
    $changed = false;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int)($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle) {
            $rotated = imagerotate($src, $angle, 0);
            if ($rotated) { imagedestroy($src); $src = $rotated; $changed = true; }
        }
    }

    $w = imagesx($src);
    $h = imagesy($src);
    $max = MAX_IMAGE_SIDE;
    if ($w > $max || $h > $max) {
        $ratio = min($max / $w, $max / $h);
        $nw = (int)round($w * $ratio);
        $nh = (int)round($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
        $changed = true;
    }
    imagealphablending($src, false);
    imagesavealpha($src, true);

    if (can_write_webp()) {
        $webp = preg_replace('/\.(jpe?g|png|webp)$/i', '', $path) . '.webp';
        if (@imagewebp($src, $webp, 82) && @filesize($webp) > 0) {
            @chmod($webp, 0644);
            if ($webp !== $path) @unlink($path);
            imagedestroy($src);
            return basename($webp);
        }
        if ($webp !== $path) @unlink($webp);   // falhou: mantém o arquivo original
    }
    if ($changed) {                             // sem WebP: grava no formato original
        if ($mime === 'image/jpeg') imagejpeg($src, $path, 85);
        elseif ($mime === 'image/png') imagepng($src, $path, 6);
        elseif (function_exists('imagewebp')) imagewebp($src, $path, 82);
    }
    imagedestroy($src);
    return $name;
}

function delete_image(string $machineId, string $file): void
{
    if (!valid_filename($file)) return;
    $path = machine_dir($machineId) . '/' . $file;
    if (is_file($path)) @unlink($path);
}

/* Ao excluir uma máquina, as fotos vão para img/machines/_deleted (podem ser recuperadas) */
function archive_machine_images(string $machineId): void
{
    $dir = machine_dir($machineId);
    if (!is_dir($dir)) return;
    $trash = IMG_DIR . '/_deleted';
    if (!is_dir($trash)) mkdir($trash, 0755, true);
    @rename($dir, $trash . '/' . $machineId . '-' . date('Ymd-His'));
}

function image_url(string $machineId, string $file): string
{
    return '../img/machines/' . rawurlencode($machineId) . '/' . rawurlencode($file);
}
