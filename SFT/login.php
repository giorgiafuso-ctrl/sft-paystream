<?php
session_name('SFT_SESSION'); session_start(); 
include('connessione.php');

error_reporting(E_ALL);
ini_set('display_errors', '1');

$errore = ""; 

if (isset($_POST['entra'])) {

    $user_inserito = $con->real_escape_string($_POST['user']);
    $password_inserita = $_POST['password'];

    
    $query = "SELECT * FROM SFT_UTENTE WHERE USER = '$user_inserito'";
    $risultato = $con->query($query);

    if ($risultato && $risultato->num_rows > 0) {
        $utente = $risultato->fetch_assoc();


        if (password_verify($password_inserita, $utente['PW'])) {

            session_regenerate_id(true);

            $_SESSION['sft_id'] = $utente['CODUTENTE'];
            $_SESSION['sft_nome'] = $utente['NOME'];
            $_SESSION['sft_ruolo'] = $utente['TIPO'];

            header("Location: index.php");
            exit();

            } else {
                    $errore = "Password errata!";
                }
            } else {
                $errore = "Utente non trovato!";
            }
        }

?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
 rel="stylesheet">
 <link rel="stylesheet" href="stile.css">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card mx-auto shadow" style="max-width: 400px;">
        <div class="card-body">
            <h3 class="text-center mb-4">Accedi</h3>
            
            <?php if(!empty($errore)) echo "<div class='alert alert-danger'>$errore</div>"; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="user" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>
                <button type="submit" name="entra" class="btn btn-primary w-100">ACCEDI</button>
            </form>
            <div class="text-center mt-3">
                <a href="registrazione.php" class="small">Non hai un account? Registrati</a>
            </div>
             <div class="text-center mt-3">
                <a href="index.php" class="small">Torna alla Home</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>


