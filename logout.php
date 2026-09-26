<?php
session_start();
// Wipe all session variables
$_SESSION = array();
// Destroy the physical session file on the server
session_destroy();
// Kick them back to the login page
header("Location: login.php");
exit();
?>