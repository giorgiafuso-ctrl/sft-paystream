<?php
session_name('SFT_SESSION'); session_start();    
session_unset();    
session_destroy();  

header("Location: index.php");
exit();
?>
