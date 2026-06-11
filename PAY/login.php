<?php
ob_start();

file_put_contents('/tmp/pay_log.txt', 
    date('H:i:s') . " [3] login.php - method=" . $_SERVER['REQUEST_METHOD'] . "\n", 
    FILE_APPEND);

session_name('PAY_SESSION'); 
session_start();
include ('connessione.php');

$errore = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $con->real_escape_string($_POST['email']);
    $password_inserita = $_POST['password'];

    $query = "SELECT * FROM PAY_UTENTE WHERE EMAIL = '$email'";
    $risultato = $con->query($query);

    file_put_contents('/tmp/pay_log.txt', 
        date('H:i:s') . " [3b] login - email=$email trovato=" . ($risultato ? $risultato->num_rows : 'ERRORE_QUERY') . "\n", 
        FILE_APPEND);

    if ($risultato && $risultato->num_rows > 0) {
        $utente = $risultato->fetch_assoc();
        
        $pw_ok = password_verify($password_inserita, $utente['PW']);
        
        file_put_contents('/tmp/pay_log.txt', 
            date('H:i:s') . " [3c] login - pw_verify=" . ($pw_ok ? 'OK' : 'FAIL') . 
            " hash_inizio=" . substr($utente['PW'], 0, 10) . 
            " tipo=" . $utente['TIPO'] . "\n", 
            FILE_APPEND);

        if ($pw_ok) {
            $_SESSION['pay_id']   = $utente['IDUTENTE'];
            $_SESSION['pay_nome'] = $utente['NOME'];
            $_SESSION['pay_tipo'] = $utente['TIPO'];

            $token_in  = $_POST['token']    ?? $_GET['token']    ?? '';
            $redirect  = $_POST['redirect'] ?? $_GET['redirect'] ?? '';

            if (!isset($_SESSION['pay_pending']) && !empty($token_in)) {
                $decoded = json_decode(base64_decode($token_in), true);
                if ($decoded) {
                    $_SESSION['pay_pending'] = $decoded;
                }
            }

            //Redirect
            if ($redirect === 'pay' && isset($_SESSION['pay_pending'])) {
                $token_out = base64_encode(json_encode($_SESSION['pay_pending']));
                $dest = "/gio.fuso/PAY/conferma.php?token=" . urlencode($token_out);
            } elseif ($utente['TIPO'] === 'ESERCENTE') {
                $dest = "/gio.fuso/PAY/esercente.php";
            } else {
                $dest = "/gio.fuso/PAY/dashboard.php";
            }
            
            file_put_contents('/tmp/pay_log.txt', 
                date('H:i:s') . " [3d] login OK - redirect=$redirect dest=$dest session_id=" . session_id() . "\n", 
                FILE_APPEND);
            
            header("Location: $dest");
            exit();
        } else {
            $errore = "Password errata!";
        }
    } else {
        $errore = "Email non trovata!";
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PaySteam – Accedi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sft-blu:       #1a3a6b;
            --sft-blu-scuro: #0d1f3c;
            --sft-azzurro:   #2563eb;
            --sft-accent:    #d4066d;
            --sft-bordo:     #cbd5e1;
            --sft-muted:     #64748b;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, #1e3a8a 60%, var(--sft-azzurro) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .card-login { width: 100%; max-width: 420px; border: none; border-radius: 20px; box-shadow: 0 20px 60px rgba(13,31,60,0.40); }
        .logo-header { background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 60%, var(--sft-azzurro) 100%); border-radius: 20px 20px 0 0; padding: 2rem; text-align: center; color: white; position: relative; overflow: hidden; }
        .logo-header::after { content: ""; position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: var(--sft-accent); }
        .logo-header h1 { font-size: 2rem; font-weight: 800; letter-spacing: -0.03em; position: relative; }
        .logo-header p { opacity: 0.85; margin: 0; font-size: 0.9rem; position: relative; }
        .card-body { padding: 2rem 2rem 1.75rem !important; background: white; border-radius: 0 0 20px 20px; }
        .form-label { font-size: 0.80rem; font-weight: 700; color: var(--sft-muted); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 5px; }
        .form-control { border: 1.5px solid var(--sft-bordo); border-radius: 0 9px 9px 0 !important; font-size: 0.93rem; padding: 0.58rem 0.9rem; background: #f8fafc; }
        .form-control:focus { border-color: var(--sft-azzurro); box-shadow: 0 0 0 3px rgba(37,99,235,0.14); background: white; }
        .input-group-text { background: #f0f5ff; border: 1.5px solid var(--sft-bordo); border-right: none; border-radius: 9px 0 0 9px !important; color: var(--sft-azzurro); font-size: 1rem; }
        .btn-accedi { background: linear-gradient(90deg, var(--sft-blu) 0%, var(--sft-azzurro) 100%); border: none; border-radius: 9px; color: white; font-weight: 700; font-size: 0.95rem; padding: 0.7rem; width: 100%; box-shadow: 0 4px 14px rgba(37,99,235,0.28); }
        .btn-accedi:hover { filter: brightness(1.08); transform: translateY(-1px); }
        .alert { border: none; border-radius: 10px; border-left: 4px solid; font-size: 0.9rem; }
        .alert-danger { border-left-color: #dc2626; background: #fef2f2; color: #1e293b; }
        .alert-success { border-left-color: #16a34a; background: #f0fdf4; color: #1e293b; }
        a { color: var(--sft-azzurro); }
        hr { border-color: var(--sft-bordo); }
    </style>
</head>
<body>
<div class="card-login card">
    <div class="logo-header">
        <h1><i class="bi bi-credit-card-2-front me-2"></i>PaySteam</h1>
        <p>Il tuo portafoglio digitale</p>
    </div>

    <div class="card-body">

        <?php if (!empty($errore)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($errore) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['registrato']) && $_GET['registrato'] === 'successo'): ?>
            <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                Registrazione completata! Ora accedi.
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'] ?? '') ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($_GET['token'] ?? '') ?>">

            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email"
                           placeholder="nome@esempio.it" required
                           value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password"
                           name="password" placeholder="••••••••" required>
                </div>
            </div>

            <button type="submit" class="btn-accedi">
                <i class="bi bi-box-arrow-in-right me-1"></i> Accedi
            </button>
        </form>

        <hr class="my-3">
        <p class="text-center text-muted mb-0" style="font-size:0.9rem;">
            Non hai un account?
            <a href="/gio.fuso/PAY/registrazione.php<?= 
                isset($_GET['redirect']) 
                    ? '?redirect='.$_GET['redirect'].(isset($_GET['token']) ? '&token='.urlencode($_GET['token']) : '')
                    : '' 
            ?>" class="fw-semibold text-decoration-none">Registrati</a>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
