<?php
declare(strict_types=1);

// First administrator account of a new installation (command line only):
//   php setup-admin.php [language]
// The optional language ("en", "br", "es"…) becomes the default language of the site; without it,
// the site takes the language of the administrator's browser at their first login.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';

$language = $argv[1] ?? null;
if ($language !== null && !app_is_language($language)) {
    fwrite(STDERR, __('setup_langue_inconnue', ['code' => $language, 'codes' => implode(', ', array_keys(app_languages()))]) . "\n");
    exit(1);
}
app_locale($language ?? DEFAULT_LANGUAGE);

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
    fwrite(STDERR, __('setup_comptes_existent_deja') . "\n");
    exit(1);
}
if ($language !== null) {
    update_json_file(app_data_path('settings.json'), static function (array $settings) use ($language): array {
        $settings['site'] = is_array($settings['site'] ?? null) ? $settings['site'] : [];
        $settings['site']['language'] = $language;
        return $settings;
    });
}

fwrite(STDOUT, __('setup_compte_cree') . "\n");
fwrite(STDOUT, __('setup_identifiant', ['login' => 'admin']) . "\n");
fwrite(STDOUT, __('setup_mot_passe', ['password' => $password]) . "\n");
fwrite(STDOUT, __('setup_conservez_mot_passe') . "\n");
fwrite(STDOUT, ($language !== null ? __('setup_langue_site', ['language' => app_languages()[$language]]) : __('setup_langue_navigateur')) . "\n");
