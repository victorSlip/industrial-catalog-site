<?php
/* =========================================================
   Painel Nordtec — edita data/site.json e as fotos das máquinas
   ========================================================= */

declare(strict_types=1);
require __DIR__ . '/lib.php';

start_session();
send_security_headers();

$page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : 'machines';
$error = null;

/* ---------- Primeiro acesso: criar senha ---------- */
if (!is_configured()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (strlen($p1) < 10) {
            $error = 'A senha precisa ter pelo menos 10 caracteres.';
        } elseif ($p1 !== $p2) {
            $error = 'As senhas não são iguais.';
        } else {
            save_password($p1);
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            flash('Senha criada. Bem-vindo ao painel!');
            redirect();
        }
    }
    render_header('Criar senha', false);
    render_setup($error);
    render_footer();
    exit;
}

/* ---------- Login ---------- */
if ($page === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

if (!is_logged_in()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $locked = login_locked_for();
        if ($locked > 0) {
            $error = 'Muitas tentativas. Tente de novo em ' . ceil($locked / 60) . ' minuto(s).';
        } else {
            $config = load_config();
            if (password_verify((string)($_POST['password'] ?? ''), $config['password_hash'] ?? '')) {
                clear_login_failures();
                session_regenerate_id(true);
                $_SESSION['admin'] = true;
                redirect();
            }
            register_login_failure();
            usleep(800000);
            $error = 'Senha incorreta.';
        }
    }
    render_header('Entrar', false);
    render_login($error);
    render_footer();
    exit;
}

/* ---------- Área logada ---------- */
try {
    $data = load_data();
} catch (RuntimeException $ex) {
    render_header('Erro');
    echo '<div class="alert alert--error">' . e($ex->getMessage()) . '</div>';
    render_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        switch ($page) {
            case 'machine':
                $result = handle_save_machine($data);
                if (is_array($result)) {
                    // erro de validação: mostra o formulário de novo com o que foi digitado
                    render_header('Editar máquina');
                    render_machine_form($data, $result['machine'], $result['isNew'], $result['error']);
                    render_footer();
                    exit;
                }
                break;
            case 'machines':
                handle_machine_list_action($data);
                break;
            case 'categories':
                handle_categories($data);
                break;
            case 'contact':
                handle_contact($data);
                break;
            case 'messages':
                handle_messages();
                break;
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'error');
        redirect('page=' . rawurlencode($page));
    }
}

switch ($page) {
    case 'machine':
        $id = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';
        if ($id === '') {
            $machine = ['id' => '', 'category' => $data['categories'][0]['id'] ?? '', 'name' => '', 'subtitle' => '', 'description' => '', 'image' => '', 'featured' => false, 'highlights' => [], 'specs' => [], 'featuresTitle' => '', 'features' => [], 'gallery' => []];
            render_header('Nova máquina');
            render_machine_form($data, $machine, true, null);
        } else {
            $i = find_index($data['machines'], $id);
            if ($i === -1) {
                flash('Máquina não encontrada.', 'error');
                redirect();
            }
            render_header('Editar ' . $data['machines'][$i]['name']);
            render_machine_form($data, $data['machines'][$i], false, null);
        }
        break;
    case 'categories':
        render_header('Categorias');
        render_categories($data);
        break;
    case 'contact':
        render_header('Contato');
        render_contact($data);
        break;
    case 'messages':
        render_header('Mensagens');
        render_messages();
        break;
    case 'help':
        render_header('Ajuda');
        render_help();
        break;
    default:
        render_header('Máquinas');
        render_machine_list($data);
}
render_footer();


/* =========================================================
   Ações
   ========================================================= */

