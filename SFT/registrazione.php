<?php
include('connessione.php');

error_reporting(E_ALL);
ini_set('display_errors', '1');

$errore = "";

if (isset($_POST['salva'])) {

    $nome    = $con->real_escape_string($_POST['nome']);
    $cognome = $con->real_escape_string($_POST['cognome']);
    $eta     = intval($_POST['eta']);
    $email   = $con->real_escape_string($_POST['email']);
    $user    = $con->real_escape_string($_POST['user']);
    $citta    = $con->real_escape_string($_POST['citta']);
    $via      = $con->real_escape_string($_POST['via']);
    $password_chiaro = $_POST['password'];
    $password_criptata = password_hash($password_chiaro, PASSWORD_BCRYPT);

    if ($eta < 0 || $eta > 120) {
        $errore = "L'età deve essere compresa tra 0 e 120 anni.";
    } else {    
        
        $checkEmail = "SELECT * FROM SFT_UTENTE WHERE EMAIL = '$email' OR USER = '$user'";
        $ris_check = $con->query($checkEmail);

        if ($ris_check->num_rows > 0) {
            $errore = "Errore: Email o Username già esistenti!";
        } else {

            $sql = "INSERT INTO SFT_UTENTE (NOME, COGNOME, ETA, EMAIL, PW, USER, CITTA, VIA, TIPO) 
            VALUES ('$nome', '$cognome', $eta, '$email', '$password_criptata', '$user', '$citta', '$via', 'REGISTRATO')";
            
            if($con->query($sql)) {
                // Reindirizzamento in caso di successo
                header("Location: login.php?registrato=successo");
                exit();
            } else {
                $errore = "Errore tecnico durante l'inserimento: " . $con->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Registrazione Utente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="stile.css">
    <style>
        body { background: #f4f7f6; }
        .reg-card { max-width: 500px; margin: 50px auto; border-radius: 15px; }
        .btn-sft { background: #004a99; color: white; border: none; }
        .btn-sft:hover { background: #003366; color: white; }
        .header-sft { background: #004a99; color: white; padding: 20px; border-radius: 15px 15px 0 0; }
    </style>
</head>
<body>

<div class="container">
    <div class="card shadow reg-card">
        <div class="header-sft text-center">
            <h3 class="mb-0 fw-bold">Registrazione Utente</h3>
        </div>
        
        <div class="card-body p-4">
            <?php if(!empty($errore)) echo "<div class='alert alert-danger'>$errore</div>"; ?>

            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Nome</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Cognome</label>
                        <input type="text" name="cognome" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold text-secondary">Età</label>
                        <input type="number" name="eta" class="form-control" min="0" max="120" placeholder="es. 25" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small fw-bold">Username</label>
                        <input type="text" name="user" class="form-control" placeholder="Scegli un nome utente" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-bold">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Città</label>
                        <input type="text" name="citta" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Via/Piazza</label>
                        <input type="text" name="via" class="form-control" required>
                    </div>
                </div>

                <button type="submit" name="salva" class="btn btn-sft w-100 mt-4 py-2">CREA ACCOUNT</button>
                <div class="text-center mt-3">
                <a href="index.php" class="small">Torna alla Home</a>
            </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>