<?php
require_once __DIR__ . '/functions.php';

require_login();
require_role('admin');
set_security_headers();