/* Devolve null em caso de sucesso (redireciona) ou um array com o erro */
function handle_save_machine(array $data)
{
    $id = post_text('id', 100);
    $isNew = $id === '';
    $index = $isNew ? -1 : find_index($data['machines'], $id);
    if (!$isNew && $index === -1) throw new RuntimeException('Máquina não encontrada.');

    $old = $isNew ? ['image' => '', 'gallery' => [], 'features' => []] : $data['machines'][$index];
    $machine = $isNew ? [] : $old;

    $machine['name'] = post_text('name', 120);
    $machine['category'] = post_text('category', 100);
    $machine['subtitle'] = post_text('subtitle', 300);
    $machine['description'] = post_text('description', 5000);
    $machine['featured'] = !empty($_POST['featured']);
    $machine['highlights'] = lines(post_text('highlights', 5000));
    $machine['specs'] = parse_specs(post_text('specs', 5000));
    $machine['featuresTitle'] = post_text('featuresTitle', 200);
    $machine['image'] = $old['image'] ?? '';
    $machine['gallery'] = $old['gallery'] ?? [];
    $machine['features'] = [];

    // Traduções (alemão e português). Campo vazio = o site mostra o texto em inglês.
    $trPost = is_array($_POST['tr'] ?? null) ? $_POST['tr'] : [];
    $featTr = is_array($_POST['feature_tr'] ?? null) ? $_POST['feature_tr'] : [];
    $ft = function (string $lg, string $key, int $i) use ($featTr): string {
        $list = $featTr[$lg][$key] ?? null;
        return is_array($list) && is_string($list[$i] ?? null) ? $list[$i] : '';
    };
    $translations = [];
    foreach (TR_LANGS as $lg => $label) {
        $t = is_array($trPost[$lg] ?? null) ? $trPost[$lg] : [];
        $translations[$lg] = [
            'subtitle' => clean_text($t['subtitle'] ?? '', 300),
            'description' => clean_text($t['description'] ?? '', 5000),
            'highlights' => lines(clean_text($t['highlights'] ?? '', 5000)),
            'specs' => parse_specs(clean_text($t['specs'] ?? '', 5000)),
            'featuresTitle' => clean_text($t['featuresTitle'] ?? '', 200),
            'features' => [],
        ];
    }

    // Recursos (blocos com título, itens e imagem)
    $titles = $_POST['feature_title'] ?? [];
    $items = $_POST['feature_items'] ?? [];
    $existing = $_POST['feature_image'] ?? [];
    $featureUploads = uploaded_files('feature_file');
    if (!is_array($titles)) $titles = [];
    if (!is_array($items)) $items = [];
    if (!is_array($existing)) $existing = [];
    foreach ($titles as $i => $title) {
        $title = clean_text($title, 150);
        $list = lines(clean_text($items[$i] ?? '', 3000));
        $img = is_string($existing[$i] ?? null) ? $existing[$i] : '';
        $hasUpload = isset($featureUploads[$i]) && ($featureUploads[$i]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        // descarta só blocos totalmente vazios (sem título, itens nem foto)
        if ($title === '' && !$list && $img === '' && !$hasUpload) continue;
        $machine['features'][] = ['title' => $title, 'image' => valid_filename($img) ? $img : '', 'items' => $list, '_upload' => $i];
        foreach (TR_LANGS as $lg => $label) {
            $translations[$lg]['features'][] = [
                'title' => clean_text($ft($lg, 'title', (int)$i), 150),
                'items' => lines(clean_text($ft($lg, 'items', (int)$i), 3000)),
            ];
        }
    }
    $machine['translations'] = $translations;

    if ($machine['name'] === '') {
        return ['error' => 'Preencha o nome da máquina.', 'machine' => $machine + ['id' => $id], 'isNew' => $isNew];
    }
    if (find_index($data['categories'], $machine['category']) === -1) {
        return ['error' => 'Escolha uma categoria válida.', 'machine' => $machine + ['id' => $id], 'isNew' => $isNew];
    }

    if ($isNew) {
        $id = unique_id($data['machines'], slugify($machine['name']));
    }
    $machine = ['id' => $id] + $machine;

    // Envio de imagens (se algo falhar, apaga o que já foi enviado nesta tentativa)
    $newFiles = [];
    try {
        $main = uploaded_files('main_image')[0] ?? null;
        if ($main && ($saved = save_uploaded_image($main, $id))) {
            $newFiles[] = $saved;
            $machine['image'] = $saved;
        }
        if ($machine['image'] === '') {
            throw new RuntimeException('Envie a foto principal da máquina.');
        }

        $remove = $_POST['gallery_remove'] ?? [];
        if (is_array($remove)) {
            $machine['gallery'] = array_values(array_filter($machine['gallery'], function ($g) use ($remove) {
                return !in_array($g, $remove, true);
            }));
        }
        foreach (uploaded_files('gallery_new') as $file) {
            if ($saved = save_uploaded_image($file, $id)) {
                $newFiles[] = $saved;
                $machine['gallery'][] = $saved;
            }
        }

        foreach ($machine['features'] as &$f) {
            $file = $featureUploads[$f['_upload']] ?? null;
            if ($file && ($saved = save_uploaded_image($file, $id))) {
                $newFiles[] = $saved;
                $f['image'] = $saved;
            }
            unset($f['_upload']);
        }
        unset($f);
    } catch (RuntimeException $ex) {
        foreach ($newFiles as $file) delete_image($id, $file);
        // volta as referências de imagem para o estado anterior ao envio
        $machine['image'] = $old['image'] ?? '';
        $machine['gallery'] = array_values(array_diff($machine['gallery'], $newFiles));
        foreach ($machine['features'] as &$f) {
            if (in_array($f['image'], $newFiles, true)) $f['image'] = '';
            unset($f['_upload']);
        }
        unset($f);
        return ['error' => $ex->getMessage(), 'machine' => $machine + ['id' => $isNew ? '' : $id], 'isNew' => $isNew];
    }

    if ($isNew) {
        $data['machines'][] = $machine;
    } else {
        $data['machines'][$index] = $machine;
    }
    save_data($data);

    // Apaga imagens que deixaram de ser usadas
    if (!$isNew) {
        $used = array_merge([$machine['image']], $machine['gallery'], array_column($machine['features'], 'image'));
        $before = array_merge([$old['image'] ?? ''], $old['gallery'] ?? [], array_column($old['features'] ?? [], 'image'));
        foreach (array_diff($before, $used) as $file) {
            if ($file !== '') delete_image($id, $file);
        }
    }

    flash($isNew ? 'Máquina criada.' : 'Alterações salvas.');
    redirect('page=machine&id=' . rawurlencode($id));
    return null;
}

function handle_machine_list_action(array $data): void
{
    $id = post_text('id', 100);
    $i = find_index($data['machines'], $id);
    if ($i === -1) throw new RuntimeException('Máquina não encontrada.');
    $action = post_text('action', 20);

    if ($action === 'delete') {
        $name = $data['machines'][$i]['name'];
        array_splice($data['machines'], $i, 1);
        save_data($data);
        archive_machine_images($id);
        flash('"' . $name . '" foi excluída.');
    } elseif ($action === 'up' || $action === 'down') {
        $j = $action === 'up' ? $i - 1 : $i + 1;
        if (isset($data['machines'][$j])) {
            [$data['machines'][$i], $data['machines'][$j]] = [$data['machines'][$j], $data['machines'][$i]];
            save_data($data);
        }
    }
    redirect();
}

function handle_categories(array $data): void
{
    $action = post_text('action', 120);

    if ($action === 'add') {
        $name = post_text('new_name', 80);
        if ($name === '') throw new RuntimeException('Digite o nome da nova categoria.');
        $data['categories'][] = ['id' => unique_id($data['categories'], slugify($name)), 'name' => $name, 'cover' => ''];
        save_data($data);
        flash('Categoria "' . $name . '" criada.');
        redirect('page=categories');
    }

    if ($action === 'save') {
        $names = $_POST['name'] ?? [];
        $covers = $_POST['cover'] ?? [];
        $namesTr = is_array($_POST['name_tr'] ?? null) ? $_POST['name_tr'] : [];
        if (!is_array($names)) $names = [];
        if (!is_array($covers)) $covers = [];
        foreach ($data['categories'] as &$c) {
            $n = clean_text($names[$c['id']] ?? '', 80);
            if ($n !== '') $c['name'] = $n;
            foreach (TR_LANGS as $lg => $label) {
                $list = is_array($namesTr[$lg] ?? null) ? $namesTr[$lg] : [];
                $c['translations'][$lg]['name'] = clean_text($list[$c['id']] ?? '', 80);
            }
            $cover = is_string($covers[$c['id']] ?? null) ? $covers[$c['id']] : '';
            $c['cover'] = find_index($data['machines'], $cover) !== -1 ? $cover : '';
        }
        unset($c);
        save_data($data);
        flash('Categorias salvas.');
        redirect('page=categories');
    }

    // up:<id>, down:<id>, delete:<id>
    $parts = explode(':', $action, 2);
    $cmd = $parts[0];
    $id = $parts[1] ?? '';
    $i = find_index($data['categories'], $id);
    if ($i === -1) throw new RuntimeException('Categoria não encontrada.');

    if ($cmd === 'delete') {
        foreach ($data['machines'] as $m) {
            if ($m['category'] === $id) {
                throw new RuntimeException('Essa categoria ainda tem máquinas. Mova ou exclua as máquinas antes.');
            }
        }
        $name = $data['categories'][$i]['name'];
        array_splice($data['categories'], $i, 1);
        save_data($data);
        flash('Categoria "' . $name . '" excluída.');
    } elseif ($cmd === 'up' || $cmd === 'down') {
        $j = $cmd === 'up' ? $i - 1 : $i + 1;
        if (isset($data['categories'][$j])) {
            [$data['categories'][$i], $data['categories'][$j]] = [$data['categories'][$j], $data['categories'][$i]];
            save_data($data);
        }
    }
    redirect('page=categories');
}

function handle_contact(array $data): void
{
    $email = post_text('email', 200);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('O e-mail digitado não é válido.');
    }
    $social = [];
    foreach (['facebook', 'instagram', 'x', 'linkedin'] as $k) {
        $url = post_text('social_' . $k, 300);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('O link do ' . $k . ' precisa começar com https://');
        }
        $social[$k] = $url;
    }
    // E-mail do responsável pelo atendimento (opcional, só do domínio da empresa)
    $responsible = strtolower(post_text('responsible', 200));
    if ($responsible !== '') {
        $domain = company_domain(['smtp_host' => post_text('smtp_host', 200), 'smtp_user' => post_text('smtp_user', 200), 'smtp_pass' => 'x']);
        if (!filter_var($responsible, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('O e-mail do responsável não é válido.');
        }
        if (!str_ends_with_domain($responsible, $domain)) {
            throw new RuntimeException('O e-mail do responsável precisa ser da empresa (@' . $domain . '). E-mails particulares (Gmail, Hotmail etc.) não são aceitos.');
        }
    }

    $data['contact'] = [
        'email' => $email,
        'phone' => post_text('phone', 60),
        'address' => post_text('address', 200),
        'address2' => post_text('address2', 300),
        'hours' => post_text('hours', 200),
        'social' => $social,
        'translations' => [],
    ];
    $hoursTr = is_array($_POST['hours_tr'] ?? null) ? $_POST['hours_tr'] : [];
    foreach (TR_LANGS as $lg => $label) {
        $data['contact']['translations'][$lg] = ['hours' => clean_text($hoursTr[$lg] ?? '', 200)];
    }
    // Fica fora do site.json (que é público), para não expor o e-mail a robôs
    $settings = load_settings();
    $settings['form_recipient'] = '';                // campo antigo, não é mais usado
    $settings['responsible'] = $responsible;
    $settings['reply_copy'] = [];                    // respostas pelo painel foram removidas

    // Servidor de envio (SMTP) — opcional
    $smtpHost = post_text('smtp_host', 200);
    $smtpUser = post_text('smtp_user', 200);
    $smtpPass = (string)($_POST['smtp_pass'] ?? '');
    $smtpSecure = in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $_POST['smtp_secure'] : 'ssl';
    $smtpPort = (int)post_text('smtp_port', 6);
    if ($smtpHost !== '' && !preg_match('/^[A-Za-z0-9.-]+$/', $smtpHost)) {
        throw new RuntimeException('O servidor SMTP deve ser só o endereço, sem http:// (ex.: mail.example.com).');
    }
    if ($smtpUser !== '' && !filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('O usuário do SMTP deve ser o e-mail completo da conta (ex.: site@example.com).');
    }
    $settings['smtp_host'] = $smtpHost;
    $settings['smtp_user'] = $smtpUser;
    $settings['smtp_secure'] = $smtpSecure;
    $settings['smtp_port'] = $smtpPort > 0 && $smtpPort < 65536 ? $smtpPort : ($smtpSecure === 'tls' ? 587 : 465);
    $settings['smtp_noverify'] = !empty($_POST['smtp_noverify']);
    // Proteção contra spam (CAPTCHA) — opcional
    $capProvider = in_array($_POST['captcha_provider'] ?? '', ['recaptcha', 'turnstile'], true) ? $_POST['captcha_provider'] : '';
    $capSite = post_text('captcha_site_key', 200);
    $capSecret = trim((string)($_POST['captcha_secret'] ?? ''));
    if ($capSite !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $capSite)) throw new RuntimeException('A chave do site (CAPTCHA) parece inválida — copie de novo, sem espaços.');
    if ($capSecret !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $capSecret)) throw new RuntimeException('A chave secreta (CAPTCHA) parece inválida — copie de novo, sem espaços.');
    if ($capProvider !== ($settings['captcha_provider'] ?? '') && $capSecret === '') {
        $settings['captcha_secret'] = '';            // trocou de serviço: a chave antiga não serve mais
    }
    $settings['captcha_provider'] = $capProvider;
    $settings['captcha_site_key'] = $capSite;
    if ($capSecret !== '') $settings['captcha_secret'] = $capSecret;
    // a chave do site é pública (vai para o site.json); a secreta fica só aqui
    $data['captcha'] = captcha_enabled($settings) ? ['provider' => $capProvider, 'siteKey' => $capSite] : ['provider' => '', 'siteKey' => ''];
    save_data($data);

    if (!empty($_POST['smtp_clear'])) {
        $settings['smtp_pass'] = '';
    } elseif ($smtpPass !== '') {
        $settings['smtp_pass'] = $smtpPass;                // campo vazio = mantém a senha já salva
    }
    save_settings($settings);
    flash('Dados de contato salvos.');
    redirect('page=contact');
}

