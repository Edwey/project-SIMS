<?php
require_once __DIR__ . '/includes/functions.php';

logout_user();
set_flash_message('success', 'You have been logged out.');
redirect('/login.php');
