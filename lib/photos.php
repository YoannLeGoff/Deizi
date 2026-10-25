<?php
declare(strict_types=1);

// Profile photos and the "Trombinoscope". A photo is turned upright (orientation of phone
// pictures) and saved again as JPEG, which also drops its metadata (place of the shot…), in two
// versions: a square (the thumbnail) and a portrait (3:4), both cut from the top of the picture,
// where the face usually is, and centred across. Photos are stored in data/uploads ("photo-….jpg"
// and "photo-…-portrait.jpg"), named on the account ("photo" in users.json, the square), and
// served to logged-in members only (photo.php). Artists change their own photo, managers theirs and
// those of their scope, administrators every photo; every change goes to the audit journal.

const PHOTO_SIZE = 400;
const PHOTO_PORTRAIT_WIDTH = 600;
const PHOTO_PORTRAIT_HEIGHT = 800;
const PHOTO_MAX_BYTES = 8 * 1024 * 1024;
const PHOTO_EDITOR_ROLES = ['musician', 'manager', 'admin'];

function app_photo_path(string $name): ?string
{
    return preg_match('/^photo-[0-9a-f]{32}\.jpg$/', $name) === 1 ? app_upload_directory() . '/' . $name : null;
}

// Portrait version of a stored photo (photos saved before it existed have none).
function app_photo_portrait_path(string $name): ?string
{
    $path = app_photo_path($name);
    $portrait = $path !== null ? substr($path, 0, -4) . '-portrait.jpg' : null;
    return $portrait !== null && is_file($portrait) ? $portrait : null;
}

// Copies the part of $source of ratio $targetWidth:$targetHeight taken from the top of the picture
// (centred across) into a new image of that size.
function app_photo_crop_top(GdImage $source, int $targetWidth, int $targetHeight): GdImage
{
    $width = imagesx($source);
    $height = imagesy($source);
    $cropWidth = $width;
    $cropHeight = (int) round($width * $targetHeight / $targetWidth);
    if ($cropHeight > $height) {
        $cropHeight = $height;
        $cropWidth = (int) round($height * $targetWidth / $targetHeight);
    }
    $image = imagecreatetruecolor($targetWidth, $targetHeight);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagecopyresampled($image, $source, 0, 0, intdiv($width - $cropWidth, 2), 0, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
    return $image;
}

// EXIF orientation of a JPEG file (1 = upright), read with the exif extension when the server has
// it, otherwise from the file itself (many hosts do not install that extension).
function app_jpeg_orientation(string $path): int
{
    if (function_exists('exif_read_data')) {
        return (int) (@exif_read_data($path)['Orientation'] ?? 1);
    }
    $data = (string) @file_get_contents($path, false, null, 0, 131072);
    $offset = 2;
    while ($offset + 4 <= strlen($data) && $data[$offset] === "\xFF") {
        $marker = ord($data[$offset + 1]);
        $length = unpack('n', substr($data, $offset + 2, 2))[1];
        if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\0\0") {
            $tiff = substr($data, $offset + 10, $length - 8);
            $little = substr($tiff, 0, 2) === 'II';
            $short = static fn (int $at): int => unpack($little ? 'v' : 'n', substr($tiff, $at, 2))[1] ?? 0;
            $long = static fn (int $at): int => unpack($little ? 'V' : 'N', substr($tiff, $at, 4))[1] ?? 0;
            if (strlen($tiff) < 8) {
                return 1;
            }
            $ifd = $long(4);
            $count = $ifd + 2 <= strlen($tiff) ? $short($ifd) : 0;
            for ($entry = 0; $entry < $count; $entry++) {
                $at = $ifd + 2 + $entry * 12;
                if ($at + 12 > strlen($tiff)) {
                    break;
                }
                if ($short($at) === 0x0112) {
                    return $short($at + 8);
                }
            }
            return 1;
        }
        if ($marker === 0xDA) {
            break;
        }
        $offset += 2 + $length;
    }
    return 1;
}

// Stored photo of $user, or null.
function app_user_photo(array $user): ?string
{
    $name = $user['photo'] ?? null;
    $path = is_string($name) ? app_photo_path($name) : null;
    return $path !== null && is_file($path) ? $name : null;
}