function handle_messages(): void
{
    $action = post_text('action', 20);
    $messages = load_messages();
    if ($action === 'delete') {
        $id = post_text('id', 40);
        $messages = array_values(array_filter($messages, function ($m) use ($id) { return ($m['id'] ?? '') !== $id; }));
        save_messages($messages);
        flash('Mensagem excluída.');
    }
    redirect('page=messages');
}

/* =========================================================
   Telas
   ========================================================= */

function render_header(string $title, bool $nav = true): void
{
    global $page;
    $flash = take_flash();
    ?><!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> · Painel Nordtec</title>
  <link rel="icon" href="../img/favicon-32.png">
  <link rel="stylesheet" href="admin.css?v=1">
</head>
<body>
<header class="topbar">
  <a class="topbar__brand" href="index.php"><img src="../img/logo-white.webp" alt="Nordtec"><span>Painel</span></a>
  <?php if ($nav): ?>
  <nav class="topbar__nav">
    <a href="index.php"<?= in_array($page, ['machines', 'machine'], true) ? ' class="is-active"' : '' ?>>Máquinas</a>
    <a href="index.php?page=categories"<?= $page === 'categories' ? ' class="is-active"' : '' ?>>Categorias</a>
    <a href="index.php?page=contact"<?= $page === 'contact' ? ' class="is-active"' : '' ?>>Contato</a>
    <?php $unread = $page === 'messages' ? 0 : unread_messages_count(); ?>
    <a href="index.php?page=messages"<?= $page === 'messages' ? ' class="is-active"' : '' ?>>Mensagens<?php if ($unread): ?> <span class="badge"><?= $unread ?></span><?php endif; ?></a>
    <a href="index.php?page=help"<?= $page === 'help' ? ' class="is-active"' : '' ?>>Ajuda</a>
  </nav>
  <div class="topbar__right">
    <a class="btn btn--ghost btn--sm" href="../" target="_blank" rel="noopener">Ver site ↗</a>
    <form method="post" action="index.php?page=logout"><?= csrf_field() ?><button class="btn btn--ghost btn--sm">Sair</button></form>
  </div>
  <?php endif; ?>
</header>
<main class="wrap">
<?php if ($flash): ?>
  <div class="alert alert--<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif;
}

