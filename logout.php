<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

start_panel_session();
if (is_post()) {
    verify_csrf();
    logout_admin();
}
redirect('login.php');
