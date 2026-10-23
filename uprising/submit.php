<?php

/**
 * Dominion Festival 3.0 | ARISE - registration handler
 * Receives the FormData POST from register.html and saves it to the database.
 * Responds with JSON: { ok: true, reference: "DF3-XXXXXXXX" } or { ok: false, message: "..." }
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// CONFIG - edit these (ideally load from env vars or a file outside web root)
// ---------------------------------------------------------------------------
// Shared config (same file the admin area uses): returns ['dsn', 'db_user', 'db_password', ...]
$configPath = is_file(__DIR__ . '/config.php') ? __DIR__ . '/config.php' : dirname(__DIR__) . '/config.php';
$config = is_file($configPath) ? require $configPath : null;

// Optional: add 'registrations_table' => 'your_table' to config.php.
// Falls back to this name if the key is not set.
define('DB_TABLE', is_array($config) && !empty($config['registrations_table'])
    ? (string) $config['registrations_table']
    : 'festival_registrations');   // <-- change to your real table name

const PRIVACY_NOTICE_VERSION = '2026-10-v1';
const MIXED_AGE_RULES_VERSION = 'v1';
const AGE_CUTOFF = '2026-12-04';             // age is calculated as of this date
const LOG_FILE = __DIR__ . '/submit-errors.log';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 422): void
{
    respond($status, ['ok' => false, 'message' => $message]);
}

function logError(string $msg): void
{
    @file_put_contents(LOG_FILE, '[' . date('c') . '] ' . $msg . PHP_EOL, FILE_APPEND);
}

function post(string $key): string
{
    $v = $_POST[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

function checked(string $key): bool
{
    return !empty($_POST[$key]);
}

function len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

/** Return the allowed values of an ENUM column. */
function enumValues(PDO $pdo, string $column): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([DB_TABLE, $column]);
    $type = (string) $stmt->fetchColumn();
    if (!preg_match('/^enum\((.*)\)$/i', $type, $m)) {
        return [];
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $vals);
    return array_map(fn($v) => str_replace("''", "'", $v), $vals[1]);
}