function render_footer(): void
{
    ?></main>
<script src="admin.js?v=1"></script>
</body>
</html>
<?php
}

function render_setup(?string $error): void
{
    ?>
  <div class="card card--narrow">
    <h1>Bem-vindo ao painel da Nordtec</h1>
    <p class="muted">Este é o primeiro acesso. Crie a senha que será usada para entrar no painel. Guarde-a em um lugar seguro.</p>
    <?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label class="field"><span>Nova senha (mínimo 10 caracteres)</span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
      <label class="field"><span>Repita a senha</span><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
      <button class="btn btn--block">Criar senha e entrar</button>
    </form>
  </div>
<?php
}

function render_login(?string $error): void
{
    ?>
  <div class="card card--narrow">
    <h1>Entrar no painel</h1>
    <?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label class="field"><span>Senha</span><input type="password" name="password" required autofocus autocomplete="current-password"></label>
      <button class="btn btn--block">Entrar</button>
    </form>
  </div>
<?php
}

function render_machine_list(array $data): void
{
    $cats = [];
    foreach ($data['categories'] as $c) $cats[$c['id']] = $c['name'];
    $total = count($data['machines']);
    ?>
  <div class="page-head">
    <div>
      <h1>Máquinas</h1>
      <p class="muted"><?= $total ?> máquina(s). A ordem desta lista é a ordem em que aparecem no site.</p>
    </div>
    <a class="btn" href="index.php?page=machine">+ Nova máquina</a>
  </div>

  <div class="list">
    <?php foreach ($data['machines'] as $i => $m): ?>
      <div class="list__row">
        <img class="list__thumb" src="<?= e(image_url($m['id'], $m['image'] ?: 'main.jpg')) ?>" alt="">
        <div class="list__info">
          <a class="list__title" href="index.php?page=machine&amp;id=<?= e(rawurlencode($m['id'])) ?>"><?= e($m['name']) ?></a>
          <div class="muted small">
            <?= e($cats[$m['category']] ?? 'Sem categoria') ?>
            <?php if (!empty($m['featured'])): ?> · <span class="tag">Destaque na Home</span><?php endif; ?>
          </div>
        </div>
        <form method="post" class="list__actions">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= e($m['id']) ?>">
          <button class="icon-btn" name="action" value="up" title="Subir"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
          <button class="icon-btn" name="action" value="down" title="Descer"<?= $i === $total - 1 ? ' disabled' : '' ?>>↓</button>
          <a class="btn btn--sm btn--outline" href="index.php?page=machine&amp;id=<?= e(rawurlencode($m['id'])) ?>">Editar</a>
          <button class="btn btn--sm btn--danger" name="action" value="delete" data-confirm="Excluir &quot;<?= e($m['name']) ?>&quot;? Essa ação remove a máquina do site.">Excluir</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php
}

