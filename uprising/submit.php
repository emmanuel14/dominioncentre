<?php

/**
 * Dominion Festival 3.0 | ARISE - registration handler
 * Receives the FormData POST from register.html and saves it to the database.
 * Responds with JSON: { ok: true, reference: "DF3-XXXXXXXX" } or { ok: false, message: "..." }
 *
 * Declarations: the canonical wording and version live in this file (DECLARATIONS below).
 * Acceptance is read from the posted checkboxes only. Every declaration is written to
 * `application_declarations` with the server-side wording, version, UTC time, IP and
 * user agent, in the same transaction as the application. Run migration-declarations.sql first.
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
    : 'applications');

const DECLARATIONS_TABLE = 'application_declarations';
const PRIVACY_NOTICE_VERSION = '2026-10-v1';
const MIXED_AGE_RULES_VERSION = 'v1';
const AGE_CUTOFF = '2026-12-04';             // age is calculated as of this date
const LOG_FILE = __DIR__ . '/submit-errors.log';

/**
 * Bump this whenever ANY wording below changes, and change DECL_VERSION in register.html
 * to match. Old cached pages will then be asked to refresh instead of recording stale wording.
 */
const DECLARATIONS_VERSION = 'UPRISING-3.0-DECL-v1.0';

/**
 * Canonical declarations. 'type': mandatory (always), guardian (only for under 18), optional.
 * 'field' is the checkbox name posted by register.html.
 */
const DECLARATIONS = [
    [
        'id' => 'D1',
        'field' => 'decl_accuracy',
        'type' => 'mandatory',
        'title' => 'Declaration of Accuracy & Eligibility',
        'wording' => 'I confirm that all personal information, age details, category selections and audition materials submitted in this application are true, accurate and complete. I understand that false information, impersonation or misrepresentation may result in rejection or disqualification.'
    ],
    [
        'id' => 'D2',
        'field' => 'decl_kingdom',
        'type' => 'mandatory',
        'title' => 'Kingdom Content & Performance Commitment',
        'wording' => 'I have read, understood and agree to the UPRISING 3.0 Kingdom Content & Performance Policy. I undertake to present only songs, dance performances, spoken-word presentations, speeches or dramatic productions that explicitly communicate a biblical truth, Christ-centred message or recognisable aspect of the Christian faith. I understand that content which is merely wholesome, inspirational or morally acceptable, without an identifiable Christian message, does not satisfy this requirement.'
    ],
    [
        'id' => 'D3',
        'field' => 'decl_terms',
        'type' => 'mandatory',
        'title' => 'Competition Terms & Conditions',
        'wording' => 'I have read, understood and agree to abide by the UPRISING 3.0 Competition Terms & Conditions, including eligibility, auditions, judging, intellectual property, contestant conduct, disqualification, complaints and dispute-resolution procedures.'
    ],
    [
        'id' => 'D4',
        'field' => 'decl_handbook',
        'type' => 'mandatory',
        'title' => 'Contestants’ Handbook & Participation Guidelines',
        'wording' => 'I confirm that I have reviewed the UPRISING 3.0 Contestants’ Handbook and understand the applicable audition requirements, performance guidelines, schedules, communications and responsibilities expected of contestants.'
    ],
    [
        'id' => 'D5',
        'field' => 'decl_conduct',
        'type' => 'mandatory',
        'title' => 'Code of Conduct & Competition Integrity',
        'wording' => 'I undertake to act respectfully, honestly and responsibly throughout the competition. I will not engage in harassment, plagiarism, impersonation, fraudulent submissions, vote or score manipulation, intimidation, exploitation, sabotage or other conduct that compromises the integrity or safety of the competition.'
    ],
    [
        'id' => 'D6',
        'field' => 'decl_copyright',
        'type' => 'mandatory',
        'title' => 'Originality, Copyright & Performance Rights',
        'wording' => 'I confirm that I own or have obtained the permissions necessary to submit and perform my chosen material, including any music, lyrics, recordings, choreography, scripts and other protected works. I understand that participation does not transfer ownership of my original work to the organisers.'
    ],
    [
        'id' => 'D7',
        'field' => 'decl_privacy',
        'type' => 'mandatory',
        'title' => 'Privacy & Personal Data Acknowledgement',
        'wording' => 'I acknowledge that I have been provided with the UPRISING 3.0 Privacy Notice, explaining how my registration information, audition materials and related records will be processed for competition administration, judging, safety and lawful operational purposes.'
    ],
    [
        'id' => 'D8',
        'field' => 'decl_recording',
        'type' => 'mandatory',
        'title' => 'Audition Recording & Event Production',
        'wording' => 'I understand that my auditions and performances may be recorded and used for judging, contestant assessment, event production, competition records and other purposes specifically explained in the published competition documents.'
    ],
    [
        'id' => 'D9',
        'field' => 'guardianConsent',
        'type' => 'guardian',
        'title' => 'Guardian Consent & Safeguarding Declaration',
        'wording' => 'I confirm that I am the parent or legally authorised guardian of the named contestant. I have reviewed the competition requirements, including the Kingdom Content Policy, Terms & Conditions, Privacy Notice and applicable safeguarding arrangements. I authorise the minor\'s participation and confirm that the information provided is accurate.'
    ],
    [
        'id' => 'D10',
        'field' => 'publicityConsent',
        'type' => 'optional',
        'title' => 'Publicity & Promotional Media Consent',
        'wording' => 'I authorise the organisers to use selected photographs, interviews, performance excerpts and recordings featuring me for publicity and promotion of UPRISING and related programmes, subject to the published media terms.'
    ],
    [
        'id' => 'D11',
        'field' => 'commsConsent',
        'type' => 'optional',
        'title' => 'Future Opportunities & Communications',
        'wording' => 'I would like to receive information about future UPRISING competitions, talent development opportunities and related programmes.'
    ],
];

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

