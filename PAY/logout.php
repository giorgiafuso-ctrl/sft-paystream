<?php
session_name('PAY_SESSION'); session_start();    
session_unset();    
session_destroy();  

header("Location: index.php");
exit();
?>