function render_machine_form(array $data, array $m, bool $isNew, ?string $error): void
{
    $id = $m['id'] ?? '';
    $img = function ($file) use ($id) { return $id !== '' && $file !== '' ? image_url($id, $file) : ''; };
    ?>
  <div class="page-head">
    <div>
      <a class="muted small" href="index.php">← Voltar para a lista</a>
      <h1><?= $isNew ? 'Nova máquina' : e($m['name']) ?></h1>
    </div>
    <?php if (!$isNew): ?><a class="btn btn--ghost-dark" href="../machine?id=<?= e(rawurlencode($id)) ?>" target="_blank" rel="noopener">Ver no site ↗</a><?php endif; ?>
  </div>

  <?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="form" data-warn-unsaved data-lang-form>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e($isNew ? '' : $id) ?>">

    <div class="lang-tabs" role="tablist" aria-label="Idioma do texto">
      <span class="lang-tabs__label">Editando o texto em:</span>
      <?php foreach (ALL_LANGS as $lg => $label): ?>
        <button type="button" role="tab" data-lang-tab="<?= $lg ?>"<?= $lg === 'en' ? ' aria-selected="true"' : ' aria-selected="false"' ?>><?= e($label) ?><?php if ($lg !== 'en'): ?> <span class="lang-tabs__status" title="Campos traduzidos"><?= tr_progress($m, $lg) ?></span><?php endif; ?></button>
      <?php endforeach; ?>
      <small class="lang-tabs__hint">Nome, categoria e fotos valem para todos os idiomas. Textos em alemão ou português deixados em branco aparecem em inglês no site.</small>
    </div>

    <section class="card">
      <h2>Informações principais</h2>
      <div class="grid-2">
        <label class="field"><span>Nome *</span><input name="name" value="<?= e($m['name']) ?>" required maxlength="120"></label>
        <label class="field"><span>Categoria *</span>
          <select name="category" required>
            <?php foreach ($data['categories'] as $c): ?>
              <option value="<?= e($c['id']) ?>"<?= $c['id'] === $m['category'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <?php render_tr_field($m, 'subtitle', 'Subtítulo <em>(frase curta que aparece no card e no topo da página)</em>', 'input', 1, 300); ?>
      <?php render_tr_field($m, 'description', 'Descrição <em>(opcional — texto corrido abaixo do subtítulo; deixe uma linha em branco entre parágrafos)</em>', 'textarea', 5, 5000); ?>
      <label class="check"><input type="checkbox" name="featured" value="1"<?= !empty($m['featured']) ? ' checked' : '' ?>> Mostrar no carrossel de destaques da página inicial</label>
    </section>

    <section class="card">
      <h2>Foto principal <?= $isNew ? '*' : '' ?></h2>
      <div class="photo-row">
        <div class="photo-preview" data-preview-for="main_image">
          <?php if ($img($m['image'] ?? '')): ?><img src="<?= e($img($m['image'])) ?>" alt=""><?php else: ?><span>Sem foto</span><?php endif; ?>
        </div>
        <label class="field"><span><?= $isNew ? 'Escolha a foto' : 'Trocar foto' ?> <em>(JPG, PNG ou WebP, até 10 MB)</em></span>
          <small class="hint">Ideal: foto <strong>deitada (horizontal)</strong>, com cerca de <strong>1600 × 1067 pixels</strong>. Fotos em pé ou quadradas também funcionam — aparecem inteiras, com fundo branco nas laterais.</small>
          <input type="file" name="main_image" id="main_image" accept="image/jpeg,image/png,image/webp"<?= $isNew && empty($m['image']) ? ' required' : '' ?>>
        </label>
      </div>
    </section>

    <section class="card">
      <h2>Destaques</h2>
      <?php render_tr_field($m, 'highlights', 'Um destaque por linha', 'textarea', 7, 5000); ?>
    </section>

    <section class="card">
      <h2>Dados técnicos</h2>
      <?php render_tr_field($m, 'specs', 'Um item por linha, no formato <strong>Nome: valor</strong>', 'textarea', 6, 5000); ?>
    </section>

    <section class="card">
      <h2>Galeria de fotos</h2>
      <p class="muted small">Fotos extras que aparecem na página da máquina. Clique na foto no site para ampliar.</p>
      <?php if (!empty($m['gallery'])): ?>
        <div class="gallery-edit">
          <?php foreach ($m['gallery'] as $g): ?>
            <label class="gallery-edit__item">
              <img src="<?= e($img($g)) ?>" alt="">
              <span class="check check--small"><input type="checkbox" name="gallery_remove[]" value="<?= e($g) ?>"> Remover</span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <label class="field"><span>Adicionar fotos <em>(pode escolher várias de uma vez)</em></span>
        <input type="file" name="gallery_new[]" multiple accept="image/jpeg,image/png,image/webp">
      </label>
    </section>

    <section class="card">
      <h2>Recursos <em class="muted">(opcional)</em></h2>
      <p class="muted small">Blocos com foto, título e lista — como na página da AutoLoad 500.</p>
      <?php render_tr_field($m, 'featuresTitle', 'Título da seção <em>(opcional)</em>', 'input', 1, 200); ?>
      <div class="features" id="features">
        <?php foreach ($m['features'] ?? [] as $idx => $f) render_feature_block($f, $img($f['image'] ?? ''), feature_translations($m, (int)$idx)); ?>
      </div>
      <template id="feature-template"><?php render_feature_block(['title' => '', 'items' => [], 'image' => ''], '', []); ?></template>
      <button type="button" class="btn btn--outline btn--sm" data-add-feature>+ Adicionar bloco</button>
    </section>

    <div class="savebar">
      <a class="btn btn--ghost-dark" href="index.php">Cancelar</a>
      <button class="btn">Salvar</button>
    </div>
  </form>
<?php
}

function render_feature_block(array $f, string $imgUrl, array $trs): void
{
    $enTitle = $f['title'] ?? '';
    $enItems = implode("\n", $f['items'] ?? []);
    ?>
        <div class="feature-block">
          <div class="photo-preview photo-preview--small"><?php if ($imgUrl): ?><img src="<?= e($imgUrl) ?>" alt=""><?php else: ?><span>Sem foto</span><?php endif; ?></div>
          <div class="feature-block__fields">
            <input type="hidden" name="feature_image[]" value="<?= e($f['image'] ?? '') ?>">
            <div class="lang-pane" data-lang-pane="en">
              <label class="field"><span>Título</span><input name="feature_title[]" value="<?= e($enTitle) ?>" maxlength="150"></label>
              <label class="field"><span>Itens (um por linha)</span><textarea name="feature_items[]" rows="3"><?= e($enItems) ?></textarea></label>
            </div>
            <?php foreach (TR_LANGS as $lg => $label): $t = $trs[$lg] ?? []; ?>
            <div class="lang-pane" data-lang-pane="<?= $lg ?>">
              <label class="field"><span>Título <span class="lang-badge"><?= strtoupper($lg) ?></span></span><input name="feature_tr[<?= $lg ?>][title][]" value="<?= e($t['title'] ?? '') ?>" maxlength="150" placeholder="<?= e($enTitle) ?>"></label>
              <label class="field"><span>Itens (um por linha) <span class="lang-badge"><?= strtoupper($lg) ?></span></span><textarea name="feature_tr[<?= $lg ?>][items][]" rows="3" placeholder="<?= e($enItems) ?>"><?= e(implode("\n", $t['items'] ?? [])) ?></textarea></label>
            </div>
            <?php endforeach; ?>
            <label class="field"><span>Foto</span><input type="file" name="feature_file[]" accept="image/jpeg,image/png,image/webp"></label>
          </div>
          <button type="button" class="icon-btn icon-btn--danger" data-remove-feature title="Remover bloco">✕</button>
        </div>
<?php
}

/* Valor de um campo traduzível no idioma pedido */
function tr_value(array $m, string $lang, string $field)
{
    if ($lang === 'en') return $m[$field] ?? '';
    return $m['translations'][$lang][$field] ?? '';
}

/* Converte o valor guardado no texto do formulário */
function tr_text(string $field, $value): string
{
    if ($field === 'specs') return specs_to_text(is_array($value) ? $value : []);
    if (is_array($value)) return implode("\n", $value);
    return (string)$value;
}

/* Campo com uma versão para cada idioma (só a aba ativa aparece) */
function render_tr_field(array $m, string $field, string $labelHtml, string $kind, int $rows, int $max): void
{
    $en = tr_text($field, tr_value($m, 'en', $field));
    foreach (ALL_LANGS as $lg => $label) {
        $name = $lg === 'en' ? $field : "tr[$lg][$field]";
        $value = tr_text($field, tr_value($m, $lg, $field));
        $ph = $lg === 'en' ? '' : $en;
        echo '<div class="lang-pane" data-lang-pane="' . $lg . '"><label class="field"><span>' . $labelHtml;
        if ($lg !== 'en') echo ' <span class="lang-badge">' . strtoupper($lg) . '</span>';
        echo '</span>';
        if ($kind === 'textarea') {
            echo '<textarea name="' . e($name) . '" rows="' . $rows . '" maxlength="' . $max . '" placeholder="' . e($ph) . '">' . e($value) . '</textarea>';
        } else {
            echo '<input name="' . e($name) . '" value="' . e($value) . '" maxlength="' . $max . '" placeholder="' . e($ph) . '">';
        }
        echo '</label></div>';
    }
}

/* Traduções de um bloco de recursos */
function feature_translations(array $m, int $idx): array
{
    $out = [];
    foreach (TR_LANGS as $lg => $label) {
        $out[$lg] = $m['translations'][$lg]['features'][$idx] ?? [];
    }
    return $out;
}

/* "3/5": quantos campos com texto em inglês já têm tradução */
function tr_progress(array $m, string $lg): string
{
    $total = 0;
    $done = 0;
    foreach (['subtitle', 'description', 'highlights', 'specs', 'featuresTitle'] as $f) {
        if (!empty($m[$f])) { $total++; if (!empty($m['translations'][$lg][$f])) $done++; }
    }
    foreach ($m['features'] ?? [] as $i => $f) {
        if (($f['title'] ?? '') !== '' || !empty($f['items'])) {
            $total++;
            $t = $m['translations'][$lg]['features'][$i] ?? [];
            if (($t['title'] ?? '') !== '' || !empty($t['items'])) $done++;
        }
    }
    return $done . '/' . $total;
}

function render_categories(array $data): void
{
    $count = [];
    foreach ($data['machines'] as $m) $count[$m['category']] = ($count[$m['category']] ?? 0) + 1;
    $total = count($data['categories']);
    ?>
  <div class="page-head">
    <div>
      <h1>Categorias</h1>
      <p class="muted">Categorias sem máquinas não aparecem na página inicial.</p>
    </div>
  </div>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <!-- Botão padrão: apertar Enter num campo salva (em vez de acionar "Subir") -->
    <button class="visually-hidden" name="action" value="save" tabindex="-1" aria-hidden="true">Salvar</button>
    <table class="table">
      <thead><tr><th>Ordem</th><th>Nome (EN)</th><th>Deutsch</th><th>Português</th><th>Foto de capa</th><th>Máquinas</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($data['categories'] as $i => $c): $n = $count[$c['id']] ?? 0; ?>
        <tr>
          <td class="nowrap">
            <button class="icon-btn" name="action" value="up:<?= e($c['id']) ?>" title="Subir"<?= $i === 0 ? ' disabled' : '' ?>>↑</button>
            <button class="icon-btn" name="action" value="down:<?= e($c['id']) ?>" title="Descer"<?= $i === $total - 1 ? ' disabled' : '' ?>>↓</button>
          </td>
          <td><input name="name[<?= e($c['id']) ?>]" value="<?= e($c['name']) ?>" maxlength="80" aria-label="Nome em inglês"></td>
          <?php foreach (TR_LANGS as $lg => $label): ?>
          <td><input name="name_tr[<?= $lg ?>][<?= e($c['id']) ?>]" value="<?= e($c['translations'][$lg]['name'] ?? '') ?>" maxlength="80" placeholder="<?= e($c['name']) ?>" aria-label="Nome em <?= e($label) ?>"></td>
          <?php endforeach; ?>
          <td>
            <select name="cover[<?= e($c['id']) ?>]" aria-label="Foto de capa">
              <option value="">Primeira máquina da categoria</option>
              <?php foreach ($data['machines'] as $m): if ($m['category'] !== $c['id']) continue; ?>
                <option value="<?= e($m['id']) ?>"<?= ($c['cover'] ?? '') === $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><?= $n ?></td>
          <td class="nowrap">
            <button class="btn btn--sm btn--danger" name="action" value="delete:<?= e($c['id']) ?>"<?= $n ? ' disabled title="Mova ou exclua as máquinas desta categoria antes"' : '' ?> data-confirm="Excluir a categoria &quot;<?= e($c['name']) ?>&quot;?">Excluir</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted small">Nomes em alemão ou português deixados em branco aparecem em inglês no site.</p>
    <div class="savebar savebar--inline"><button class="btn" name="action" value="save">Salvar nomes e capas</button></div>
  </form>

  <form method="post" class="card">
    <?= csrf_field() ?>
    <h2>Nova categoria</h2>
    <div class="inline-form">
      <input name="new_name" placeholder="Ex.: Engraving" maxlength="80" required aria-label="Nome da nova categoria">
      <button class="btn" name="action" value="add">Criar categoria</button>
    </div>
  </form>
<?php
}

function render_contact(array $data): void
{
    $c = $data['contact'] + ['email' => '', 'phone' => '', 'address' => '', 'hours' => '', 'social' => []];
    $s = $c['social'] + ['facebook' => '', 'instagram' => '', 'x' => '', 'linkedin' => ''];
    foreach ($s as $k => $v) if ($v === '#') $s[$k] = '';
    ?>
  <div class="page-head"><div><h1>Contato</h1><p class="muted">Aparece no rodapé, na página de contato e nas páginas das máquinas.</p></div></div>
  <form method="post" class="form" data-warn-unsaved>
    <?= csrf_field() ?>
    <section class="card">
      <h2>Dados da empresa</h2>
      <div class="grid-2">
        <label class="field"><span>E-mail</span><input type="email" name="email" value="<?= e($c['email']) ?>"></label>
        <label class="field"><span>Telefone</span><input name="phone" value="<?= e($c['phone']) ?>"></label>
      </div>
      <label class="field"><span>Endereço</span><input name="address" value="<?= e($c['address']) ?>"></label>
      <label class="field"><span>Segundo endereço <em>(opcional — ex.: uma filial; deixe em branco para não mostrar)</em></span><input name="address2" value="<?= e($c['address2'] ?? '') ?>" maxlength="300"></label>
      <label class="field"><span>Horário de atendimento <span class="lang-badge">EN</span></span><input name="hours" value="<?= e($c['hours']) ?>"></label>
      <div class="grid-2">
        <?php foreach (TR_LANGS as $lg => $label): ?>
        <label class="field"><span>Horário de atendimento <span class="lang-badge"><?= strtoupper($lg) ?></span></span><input name="hours_tr[<?= $lg ?>]" value="<?= e($c['translations'][$lg]['hours'] ?? '') ?>" placeholder="<?= e($c['hours']) ?>"></label>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="card">
      <h2>Formulário de contato</h2>
      <?php $settings = load_settings(); ?>
      <?php $inbox = company_inbox($settings); ?>
      <label class="field"><span>E-mail do responsável pelo atendimento <em>(opcional — só e-mails @<?= e(company_domain($settings)) ?>)</em></span>
        <input type="email" name="responsible" value="<?= e($settings['responsible']) ?>" placeholder="ex.: joao@<?= e(company_domain($settings)) ?>">
      </label>
      <?php if ($inbox !== ''): ?>
        <p class="muted small">
          Os avisos de novas mensagens do site vão para <strong><?= e(responsible_email($settings)) ?></strong><?= responsible_email($settings) !== $inbox ? ' (responsável)' : ' (a própria conta de envio, porque o campo acima está vazio)' ?>, enviados pela conta <strong><?= e($inbox) ?></strong>.
          Para responder ao cliente, basta abrir o aviso nesse e-mail e clicar em <strong>Responder</strong> — a resposta vai direto para o cliente.
          As mensagens também ficam guardadas na aba <a href="index.php?page=messages">Mensagens</a>.
        </p>
      <?php else: ?>
        <div class="alert alert--warn">Configure o <a href="#smtp">Servidor de envio (SMTP)</a> abaixo com a conta de e-mail da empresa (ex.: info@example.com). Enquanto isso, as mensagens do site ficam só na aba Mensagens e nenhum aviso é enviado por e-mail.</div>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>Redes sociais</h2>
      <p class="muted small">Deixe em branco as redes que a empresa não usa — elas somem do site.</p>
      <div class="grid-2">
        <label class="field"><span>Facebook</span><input type="url" name="social_facebook" value="<?= e($s['facebook']) ?>" placeholder="https://"></label>
        <label class="field"><span>Instagram</span><input type="url" name="social_instagram" value="<?= e($s['instagram']) ?>" placeholder="https://"></label>
        <label class="field"><span>X (Twitter)</span><input type="url" name="social_x" value="<?= e($s['x']) ?>" placeholder="https://"></label>
        <label class="field"><span>LinkedIn</span><input type="url" name="social_linkedin" value="<?= e($s['linkedin']) ?>" placeholder="https://"></label>
      </div>
    </section>
    <section class="card" id="captcha">
      <h2>Proteção contra spam (CAPTCHA) <em class="muted">(opcional)</em></h2>
      <p class="muted small">Adiciona uma verificação "não sou um robô" no formulário de contato. O formulário já tem proteções invisíveis; o CAPTCHA bloqueia também os robôs mais espertos. Para ativar, crie as chaves (grátis) para o domínio <strong>example.com</strong> e cole aqui:</p>
      <ul class="muted small">
        <li><strong>Cloudflare Turnstile</strong> (recomendado: normalmente o visitante nem precisa clicar e é mais amigável à privacidade): <em>dash.cloudflare.com → Turnstile → Add site</em>.</li>
        <li><strong>Google reCAPTCHA v2</strong> (a caixinha "Não sou um robô"): <em>google.com/recaptcha/admin → criar → tipo "Desafio (v2)" → caixa de seleção</em>. Atenção: as chaves do reCAPTCHA v3 não funcionam aqui.</li>
      </ul>
      <div class="grid-2">
        <label class="field"><span>Serviço</span>
          <select name="captcha_provider">
            <option value="">Desativado</option>
            <option value="turnstile"<?= $settings['captcha_provider'] === 'turnstile' ? ' selected' : '' ?>>Cloudflare Turnstile</option>
            <option value="recaptcha"<?= $settings['captcha_provider'] === 'recaptcha' ? ' selected' : '' ?>>Google reCAPTCHA v2</option>
          </select>
        </label>
        <label class="field"><span>Chave do site <em>(site key)</em></span><input name="captcha_site_key" value="<?= e($settings['captcha_site_key']) ?>" autocomplete="off"></label>
        <label class="field"><span>Chave secreta <em>(secret key)</em></span><input type="password" name="captcha_secret" value="" autocomplete="new-password" placeholder="<?= $settings['captcha_secret'] !== '' ? '•••••••• (salva — deixe em branco para manter)' : 'cole a chave secreta' ?>"></label>
      </div>
      <p class="small">
        Situação: <?= captcha_enabled($settings) ? '<span class="tag tag--ok">Ativo (' . ($settings['captcha_provider'] === 'recaptcha' ? 'Google reCAPTCHA' : 'Cloudflare Turnstile') . ')</span>' : '<span class="tag">Desativado</span>' ?>
        <?php if (captcha_enabled($settings)): ?> — depois de ativar, abra a página de contato do site e envie uma mensagem de teste para conferir.<?php endif; ?>
      </p>
    </section>
    <section class="card" id="smtp">
      <h2>Servidor de envio (SMTP) <em class="muted">(recomendado)</em></h2>
      <p class="muted small">Faz o site enviar os e-mails <strong>entrando numa conta de e-mail da empresa</strong> (ex.: site@example.com), com usuário e senha. É o jeito mais confiável de os e-mails chegarem ao Gmail, Outlook etc. sem cair no spam. Os dados ficam no cPanel em <em>Contas de e-mail → Conectar dispositivos / Configurar cliente de e-mail</em> (use os dados de "Servidor de saída"). Se deixar em branco, o site usa o método simples do servidor.</p>
      <div class="grid-2">
        <label class="field"><span>Servidor de saída (SMTP)</span><input name="smtp_host" value="<?= e($settings['smtp_host']) ?>" placeholder="ex.: mail.example.com"></label>
        <label class="field"><span>Segurança e porta</span>
          <span class="inline-form">
            <select name="smtp_secure" aria-label="Segurança">
              <option value="ssl"<?= $settings['smtp_secure'] === 'ssl' ? ' selected' : '' ?>>SSL</option>
              <option value="tls"<?= $settings['smtp_secure'] === 'tls' ? ' selected' : '' ?>>TLS / STARTTLS</option>
              <option value="none"<?= $settings['smtp_secure'] === 'none' ? ' selected' : '' ?>>Nenhuma</option>
            </select>
            <input name="smtp_port" value="<?= e($settings['smtp_port']) ?>" inputmode="numeric" aria-label="Porta" placeholder="465">
          </span>
          <small class="hint">Normalmente: SSL com porta 465, ou TLS com porta 587.</small>
        </label>
        <label class="field"><span>Usuário (e-mail completo da conta)</span><input type="email" name="smtp_user" value="<?= e($settings['smtp_user']) ?>" placeholder="ex.: site@example.com" autocomplete="off"></label>
        <label class="field"><span>Senha da conta</span><input type="password" name="smtp_pass" value="" autocomplete="new-password" placeholder="<?= $settings['smtp_pass'] !== '' ? '•••••••• (salva — deixe em branco para manter)' : 'senha do e-mail' ?>"></label>
      </div>
      <label class="check check--small"><input type="checkbox" name="smtp_noverify" value="1"<?= !empty($settings['smtp_noverify']) ? ' checked' : '' ?>> Não verificar o certificado de segurança (marque só se o suporte da hospedagem indicar problema de certificado)</label>
      <?php if ($settings['smtp_pass'] !== ''): ?>
        <label class="check check--small"><input type="checkbox" name="smtp_clear" value="1"> Apagar a senha salva (volta ao método simples)</label>
      <?php endif; ?>
      <p class="small <?= smtp_configured($settings) ? '' : 'muted' ?>">
        Situação: <?= smtp_configured($settings) ? '<span class="tag tag--ok">Enviando por SMTP (' . e($settings['smtp_user']) . ')</span>' : '<span class="tag">Usando o método simples do servidor</span>' ?>
      </p>
    </section>
    <div class="savebar"><button class="btn">Salvar</button></div>
  </form>

<?php
}

function render_messages(): void
{
    $messages = load_messages();
    $settings = load_settings();
    $labels = [
        'sent' => ['Aviso enviado por e-mail', 'tag--ok'],
        'failed' => ['Falha no envio do aviso', 'tag--error'],
        'not_configured' => ['Só no painel', 'tag'],
    ];
    ?>
  <div class="page-head">
    <div>
      <h1>Mensagens</h1>
      <p class="muted">Mensagens enviadas pelo formulário de contato do site (as <?= MESSAGES_TO_KEEP ?> mais recentes). Para responder, use o e-mail: cada mensagem chega como aviso <?= responsible_email($settings) !== '' ? 'em <strong>' . e(responsible_email($settings)) . '</strong>' : 'na caixa de e-mail da empresa' ?> — clique em "Responder" no próprio e-mail.</p>
    </div>
  </div>

  <?php if (company_inbox($settings) === ''): ?>
    <div class="alert alert--warn">A conta de e-mail da empresa ainda não foi configurada — as mensagens ficam só aqui e nenhum aviso é enviado por e-mail. Configure em <a href="index.php?page=contact#smtp">Contato → Servidor de envio (SMTP)</a>.</div>
  <?php endif; ?>

  <?php if (!$messages): ?>
    <div class="card"><p class="muted">Nenhuma mensagem recebida ainda.</p></div>
  <?php endif; ?>

  <?php foreach ($messages as $m):
        $label = $labels[$m['status'] ?? ''] ?? ['', 'tag'];
  ?>
    <article class="card message<?= empty($m['read']) ? ' message--unread' : '' ?>" id="msg-<?= e($m['id']) ?>">
      <header class="message__head">
        <div>
          <h2><?= e($m['subject']) ?></h2>
          <div class="muted small">
            <strong><?= e($m['name']) ?></strong> · <a href="mailto:<?= e($m['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $m['subject'])) ?>"><?= e($m['email']) ?></a>
            <?php if (!empty($m['phone'])): ?> · <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['phone'])) ?>"><?= e($m['phone']) ?></a><?php endif; ?>
            · <?= e(date('d/m/Y H:i', strtotime($m['date']))) ?>
            · <span class="tag <?= e($label[1]) ?>"<?= !empty($m['error']) ? ' title="' . e($m['error']) . '"' : '' ?>><?= e($label[0]) ?></span>
          </div>
        </div>
        <form method="post" class="list__actions">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= e($m['id']) ?>">
          <button class="btn btn--sm btn--danger" name="action" value="delete" data-confirm="Excluir esta mensagem?">Excluir</button>
        </form>
      </header>
      <p class="message__body"><?= nl2br(e($m['message'])) ?></p>

    </article>
  <?php endforeach;

    // Ao abrir a aba, todas passam a contar como lidas
    if (array_filter($messages, function ($m) { return empty($m['read']); })) {
        foreach ($messages as &$m) $m['read'] = true;
        unset($m);
        save_messages($messages);
    }
}