function normWording(string $s): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $s));
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

/**
 * TODO: deliver the guardian verification link, e.g. https://yoursite/uprising/verify-guardian?ref=...&token=...
 * The guardian must confirm directly; the application stays 'pending' until they do.
 * Implement with your mailer (PHPMailer / SMTP / transactional email API).
 */
function sendGuardianVerification(string $guardianEmail, string $guardianName, string $reference, string $token): void
{
    // intentionally empty until the mailer and verify page exist
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

// A cached copy of the old form (or one with outdated wording) must not be recorded.
if (post('declarationsVersion') !== DECLARATIONS_VERSION) {
    fail('The registration form has been updated. Please refresh this page and complete it again.');
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
        // Mixed-age groups are only accepted once the organisers publish the rules (config.php)
        if (empty($config['mixed_age_rules_published'])) {
            fail('Mixed-age groups are not being accepted yet because the rules have not been published. Please check back or contact the festival team.');
        }
        $mixedAge = 1;
        if (post('mixedRules') === '') fail('Please complete the mixed-age confirmation.');
        $mixedConfirmed = 1;
        $mixedVersion = MIXED_AGE_RULES_VERSION;
    }
}

// Audition checkboxes
if (!checked('videoLength'))     fail('Please confirm your video details.');
if (!checked('videoPermission')) fail('Video permission is required.');

// Declarations: every mandatory one must be ticked (and the guardian one for minors).
// Optional ones are recorded as accepted or declined and never block submission.
foreach (DECLARATIONS as $d) {
    $required = $d['type'] === 'mandatory' || ($d['type'] === 'guardian' && $isMinor);
    if ($required && !checked($d['field'])) {
        fail($d['type'] === 'guardian'
            ? 'Parent or guardian authorisation is required for applicants under 18.'
            : 'Please accept all mandatory declarations before submitting.');
    }
}

