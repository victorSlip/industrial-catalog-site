<?php
/* =========================================================
   Recebe o formulário de contato do site.
   - Guarda toda mensagem no painel (aba "Mensagens")
   - Se houver um e-mail configurado no painel, envia também por e-mail
   Responde em JSON: { "ok": true } ou { "ok": false, "error": "..." }
   ========================================================= */

declare(strict_types=1);
require __DIR__ . '/../admin/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

// Só aceita envios vindos do próprio site
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . $host, PHP_URL_HOST)) {
    respond(403, ['ok' => false, 'error' => 'Invalid origin.']);
}

// Texto do formulário em UTF-8 válido e no tamanho máximo
$field = function (string $key, int $max): string {
    return clean_text($_POST[$key] ?? '', $max);
};
// Remove quebras de linha (evita injeção de cabeçalhos no e-mail)
$oneLine = function (string $v): string {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $v));
};

$name = $oneLine($field('name', 120));
$email = $oneLine($field('email', 200));
$phone = $oneLine($field('phone', 40));
$subject = $oneLine($field('subject', 200));
$message = $field('message', 5000);
// idioma em que o visitante estava navegando (usado ao responder pelo painel)
$lang = in_array($_POST['lang'] ?? '', ['en', 'de', 'pt'], true) ? $_POST['lang'] : 'en';

/* ---------- Anti-spam ---------- */
// 1) Campo-isca: invisível para pessoas, robôs costumam preencher
if ($field('website', 200) !== '') {
    respond(200, ['ok' => true]); // finge sucesso para o robô
}
// 2) Tempo de preenchimento: menos de 3 segundos é quase certamente um robô
$ts = (int)($_POST['ts'] ?? 0);
$elapsed = (int)round(microtime(true) * 1000) - $ts;
if ($ts <= 0 || $elapsed < 3000 || $elapsed > 86400000) {
    respond(400, ['ok' => false, 'code' => 'reload', 'error' => 'Please reload the page and try again.']);
}

/* ---------- CAPTCHA (se configurado no painel) ---------- */
$settings = load_settings();
if (captcha_enabled($settings)) {
    $token = (string)($_POST['g-recaptcha-response'] ?? $_POST['cf-turnstile-response'] ?? '');
    if (!captcha_verify($settings, substr($token, 0, 4096), $_SERVER['REMOTE_ADDR'] ?? '')) {
        respond(400, ['ok' => false, 'code' => 'captcha', 'error' => 'Please confirm that you are not a robot.']);
    }
}

/* ---------- Validação ---------- */
if ($name === '' || $email === '' || $subject === '' || $message === '') {
    respond(422, ['ok' => false, 'code' => 'fields', 'error' => 'Please fill in all fields.']);
}
// Telefone é opcional; se vier, aceita só números e + ( ) - . / espaço (mínimo 6 dígitos)
if ($phone !== '' && (!preg_match('#^[0-9+()\-./ ]+$#', $phone) || preg_match_all('/[0-9]/', $phone) < 6)) {
    respond(422, ['ok' => false, 'code' => 'phone', 'error' => 'Please enter a valid phone number.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, ['ok' => false, 'code' => 'email', 'error' => 'Please enter a valid e-mail address.']);
}

/* ---------- Limite de envios por visitante ---------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now = time();
$rate = read_json_file(CONTACT_RATE_FILE);
foreach ($rate as $k => $times) {
    $rate[$k] = array_values(array_filter((array)$times, function ($t) use ($now) { return $t > $now - 3600; }));
    if (!$rate[$k]) unset($rate[$k]);
}
if (count($rate[$ip] ?? []) >= CONTACT_MAX_PER_HOUR) {
    respond(429, ['ok' => false, 'code' => 'rate', 'error' => 'Too many messages. Please try again later.']);
}
$rate[$ip][] = $now;

/* ---------- Envio por e-mail (se configurado no painel) ---------- */
$settings = load_settings();
$recipient = company_inbox($settings) !== '' ? responsible_email($settings) : '';   // responsável (@example.com) ou a conta de envio
$status = 'not_configured';

if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    // Remetente no-reply@<domínio>; clicar em "Responder" no e-mail responde direto ao visitante
    $body = "New message from the website contact form\n"
        . "------------------------------------------\n\n"
        . "Name:    {$name}\n"
        . "E-mail:  {$email}\n"
        . "Phone:   " . ($phone !== '' ? $phone : '-') . "\n"
        . "Subject: {$subject}\n\n"
        . $message . "\n\n"
        . "------------------------------------------\n"
        . "Reply to this e-mail to answer {$name} directly.\n";

    $sent = send_mail($recipient, '[Website] ' . $subject, $body, 'Nordtec website', $email, $name);
    $status = $sent ? 'sent' : 'failed';
    $mailError = $sent ? '' : ($GLOBALS['mail_last_error'] ?? '');
}

/* ---------- Guarda a mensagem no painel ---------- */
try {
    $messages = load_messages();
    array_unshift($messages, [
        'id' => bin2hex(random_bytes(8)),
        'date' => date('c'),
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'subject' => $subject,
        'message' => $message,
        'lang' => $lang,
        'status' => $status,
        'error' => $mailError ?? '',
        'read' => false,
    ]);
    save_messages($messages);
    write_json_file(CONTACT_RATE_FILE, $rate);
} catch (Throwable $ex) {
    // Se nem guardar nem enviar funcionou, avisa o visitante para usar o e-mail
    if ($status !== 'sent') {
        respond(500, ['ok' => false, 'error' => 'Your message could not be sent. Please e-mail us directly.']);
    }
}

respond(200, ['ok' => true]);