// Whether $actor may change the photo of $target: administrators every photo, managers those of
// their own and of the artists and managers of their scope, artists their own.
function app_can_edit_photo(array $actor, array $target): bool
{
    $role = $actor['role'] ?? '';
    if (!in_array($role, PHOTO_EDITOR_ROLES, true)) {
        return false;
    }
    return $role === 'admin'
        || (string) ($actor['id'] ?? '') === (string) ($target['id'] ?? '')
        || app_manager_scope_includes($actor, $target);
}

// Stores an uploaded photo as a square and a portrait JPEG. Returns the file name of the square,
// null when no file was sent, or throws InvalidArgumentException with a message for the member.
function app_store_photo(mixed $file): ?string
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file['size'] ?? 0) > PHOTO_MAX_BYTES) {
        throw new InvalidArgumentException('La photo ne doit pas dépasser 8' . "\u{00A0}" . 'Mo.');
    }
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('L’envoi de la photo a échoué.');
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        throw new InvalidArgumentException('La photo doit être une image JPEG ou PNG.');
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        throw new InvalidArgumentException('Le serveur ne sait pas traiter les images (extension PHP GD absente) : demandez à l’hébergeur de l’activer.');
    }
    $source = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if ($source === false) {
        throw new InvalidArgumentException('Cette image ne peut pas être lue' . "\u{00A0}" . ': enregistrez-la en JPEG ou en PNG.');
    }
    // Phone pictures: turned as they were taken.
    if ($info[2] === IMAGETYPE_JPEG) {
        $orientation = app_jpeg_orientation($file['tmp_name']);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $rotated = imagerotate($source, $angle, 0);
            if ($rotated !== false) {
                $source = $rotated;
            }
        }
    }
    $directory = app_upload_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossible de créer le dossier des photos.');
    }
    $base = 'photo-' . bin2hex(random_bytes(16));
    // Square from the top of the picture (thumbnail), and portrait 3:4 (enlarged view).
    foreach ([[$base . '.jpg', PHOTO_SIZE, PHOTO_SIZE], [$base . '-portrait.jpg', PHOTO_PORTRAIT_WIDTH, PHOTO_PORTRAIT_HEIGHT]] as [$fileName, $targetWidth, $targetHeight]) {
        $path = $directory . '/' . $fileName;
        if (!imagejpeg(app_photo_crop_top($source, $targetWidth, $targetHeight), $path, 85) || !chmod($path, 0600)) {
            throw new RuntimeException('Impossible d’enregistrer la photo.');
        }
    }
    return $base . '.jpg';
}

function app_delete_photo(?string $name): void
{
    $path = $name !== null ? app_photo_path($name) : null;
    $portrait = $name !== null ? app_photo_portrait_path($name) : null;
    foreach ([$path, $portrait] as $file) {
        if ($file !== null && is_file($file)) {
            unlink($file);
        }
    }
}

// Address of a photo: the square, or with $portrait the portrait version (the square when none).
function app_photo_url(array $user, bool $portrait = false): ?string
{
    $photo = app_user_photo($user);
    if ($photo === null) {
        return null;
    }
    return 'photo.php?' . http_build_query(['id' => (string) $user['id'], 'v' => substr($photo, 6, 8)] + ($portrait ? ['format' => 'portrait'] : []));
}

// Name shown in the Trombinoscope: first name and last name when known, otherwise the login.
function app_member_display_name(array $user): string
{
    $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
    return $name !== '' ? $name : (string) ($user['username'] ?? '');
}

// Thumbnail opening the portrait version enlarged (menu.js), or the initials when there is no photo.
function app_photo_zoom(array $user, string $class = '', int $size = 96): string
{
    $url = app_photo_url($user, true);
    if ($url === null) {
        return app_photo_thumb($user, $class, $size);
    }
    $name = app_member_display_name($user);
    return '<a class="photo-zoom" href="' . app_escape($url) . '" data-photo-zoom data-photo-name="' . app_escape($name) . '" aria-label="' . app_escape('Agrandir la photo de ' . $name) . '">' . app_photo_thumb($user, $class, $size) . '</a>';
}

