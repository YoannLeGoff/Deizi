<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';

$usersPath = app_data_path('users.json');
$password = bin2hex(random_bytes(12));
$created = false;
update_json_file($usersPath, static function (array $users) use ($password, &$created): array {
    if ($users !== []) {
        return $users;
    }
    $created = true;
    $users[] = [
        'id' => bin2hex(random_bytes(16)),
        'username' => 'admin',
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'admin',
        'active' => true,
        'sections' => array_keys(app_section_tree()),
        'default_view' => DEFAULT_VIEW_ALL,
    ];
    return $users;
});
if (!$created) {
    fwrite(STDERR, "Des comptes existent déjà. Le compte initial n'a pas été créé.\n");
    exit(1);
}

fwrite(STDOUT, "Compte administrateur initial créé.\n");
fwrite(STDOUT, "Identifiant : admin\n");
fwrite(STDOUT, "Mot de passe temporaire : {$password}\n");
fwrite(STDOUT, "Conservez ce mot de passe et changez-le depuis Gestion des comptes après connexion.\n");