/** Find the enum value containing a keyword (case-insensitive). */
function enumMatch(array $values, string $keyword): ?string
{
    foreach ($values as $v) {
        if (stripos($v, $keyword) !== false) {
            return $v;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Request checks
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed.', 405);
}

// Honeypot (add <input name="website" style="display:none" tabindex="-1" autocomplete="off"> to the form if you want it)
if (post('website') !== '') {
    respond(200, ['ok' => true, 'reference' => 'DF3-00000000']);
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------
$errors = [];

$fullName      = post('fullName');
$birthDate     = post('birthDate');
$email         = post('email');
$phone         = post('phone');
$contactMethod = post('contactMethod');
$country       = post('country');
$region        = post('region');
$city          = post('city');
$discipline    = post('discipline');
$perfType      = post('performanceType');
$entryType     = post('entryType') ?: 'Individual';
$title         = post('title');
$duration      = post('duration');
$language      = post('language');
$originality   = post('originality');
$description   = post('description');
$finale        = post('finale');
$videoLink     = post('videoLink');

foreach (
    [
        'full name' => $fullName,
        'date of birth' => $birthDate,
        'email' => $email,
        'phone' => $phone,
        'contact method' => $contactMethod,
        'country' => $country,
        'state/region' => $region,
        'city' => $city,
        'category' => $discipline,
        'performance type' => $perfType,
        'act title' => $title,
        'duration' => $duration,
        'language' => $language,
        'originality' => $originality,
        'description' => $description,
        'finale option' => $finale,
        'video link' => $videoLink,
    ] as $label => $value
) {
    if ($value === '') {
        $errors[] = "Please provide your $label.";
    }
}

if ($errors) {
    fail($errors[0]);
}

// Lengths (match column sizes)
if (len($fullName) > 160)    fail('Name is too long.');
if (len($email) > 254)       fail('Email is too long.');
if (len($phone) > 40)        fail('Phone number is too long.');
if (len($country) > 100 || len($region) > 120 || len($city) > 120) fail('A location field is too long.');
if (len($title) > 180)       fail('Act title is too long.');
if (len($duration) > 60)     fail('Duration text is too long.');
if (len($language) > 100)    fail('Language text is too long.');
if (len($description) > 1200) fail('Description must be 1,200 characters or fewer.');
if (len($videoLink) > 2048)  fail('Video link is too long.');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Enter a valid email address.');
}

// Age as of the festival finale
$dob = DateTime::createFromFormat('!Y-m-d', $birthDate);
if (!$dob || $dob->format('Y-m-d') !== $birthDate || $dob > new DateTime('today')) {
    fail('Enter a valid date of birth.');
}
$age = (int) $dob->diff(new DateTime(AGE_CUTOFF))->y;
if ($age < 6) {
    fail('Applicants must be at least 6 years old on 4 December 2026.');
}
if ($age > 120) {
    fail('Enter a valid date of birth.');
}
$ageGroup = $age <= 12 ? 'Kids (6-12)' : ($age <= 17 ? 'Teens (13-17)' : 'Open (18+)');
$isMinor  = $age < 18;

// Whitelists
$contactAllowed = ['Email', 'Phone call', 'WhatsApp / messaging'];
if (!in_array($contactMethod, $contactAllowed, true)) fail('Select a valid contact method.');

$performanceTypes = [
    'Music' => ['Singing', 'Instrumental performance', 'Approved musical act'],
    'Dance' => ['Solo dance performance', 'Group dance performance'],
    'Public Speaking & Spoken Word' => ['Poetry', 'Storytelling', 'Oratory', 'Spoken performance'],
    'Drama & Creative Skits' => ['Creative skit', 'Original skit', 'Comedy', 'Theatrical performance'],
];
if (!isset($performanceTypes[$discipline])) fail('Select a valid category.');
if (!in_array($perfType, $performanceTypes[$discipline], true)) fail('Select a valid performance type.');

if (!in_array($originality, ['Yes', 'No', 'Partly'], true)) fail('Select a valid originality answer.');
if (!in_array($entryType, ['Individual', 'Group'], true)) fail('Select a valid participation type.');

// YouTube link (same pattern as the front-end)
$ytPattern = '~^https?://(www\.|m\.)?(youtube\.com/(watch\?(.*&)?v=|shorts/|live/|embed/)|youtu\.be/)[\w-]{6,}~i';
if (!preg_match($ytPattern, $videoLink)) {
    fail('Enter a valid YouTube link.');
}

// Group fields
$groupName = null;
$groupSize = null;
$members = '';
$mixedAge = 0;
$mixedConfirmed = 0;
$mixedVersion = null;

if ($entryType === 'Group') {
    $groupName = post('groupName');
    $groupSize = (int) post('memberCount');
    $members   = post('members');
    $mixedAnswer = post('mixedAge');

    if ($groupName === '' || len($groupName) > 160) fail('Enter the group name.');
    if ($groupSize < 2 || $groupSize > 100)         fail('A group needs between 2 and 100 performers.');
    if ($members === '')                            fail('List the group members and their ages.');
    if (!in_array($mixedAnswer, ['Yes', 'No'], true)) fail('Please say whether the group includes more than one age group.');

    if ($mixedAnswer === 'Yes') {
        $mixedAge = 1;
        if (post('mixedRules') === '') fail('Please complete the mixed-age confirmation.');
        $mixedConfirmed = 1;
        $mixedVersion = MIXED_AGE_RULES_VERSION;
    }
}

// Checkboxes
if (!checked('videoLength'))     fail('Please confirm your video details.');
if (!checked('videoPermission')) fail('Video permission is required.');
if (!checked('accuracy'))        fail('Please confirm the information is accurate and that you have read the rules and scoring.');
if (!checked('privacy'))         fail('Please agree to the terms and privacy notice.');

// Guardian (minors)
$guardian = ['name' => '', 'relationship' => '', 'email' => '', 'phone' => ''];
if ($isMinor) {
    $guardian['name']         = post('guardianName');
    $guardian['relationship'] = post('guardianRelationship');
    $guardian['email']        = post('guardianEmail');
    $guardian['phone']        = post('guardianPhone');

    if ($guardian['name'] === '')  fail("Enter the guardian's name.");
    if (!in_array($guardian['relationship'], ['Parent', 'Legal guardian'], true)) fail('Select the guardian relationship.');
    if (!filter_var($guardian['email'], FILTER_VALIDATE_EMAIL)) fail("Enter a valid guardian email.");
    if ($guardian['phone'] === '') fail("Enter the guardian's phone number.");
    if (!checked('guardianConsent')) fail('Parent or guardian consent is required for applicants under 18.');
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
try {
    if (!is_array($config) || empty($config['dsn'])) {
        throw new RuntimeException('config.php not found or missing "dsn".');
    }
    $pdo = new PDO(
        $config['dsn'],
        $config['db_user'] ?? '',
        $config['db_password'] ?? '',
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    // Map the finale option to the stored enum value by keyword,
    // so this works with whatever wording the enum uses.
    $finaleEnum = enumValues($pdo, 'finale_arrangement');
    $finaleValue = null;
    if (stripos($finale, 'attend the in-person') !== false) {
        $finaleValue = enumMatch($finaleEnum, 'attend');
    } elseif (stripos($finale, 'internationally') !== false) {
        $finaleValue = enumMatch($finaleEnum, 'internat');
    } elseif (stripos($finale, 'discuss') !== false) {
        $finaleValue = enumMatch($finaleEnum, 'discuss');
    }
    if ($finaleValue === null) {
        logError('finale_arrangement mapping failed for: ' . $finale . ' | enum: ' . json_encode($finaleEnum));
        fail('Select a valid finale option.');
    }

    // Core columns (your current table)
    $row = [
        'applicant_full_name'        => $fullName,
        'date_of_birth'              => $birthDate,
        'age_group'                  => $ageGroup,
        'email'                      => $email,
        'phone'                      => $phone,
        'preferred_contact'          => $contactMethod,
        'country'                    => $country,
        'region'                     => $region,
        'city'                       => $city,
        'discipline'                 => $discipline,
        'performance_type'           => $perfType,
        'entry_type'                 => $entryType,
        'group_name'                 => $groupName,
        'group_size'                 => $groupSize,
        'mixed_age_group'            => $mixedAge,
        'mixed_age_rules_version'    => $mixedVersion,
        'mixed_age_rules_confirmed'  => $mixedConfirmed,
        'performance_title'          => $title,
        'performance_description'    => $description,
        'performance_language'       => $language,
        'duration_text'              => $duration,
        'originality'                => $originality,
        'video_method'               => 'link',
        'video_url'                  => $videoLink,
        'video_permission'           => 1,
        'finale_arrangement'         => $finaleValue,
        'privacy_notice_version'     => PRIVACY_NOTICE_VERSION,
        'accuracy_confirmed'         => 1,
    ];

    // Optional columns: saved only if you have added them (see ALTER TABLE in the notes).
    $optional = [
        'group_members'         => $members !== '' ? $members : null,
        'previous_experience'   => post('experience') ?: null,
        'special_requirements'  => post('requirements') ?: null,
        'heard_from'            => post('heardFrom') ?: null,
        'guardian_name'         => $isMinor ? $guardian['name'] : null,
        'guardian_relationship' => $isMinor ? $guardian['relationship'] : null,
        'guardian_email'        => $isMinor ? $guardian['email'] : null,
        'guardian_phone'        => $isMinor ? $guardian['phone'] : null,
        'guardian_consent'      => $isMinor ? 1 : 0,
    ];
    $colStmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $colStmt->execute([DB_TABLE]);
    $existing = array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ($optional as $col => $val) {
        if (isset($existing[$col])) {
            $row[$col] = $val;
        }
    }

    // Insert with a unique reference (retry on the rare collision)
    $reference = '';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $reference = 'DF3-' . strtoupper(bin2hex(random_bytes(4)));
        $row['registration_reference'] = $reference;

        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . DB_TABLE . '` (`' . implode('`, `', $cols) . '`) VALUES (:' . implode(', :', $cols) . ')';
        try {
            $pdo->prepare($sql)->execute($row);
            respond(200, ['ok' => true, 'reference' => $reference]);
        } catch (PDOException $e) {
            // 23000 = integrity violation; only retry if it's the reference that collided
            if ($e->getCode() === '23000' && stripos($e->getMessage(), 'registration_reference') !== false) {
                continue;
            }
            throw $e;
        }
    }
    throw new RuntimeException('Could not generate a unique reference.');
} catch (Throwable $e) {
    logError($e->getMessage());
    fail('We could not save your application. Please try again shortly.', 500);
}