// Square thumbnail with rounded corners: the photo, or the initials of the member. $size (px) is
// also written on the image itself, so that it stays a small rounded square even when the page
// is shown with an outdated or missing stylesheet (styles.css sets the final size).
function app_photo_thumb(array $user, string $class = '', int $size = 96): string
{
    $photo = app_user_photo($user);
    $name = app_member_display_name($user);
    $classes = trim('member-photo ' . $class);
    if ($photo !== null) {
        $url = app_photo_url($user);
        return '<img class="' . app_escape($classes) . '" src="' . app_escape($url) . '" alt="' . app_escape('Photo de ' . $name) . '" width="' . $size . '" height="' . $size . '" style="object-fit:cover;border-radius:18%" loading="lazy">';
    }
    $words = preg_split('/[\s._-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($word, 0, 1)) : strtoupper(substr($word, 0, 1));
    }
    return '<span class="' . app_escape($classes . ' is-initials') . '" role="img" aria-label="' . app_escape('Pas de photo pour ' . $name) . '">' . app_escape($initials) . '</span>';
}

// Photo block of an account: the thumbnail, and for whoever may change it the forms to send a new
// photo or remove it ($return: page to come back to).
function app_photo_panel(array $target, array $actor, string $return, string $heading): void
{
    $canEdit = app_can_edit_photo($actor, $target) && app_impersonator() === null;
    $hasPhoto = app_user_photo($target) !== null;
    ?>
        <section class="management-panel account-self photo-panel" id="photo" aria-labelledby="photo-title">
          <h2 class="detail-heading" id="photo-title"><?= app_escape($heading) ?></h2>
          <div class="photo-panel-layout">
            <?php if ($hasPhoto): ?>
            <figure class="photo-panel-versions">
              <?= app_photo_thumb($target, 'photo-panel-thumb', 128) ?>
              <img class="photo-panel-portrait" src="<?= app_escape((string) app_photo_url($target, true)) ?>" alt="<?= app_escape('Portrait de ' . app_member_display_name($target)) ?>" width="120" height="160" style="object-fit:cover;border-radius:10px" loading="lazy">
              <figcaption class="field-help">Vignette et portrait</figcaption>
            </figure>
            <?php else: ?>
            <?= app_photo_thumb($target, 'photo-panel-thumb', 128) ?>
            <?php endif; ?>
            <div class="photo-panel-forms">
              <?php if ($canEdit): ?>
              <form method="post" action="photo-action.php" enctype="multipart/form-data" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="user_id" value="<?= app_escape((string) $target['id']) ?>">
                <input type="hidden" name="return" value="<?= app_escape($return) ?>">
                <label for="photo-file"><?= $hasPhoto ? 'Nouvelle photo' : 'Photo' ?> (JPEG ou PNG, 8<?= "\u{00A0}" ?>Mo au plus)</label>
                <input id="photo-file" name="photo" type="file" accept="image/jpeg,image/png" required>
                <p class="field-help">Elle est recadrée par le haut, en carré pour la vignette et en portrait pour l’agrandissement (cadrez le visage en haut de l’image), et réduite ; ses informations cachées (lieu de la prise de vue…) sont retirées. Elle apparaît dans le trombinoscope, visible des seuls membres connectés. Chaque changement est noté dans le journal d’audit.</p>
                <div class="form-actions"><button class="primary-button compact-button" type="submit">Enregistrer la photo</button></div>
              </form>
              <?php if ($hasPhoto): ?>
              <form method="post" action="photo-action.php" onsubmit="return confirm('Retirer cette photo<?= "\u{00A0}" ?>?');">
                <input type="hidden" name="csrf_token" value="<?= app_escape(app_csrf_token()) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="user_id" value="<?= app_escape((string) $target['id']) ?>">
                <input type="hidden" name="return" value="<?= app_escape($return) ?>">
                <button class="text-button" type="submit">Retirer la photo</button>
              </form>
              <?php endif; ?>
              <?php else: ?>
              <p class="muted-copy"><?= app_impersonator() !== null ? 'La photo ne se change pas en agissant en tant que ce membre.' : 'Seuls le membre (artiste ou manager), les managers de ses sous-sections et les administrateurs peuvent modifier cette photo.' ?></p>
              <?php endif; ?>
            </div>
          </div>
        </section>
<?php
}