function render_help(): void
{
    ?>
  <div class="page-head"><div><h1>Ajuda</h1></div></div>
  <div class="card prose">
    <h2>Adicionar uma máquina</h2>
    <ol>
      <li>Em <strong>Máquinas</strong>, clique em <strong>+ Nova máquina</strong>.</li>
      <li>Preencha nome, categoria e subtítulo e escolha a foto principal.</li>
      <li>Se quiser, adicione destaques, dados técnicos, fotos na galeria e blocos de recursos.</li>
      <li>Clique em <strong>Salvar</strong>. A máquina já aparece no site.</li>
    </ol>
    <h2>Traduções (alemão e português)</h2>
    <p>O site tem um seletor de idioma <strong>EN | DE | PT</strong>. Ao editar uma máquina, use as abas <strong>English / Deutsch / Português</strong> no topo do formulário para preencher os textos em cada idioma. O número ao lado da aba mostra quantos campos já estão traduzidos.</p>
    <p>Se um campo em alemão ou português ficar em branco, o site mostra o texto em inglês no lugar — nada fica vazio. Nomes de categoria e o horário de atendimento também podem ser traduzidos, nas abas Categorias e Contato.</p>
    <h2>Adicionar fotos a uma máquina</h2>
    <p>Abra a máquina, vá até <strong>Galeria de fotos</strong>, clique em <em>Adicionar fotos</em> (pode escolher várias) e salve. Para tirar uma foto, marque <em>Remover</em> embaixo dela e salve.</p>
    <h2>Criar uma categoria</h2>
    <p>Em <strong>Categorias</strong>, digite o nome em <em>Nova categoria</em> e clique em <strong>Criar categoria</strong>. Depois, edite as máquinas e escolha a categoria nova. A categoria só aparece na página inicial quando tiver pelo menos uma máquina.</p>
    <h2>Mensagens do formulário de contato</h2>
    <p>Tudo o que os visitantes enviam pelo formulário do site fica na aba <strong>Mensagens</strong> (para consulta) e chega como aviso no <strong>e-mail do responsável</strong> (Contato → Formulário de contato) — ou na conta de envio, ex.: info@example.com, se esse campo estiver vazio.</p>
    <p><strong>Para responder:</strong> abra o aviso no e-mail (pelo webmail em webmail.example.com ou no programa de e-mail) e clique em <strong>Responder</strong>. A resposta vai direto para o cliente, e toda a conversa continua pelo e-mail.</p>
    <p>Quando a pessoa responsável mudar, basta trocar o <strong>e-mail do responsável</strong> em Contato. Ele precisa ser um e-mail da empresa (@example.com); e-mails particulares não são aceitos.</p>
    <p>Se uma mensagem aparecer como "Falha no envio do aviso", o servidor não conseguiu enviar o e-mail: confira os dados do <strong>Servidor de envio (SMTP)</strong> na aba Contato (usuário e senha da conta) e, se continuar, fale com o suporte da hospedagem.</p>
    <h2>Muito spam no formulário?</h2>
    <p>Ative a <strong>Proteção contra spam (CAPTCHA)</strong> na aba Contato. Escolha o serviço, cole as duas chaves e salve. Depois envie uma mensagem de teste pelo site. Se o formulário parar de funcionar, volte o serviço para "Desativado" e confira as chaves (elas precisam ter sido criadas para o domínio do site).</p>
    <h2>Os e-mails não chegam?</h2>
    <p>Configure o <strong>Servidor de envio (SMTP)</strong> na aba Contato com uma conta de e-mail da empresa (ex.: site@example.com, criada no cPanel) e salve. Depois mande uma mensagem de teste pelo formulário do site e veja se o aviso chega. Quando o SMTP está configurado, os avisos do formulário saem por essa conta.</p>
    <h2>Dicas para as fotos</h2>
    <ul>
      <li>O tamanho ideal é uma foto <strong>deitada (horizontal)</strong> com cerca de <strong>1600 × 1067 pixels</strong> (proporção 3:2, a mesma da maioria das câmeras e celulares na horizontal).</li>
      <li>O site nunca corta as fotos: fotos em pé, quadradas ou pequenas aparecem inteiras, com fundo branco nas laterais. Mas fotos deitadas e grandes ficam muito mais bonitas.</li>
      <li>Fotos muito pequenas (menos de 800 pixels de largura) ficam borradas em telas grandes.</li>
      <li>Prefira fotos com fundo limpo e a máquina inteira aparecendo.</li>
      <li>Formatos aceitos: JPG, PNG ou WebP, até 10 MB cada.</li>
      <li>Não precisa diminuir as fotos antes: o painel converte sozinho para WebP (formato mais leve), reduz para no máximo 1600 px e acerta fotos de celular que viriam deitadas.</li>
    </ul>
    <h2>Desfazer um erro</h2>
    <p>Toda vez que algo é salvo, o painel guarda uma cópia do conteúdo anterior (as últimas 30). Se algo der errado, peça para o responsável técnico restaurar uma cópia da pasta <code>data/backups</code>. Fotos de máquinas excluídas ficam guardadas em <code>img/machines/_deleted</code>.</p>
    <h2>Esqueci a senha</h2>
    <p>O responsável técnico deve apagar o arquivo <code>admin/private/config.php</code> pelo gerenciador de arquivos da hospedagem. No próximo acesso ao painel será pedida uma nova senha.</p>
  </div>
<?php
}
