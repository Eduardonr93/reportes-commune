<?php
session_start();
session_destroy();
header('Location: /reportes/login.php');
exit;
