<?php
ob_start();
session_name('PAY_SESSION'); session_start();
include('connessione.php');

$errore = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = $con->real_escape_string(trim($_POST['nome']));
    $email = $con->real_escape_string(trim($_POST['email']));
    $password = $_POST['password'];
    $tipo  = $_POST['tipo'];
    $piva  = isset($_POST['piva']) ? $con->real_escape_string(trim($_POST['piva'])) : null;

    if (empty($nome) || empty($email) || empty($password) || empty($tipo)) {
        $errore = "Tutti i campi obbligatori devono essere compilati.";
    } elseif ($tipo === 'ESERCENTE' && empty($piva)) {
        $errore = "La Partita IVA è obbligatoria per gli esercenti.";
    } else {
        $check = $con->query("SELECT IDUTENTE FROM PAY_UTENTE WHERE EMAIL = '$email'");
        if ($check->num_rows > 0) {
            $errore = "Email già registrata!";
        } else {
            $pw_hash  = password_hash($password, PASSWORD_BCRYPT);
            $piva_sql = ($tipo === 'ESERCENTE') ? "'$piva'" : "NULL";
            $sql = "INSERT INTO PAY_UTENTE (NOME, EMAIL, PW, TIPO, P_IVA)
                    VALUES ('$nome', '$email', '$pw_hash', '$tipo', $piva_sql)";

            if ($con->query($sql)) {
                $nuovo_id = $con->insert_id;
                $iban = 'IT' . strtoupper(substr(md5(uniqid($email, true)), 0, 22));
                $con->query("INSERT INTO PAY_CONTO (IDUTENTE, IBAN, SALDO)
                             VALUES ($nuovo_id, '$iban', 0.00)");

                //Mantieni redirect e token nell'URL
                $extra = '';
                if (isset($_GET['redirect'])) $extra .= '&redirect=' . $_GET['redirect'];
                if (isset($_GET['token']))    $extra .= '&token=' . urlencode($_GET['token']);

                ob_end_clean();
                header("Location: /gio.fuso/PAY/login.php?registrato=successo" . $extra);
                exit();
            } else {
                $errore = "Errore durante la registrazione: " . $con->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PaySteam – Registrati</title>
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

        .card-registrazione {
            width: 100%;
            max-width: 490px;
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(13,31,60,0.40);
        }

        .logo-header {
            background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 60%, var(--sft-azzurro) 100%);
            border-radius: 20px 20px 0 0;
            padding: 1.6rem 2rem;
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .logo-header::before {
            content: "";
            position: absolute;
            top: -40px; right: -40px;
            width: 140px; height: 140px;
            background: rgba(255,255,255,0.05);
            border-radius: 50%;
        }

        .logo-header::after {
            content: "";
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 3px;
            background: var(--sft-accent);
        }

        .logo-header h1 {
            font-size: 1.8rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            position: relative;
        }

        .logo-header p {
            opacity: 0.80;
            margin: 0;
            font-size: 0.88rem;
            position: relative;
        }

        .card-body {
            padding: 1.75rem 2rem !important;
            background: white;
            border-radius: 0 0 20px 20px;
        }

        .form-label {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--sft-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 4px;
        }

        .form-control {
            border: 1.5px solid var(--sft-bordo);
            border-radius: 0 9px 9px 0 !important;
            font-size: 0.92rem;
            padding: 0.55rem 0.85rem;
            background: #f8fafc;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-control:focus {
            border-color: var(--sft-azzurro);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.14);
            background: white;
        }

        .input-group-text {
            background: #f0f5ff;
            border: 1.5px solid var(--sft-bordo);
            border-right: none;
            border-radius: 9px 0 0 9px !important;
            color: var(--sft-azzurro);
            font-size: 1rem;
        }

        .form-check-input:checked {
            background-color: var(--sft-azzurro);
            border-color: var(--sft-azzurro);
        }

        .btn-registrati {
            background: linear-gradient(90deg, var(--sft-blu) 0%, var(--sft-azzurro) 100%);
            border: none;
            border-radius: 9px;
            color: white;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 0.7rem;
            width: 100%;
            transition: filter 0.18s, transform 0.18s, box-shadow 0.18s;
            box-shadow: 0 4px 14px rgba(37,99,235,0.28);
        }

        .btn-registrati:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(37,99,235,0.38);
        }

        .alert {
            border: none;
            border-radius: 10px;
            border-left: 4px solid;
            font-size: 0.9rem;
        }

        .alert-danger { border-left-color: #dc2626; background: #fef2f2; color: #1e293b; }

        #campo-piva { display: none; }

        a { color: var(--sft-azzurro); }
        a:hover { color: var(--sft-blu); }

        hr { border-color: var(--sft-bordo); }
    </style>
</head>
<body>
<div class="card-registrazione card">
    <div class="logo-header">
        <h1><i class="bi bi-credit-card-2-front me-2"></i>PaySteam</h1>
        <p>Crea il tuo account</p>
    </div>

    <div class="card-body">

        <?php if (!empty($errore)): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($errore) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">

            <!-- TIPO ACCOUNT -->
            <div class="mb-3">
                <label class="form-label">Tipo account</label>
                <div class="d-flex gap-3">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="tipo"
                               id="tipo-consumatore" value="CONSUMATORE" required
                               <?= (!isset($_POST['tipo']) || $_POST['tipo']==='CONSUMATORE') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tipo-consumatore">
                            <i class="bi bi-person"></i> Consumatore
                        </label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="tipo"
                               id="tipo-esercente" value="ESERCENTE"
                               <?= (isset($_POST['tipo']) && $_POST['tipo']==='ESERCENTE') ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tipo-esercente">
                            <i class="bi bi-shop"></i> Esercente
                        </label>
                    </div>
                </div>
            </div>

            <!-- NOME -->
            <div class="mb-3">
                <label for="nome" class="form-label">Nome e Cognome</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input type="text" class="form-control" id="nome" name="nome"
                           placeholder="Mario Rossi" required
                           value="<?= isset($_POST['nome']) ? htmlspecialchars($_POST['nome']) : '' ?>">
                </div>
            </div>

            <!-- EMAIL -->
            <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email"
                           placeholder="nome@esempio.it" required
                           value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                </div>
            </div>

            <!-- PASSWORD -->
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password"
                           name="password" placeholder="Minimo 6 caratteri"
                           required minlength="6">
                </div>
            </div>

            <!-- P.IVA (solo esercente) -->
            <div class="mb-3" id="campo-piva">
                <label for="piva" class="form-label">
                    Partita IVA <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-building"></i></span>
                    <input type="text" class="form-control" id="piva" name="piva"
                           placeholder="12345678901" maxlength="11"
                           value="<?= isset($_POST['piva']) ? htmlspecialchars($_POST['piva']) : '' ?>">
                </div>
                <div class="form-text text-muted" style="font-size:0.8rem;">11 cifre, senza spazi.</div>
            </div>

            <button type="submit" class="btn-registrati mt-2">
                <i class="bi bi-person-plus me-1"></i> Crea account
            </button>
        </form>

        <hr class="my-3">
        <p class="text-center text-muted mb-0" style="font-size:0.9rem;">
            Hai già un account?
            <a href="login.php" class="fw-semibold text-decoration-none">Accedi</a>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const radios    = document.querySelectorAll('input[name="tipo"]');
const campoPiva = document.getElementById('campo-piva');
const inputPiva = document.getElementById('piva');

function togglePiva() {
    const tipo = document.querySelector('input[name="tipo"]:checked').value;
    if (tipo === 'ESERCENTE') {
        campoPiva.style.display = 'block';
        inputPiva.required = true;
    } else {
        campoPiva.style.display = 'none';
        inputPiva.required = false;
        inputPiva.value = '';
    }
}
radios.forEach(r => r.addEventListener('change', togglePiva));
togglePiva();
</script>
</body>
</html>
