<?php
declare(strict_types=1);

// The attendance table is shown in the "Tableau de présence" tab of "Gérer les dates".
// This address receives its saves (and those of the Présences tab of a date) and
// sends earlier links and bookmarks to the tab.
require __DIR__ . '/lib/bootstrap.php';
$manager = app_require_roles(EVENT_MANAGER_ROLES);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The Présences tab of a date comes back to that date.
    $returnEvent = $_POST['return_event'] ?? '';
    $redirectUrl = is_string($returnEvent) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $returnEvent) === 1
        ? 'event.php?id=' . rawurlencode($returnEvent) . '&tab=presences'
        : app_matrix_tab_url(($_POST['periode'] ?? '') === 'toutes');
    app_matrix_save($manager, $redirectUrl);
}

app_redirect(app_matrix_tab_url(($_GET['periode'] ?? '') === 'toutes'));