// Wording the browser says it showed (audit only; the server's wording is what we record)
$shown = [];
$rawRecord = json_decode(post('declarationsRecord'), true);
if (is_array($rawRecord)) {
    foreach ($rawRecord as $item) {
        if (is_array($item) && isset($item['id'], $item['wording']) && is_string($item['id']) && is_string($item['wording'])) {
            $shown[$item['id']] = normWording($item['wording']);
        }
    }
}

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
}

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
$pdo = null;
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
        'privacy_notice_version'     => (string) ($config['privacy_notice_version'] ?? PRIVACY_NOTICE_VERSION),
        'accuracy_confirmed'         => 1, // declaration D1 is mandatory, so always accepted here
    ];

    // Guardian verification: the tick box alone is not proof. The guardian must confirm
    // directly through an emailed link; until then the application is 'pending'.
    $guardianToken = null;
    if ($isMinor) {
        $guardianToken = bin2hex(random_bytes(32));
    }

    // Optional columns: saved only if you have added them (run migration-declarations.sql).
    $optional = [
        'group_members'         => $members !== '' ? $members : null,
        'previous_experience'  => post('experience') ?: null,
        'special_requirements' => post('requirements') ?: null,
        'heard_from'           => post('heardFrom') ?: null,
        'guardian_name'         => $isMinor ? $guardian['name'] : null,
        'guardian_relationship' => $isMinor ? $guardian['relationship'] : null,
        'guardian_email'        => $isMinor ? $guardian['email'] : null,
        'guardian_phone'        => $isMinor ? $guardian['phone'] : null,
        'guardian_consent'      => $isMinor ? 1 : 0,
        'guardian_verification_status'     => $isMinor ? 'pending' : 'not_required',
        'guardian_verification_token_hash' => $guardianToken !== null ? hash('sha256', $guardianToken) : null,
        'declarations_version'  => DECLARATIONS_VERSION,
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

    // Application + declarations are saved together or not at all.
    $acceptedAt = gmdate('Y-m-d H:i:s'); // UTC
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $pdo->beginTransaction();

    // Insert with a unique reference (retry on the rare collision)
    $reference = '';
    $saved = false;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $reference = 'DF3-' . strtoupper(bin2hex(random_bytes(4)));
        $row['registration_reference'] = $reference;

        $cols = array_keys($row);
        $sql = 'INSERT INTO `' . DB_TABLE . '` (`' . implode('`, `', $cols) . '`) VALUES (:' . implode(', :', $cols) . ')';
        try {
            $pdo->prepare($sql)->execute($row);
            $saved = true;
            break;
        } catch (PDOException $e) {
            // 23000 = integrity violation; only retry if it's the reference that collided
            if ($e->getCode() === '23000' && stripos($e->getMessage(), 'registration_reference') !== false) {
                continue;
            }
            throw $e;
        }
    }
    if (!$saved) {
        throw new RuntimeException('Could not generate a unique reference.');
    }

    // Record every declaration shown to this applicant, accepted or declined.
    $declStmt = $pdo->prepare(
        'INSERT INTO `' . DECLARATIONS_TABLE . '`
            (registration_reference, declarations_version, declaration_id, field_name, title,
             is_optional, wording, wording_shown, accepted, accepted_at, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach (DECLARATIONS as $d) {
        if ($d['type'] === 'guardian' && !$isMinor) {
            continue; // not presented to adults
        }
        $canonical = normWording($d['wording']);
        $shownText = $shown[$d['id']] ?? null;
        if ($shownText !== null && $shownText !== $canonical) {
            logError("Wording mismatch for {$d['id']} on $reference (browser text differs from server text).");
        }
        $declStmt->execute([
            $reference,
            DECLARATIONS_VERSION,
            $d['id'],
            $d['field'],
            $d['title'],
            $d['type'] === 'optional' ? 1 : 0,
            $d['wording'],
            $shownText,
            checked($d['field']) ? 1 : 0,
            $acceptedAt,
            $ip,
            $ua,
        ]);
    }

    $pdo->commit();

    if ($isMinor && $guardianToken !== null) {
        try {
            sendGuardianVerification($guardian['email'], $guardian['name'], $reference, $guardianToken);
        } catch (Throwable $mailErr) {
            // The application is saved; staff can resend the verification request.
            logError("Guardian verification email failed for $reference: " . $mailErr->getMessage());
        }
    }

    respond(200, ['ok' => true, 'reference' => $reference]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logError($e->getMessage());
    fail('We could not save your application. Please try again shortly.', 500);
}
