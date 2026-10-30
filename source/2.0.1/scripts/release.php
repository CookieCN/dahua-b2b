<?php
declare(strict_types=1);
// Release tooling (dev side only; never shipped inside a package's payload
// paths and never run on customer sites).
//
//   php scripts/release.php keys <dir>
//   php scripts/release.php baseline <root> [--stdout]
//   php scripts/release.php withdraw --out <dir> --key <private.pem> --version <x.y.z>
//   php scripts/release.php build <root> --key <private.pem> --out <dir>
//        [--notes <text>] [--notes-file <file>] [--min-upgradable <ver>]
//        [--delete <path[,path...]>] [--withdraw <version>] [--channel <name>]
//   php scripts/release.php verify <zip> --key <public.pem>
//   php scripts/release.php lint <root>
//
// Keys live outside the repository (e.g. ignored delivery/signing/) so no
// private key can ever enter a package or the source tree.

function fail(string $message): never {
    // A structured throw instead of a bare exit lets owning try/finally blocks
    // run (removing this process's own uncommitted temp files); the top-level
    // CLI catch below prints the message and exits non-zero.
    throw new ReleaseCliError($message);
}

/** Top-level CLI failure: message on stderr, exit code 1. */
final class ReleaseCliError extends RuntimeException {}

// The publisher and the client MUST validate identical channel structures,
// so the pure include-safe envelope/payload schema helpers live once in the
// standalone updater engine and are shared here. Decoding supplied bytes
// touches no bootstrap, sessions, trust config or customer data.
if (!defined('OCPEPTIDE_UPDATER_ENGINE')) {
    $sharedEngine = dirname(__DIR__) . '/private/updater-engine.php';
    if (!is_file($sharedEngine)) { fail('shared channel decoder missing: private/updater-engine.php'); }
    require $sharedEngine;
}

/** Windows/dev PHP builds often lack an OpenSSL config; keygen needs one, so
 *  generate a secure temporary minimal config and pass it per call. */
function release_openssl_config(): string {
    $configured = getenv('OPENSSL_CONF');
    if (is_string($configured) && $configured !== '' && is_file($configured)) { return $configured; }
    foreach ([dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', dirname(PHP_BINARY) . '/openssl.cnf'] as $candidate) {
        if (is_file($candidate)) { return $candidate; }
    }
    // Owned exclusive temporary config: native tempnam() creates a unique file
    // (never a predictable PID path); the full write is verified by read-back;
    // only this process-owned file is cleaned at shutdown. An external
    // OPENSSL_CONF is returned untouched and never deleted.
    $temp = tempnam(sys_get_temp_dir(), 'ocpeptide-rel-openssl-');
    if ($temp === false) { fail('cannot create temporary openssl config'); }
    $config = "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n";
    if (@file_put_contents($temp, $config) !== strlen($config)) { @unlink($temp); fail('cannot write temporary openssl config'); }
    if (@file_get_contents($temp, false, null, 0, strlen($config) + 1) !== $config) { @unlink($temp); fail('temporary openssl config write not verifiable'); }
    register_shutdown_function(static function () use ($temp): void { @unlink($temp); });
    return $temp;
}

function release_version_info(string $root): array {
    $raw = @file_get_contents($root . '/private/version.php');
    if (!is_string($raw)) { fail('missing private/version.php under ' . $root); }
    $info = ['product' => null, 'version' => null, 'requires_php' => '8.2',
             'requires_extensions' => ['json','openssl','zip'], 'schema_min' => 2, 'schema_max' => 2];
    if (preg_match("/PRODUCT_ID\s*=\s*'([^']+)'/", $raw, $m)) { $info['product'] = $m[1]; }
    if (preg_match("/CODE_VERSION\s*=\s*'([^']+)'/", $raw, $m)) { $info['version'] = $m[1]; }
    if (preg_match("/UPDATE_REQUIRES_PHP\s*=\s*'([^']+)'/", $raw, $m)) { $info['requires_php'] = $m[1]; }
    if (preg_match('/UPDATE_REQUIRES_EXTENSIONS\s*=\s*\[([^\]]*)\]/', $raw, $m) && preg_match_all("/'([a-z0-9_]+)'/", $m[1], $list)) { $info['requires_extensions'] = $list[1]; }
    if (preg_match('/SCHEMA_MIN\s*=\s*(\d+)/', $raw, $m)) { $info['schema_min'] = (int)$m[1]; }
    if (preg_match('/SCHEMA_MAX\s*=\s*(\d+)/', $raw, $m)) { $info['schema_max'] = (int)$m[1]; }
    if ($info['product'] === null || $info['version'] === null || !preg_match('/^\d+\.\d+\.\d+$/D', $info['version'])) {
        fail('private/version.php must define PRODUCT_ID and a semver CODE_VERSION');
    }
    // The product becomes a filename prefix: reject anything that could
    // escape the output directory (../, slashes, separators) BEFORE any
    // artifact or temp file is created.
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $info['product']) || str_contains($info['product'], '..')) {
        fail('PRODUCT_ID must be a safe name (letters, digits, dot, underscore, hyphen; no traversal)');
    }
    return $info;
}

/** Sensitive file names never ship, anywhere in the tree. This mirrors
 *  updater_sensitive_name() in private/updater-engine.php exactly; the
 *  contract suite asserts both stay aligned. */
function release_sensitive_name(string $name): bool {
    return (bool)preg_match('~\.(pem|key|env|p12|pfx|crt|cer|sqlite3?|db|log|zip)$~iD', $name)
        || (bool)preg_match('~^(id_rsa.*|.*\.env.*|credentials.*|secrets?[-_.].*|password.*|.*_secret.*)$~iD', $name);
}

/** Files that ship in packages; must mirror the runtime updater allowlist. */
function release_code_path(string $relative): bool {
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || $relative[0] === '/' || str_contains($relative, '..') || str_contains($relative, '//')) { return false; }
    if (str_starts_with($relative, 'private/')) {
        $rest = substr($relative, 8);
        if ($rest === 'code-baseline.json') { return false; } // regenerated per release
        if (release_sensitive_name(basename($rest))) { return false; }
        // private/data and private/update never ship; only flat code files do.
        return (bool)preg_match('~^(?:[^/]+\.php|\.htaccess)$~D', $rest);
    }
    if (str_starts_with($relative, 'public_html/')) {
        $rest = substr($relative, 12);
        if ($rest === '' || str_ends_with($rest, '/')) { return false; }
        // Tooling, tests, backups and repository metadata never ship.
        foreach (['tests/', 'delivery/', 'docs/', 'scripts/', '.agents/', '.tools/', '.git/', 'private/', 'backups/'] as $blocked) {
            if (str_starts_with($rest, $blocked)) { return false; }
        }
        // Customer hero image and uploads never ship in an update payload.
        if ($rest === 'hero.webp' || (str_starts_with($rest, 'uploads/') && $rest !== 'uploads/.htaccess')) { return false; }
        $name = basename($rest);
        if ($name === '.htaccess') { return true; }
        if (release_sensitive_name($name)) { return false; }
        // Static/code assets only; nothing else ships unseen into public paths.
        return (bool)preg_match('~\.(php|css|js|json|svg|ico|txt|woff2?|map)$~iD', $name);
    }
    return false;
}

/** Online payload policy is shared with the client. Fixed entries remain
 *  in the full baseline, so an attempted bootstrap change also fails the
 *  installer's old-map plus signed-operations consistency check. */
function release_shippable(string $relative): bool {
    return release_code_path($relative) && updater_allowed_path($relative);
}

/** @return string[] relative paths, sorted. Credentials, keys, environment
 *  files and backup artifacts abort the build loudly instead of silently
 *  dropping out of the payload: a contaminated tree must never sign. */
function release_code_files(string $root): array {
    if (!is_dir($root)) { fail('root directory not found: ' . $root); }
    $paths = [];
    $fatal = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY);
    $rootLength = strlen($root);
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) { continue; }
        $relative = str_replace('\\', '/', substr($file->getPathname(), $rootLength));
        $relative = ltrim($relative, '/');
        if (!preg_match('~^(?:private|public_html)/~D', $relative)) { continue; }
        if (release_code_path($relative)) { $paths[] = $relative; continue; }
        if (release_sensitive_name(basename($relative))
            || preg_match('~/backups/~D', $relative) || preg_match('~/tests/~D', $relative)) {
            $fatal[] = $relative;
        }
        // Everything else (runtime data, trust config, uploads, hero, tooling
        // metadata) is excluded silently, mirroring the runtime allowlist.
    }
    if ($fatal) { fail('refusing to sign: sensitive/backup files present in source: ' . implode(', ', array_slice($fatal, 0, 10))); }
    sort($paths);
    if (!$paths) { fail('no code files found under ' . $root); }
    return $paths;
}

function release_baseline_bytes(string $root, array $info, array $files): string {
    $baseline = ['product' => $info['product'], 'version' => $info['version'], 'generated_at' => gmdate('c'), 'files' => []];
    foreach ($files as $relative) {
        $baseline['files'][$relative] = hash_file('sha256', $root . '/' . $relative);
    }
    return json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

function release_sign(string $data, string $privateKeyPem): string {
    $key = openssl_pkey_get_private($privateKeyPem);
    if ($key === false) { fail('cannot load private key'); }
    if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) { fail('signing failed: ' . openssl_error_string()); }
    return base64_encode($signature);
}

/** getopt() stops at the first positional argument; parse --opt[=value] pairs
 *  (also accepting the space-separated form) from anywhere after the command. */
function release_parse_options(array $argv): array {
    $options = [];
    $tokens = array_slice($argv, 2);
    for ($i = 0; $i < count($tokens); $i++) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/Di', (string)$tokens[$i], $match)) {
            $options[$match[1]] = $match[2] ?? ($tokens[$i + 1] ?? '');
            if (($match[2] ?? null) === null && isset($tokens[$i + 1]) && !str_starts_with((string)$tokens[$i + 1], '--')) { $i++; }
        }
    }
    return $options;
}

/** Serialize all publication writes for one output directory. */
function publication_lock(string $out) {
    $handle = @fopen($out . '/.publish.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) { fail('cannot lock output directory: ' . $out); }
    return $handle;
}

// Envelope/payload caps, entry bounds and validators (CHANNEL_* constants,
// updater_channel_decode_envelope, updater_channel_parse_payload and the
// schema helpers) come from the shared standalone engine included above.
const CHANNEL_DEFAULT_PRODUCT = 'ocpeptide-admin';

/** Channel files must be regular readable non-link files. */
function release_regular_file(string $path): bool {
    return is_file($path) && !is_link($path) && is_readable($path);
}

/** Derive the verification key from the validated signing key. */
function release_public_key(string $privatePem): string {
    $key = openssl_pkey_get_private($privatePem);
    if ($key === false) { fail('cannot load --key private key'); }
    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || !is_string($details['key'] ?? null)) { fail('cannot derive public key from --key'); }
    return $details['key'];
}

/** Channel state is either a fully authenticated, schema-valid single
 *  envelope, or a hard failure. The live releases.json envelope is read once
 *  (bounded, regular non-link file), its signature is verified over the exact
 *  decoded payload bytes, and the payload schema is checked with the shared
 *  helpers before anything is trusted. Legacy plain JSON or detached-signature
 *  layouts are structurally rejected by the decoder — no fallback exists. */
function channel_state(string $out, string $publicKeyPem, string $expectedProduct): array {
    $file = $out . '/releases.json';
    if (!file_exists($file) && !is_link($file)) {
        // A leftover legacy detached signature means this output directory has
        // history this tool cannot authenticate; never silently reset it.
        if (file_exists($out . '/releases.sig') || is_link($out . '/releases.sig')) {
            return ['ok' => false, 'file' => false, 'releases' => [], 'error' => 'legacy releases.sig present without an envelope; manual migration required'];
        }
        return ['ok' => true, 'file' => false, 'releases' => [], 'sequence' => 0];
    }
    if (!release_regular_file($file)) { return ['ok' => false, 'file' => true, 'releases' => [], 'error' => 'releases.json exists but is not a regular readable file']; }
    if (filesize($file) > CHANNEL_ENVELOPE_MAX_BYTES) { return ['ok' => false, 'file' => true, 'releases' => [], 'error' => 'releases.json exceeds envelope size cap']; }
    $raw = @file_get_contents($file, false, null, 0, CHANNEL_ENVELOPE_MAX_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > CHANNEL_ENVELOPE_MAX_BYTES) { return ['ok' => false, 'file' => true, 'releases' => [], 'error' => 'releases.json unreadable or oversized']; }
    try {
        $payloadBytes = updater_channel_decode_envelope($raw, $publicKeyPem);
        $payload = updater_channel_parse_payload($payloadBytes, $expectedProduct);
    } catch (UpdateError $error) { return ['ok' => false, 'file' => true, 'releases' => [], 'error' => $error->getMessage()]; }
    if (file_exists($out . '/releases.sig') || is_link($out . '/releases.sig')) {
        return ['ok' => false, 'file' => true, 'releases' => [], 'error' => 'legacy releases.sig present next to the envelope; manual migration required'];
    }
    return ['ok' => true, 'file' => true, 'releases' => $payload['releases'], 'sequence' => (int)$payload['sequence'], 'product' => $payload['product']];
}

/** Compose the exact payload document bytes for a candidate state. */
function channel_payload_bytes(array $state): string {
    return json_encode(['purpose' => CHANNEL_PURPOSE, 'product' => $state['product'],
                        'sequence' => $state['sequence'], 'generated_at' => $state['generated_at'],
                        'releases' => $state['releases']],
                       JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/** Build the single signed live envelope from exact payload bytes. Both caps
 *  (2 MiB payload, 4 MiB envelope) are enforced here — callers invoke this
 *  BEFORE exposing any live artifact and publish the returned bytes with ONE
 *  same-filesystem atomic rename of releases.json. This is a single-file
 *  replacement, not a multi-file transaction; publication interruption
 *  recovery is explicitly NOT implemented in this checkpoint.
 *  release_sign() already returns strict base64. */
function channel_envelope_bytes(string $payloadBytes, string $privatePem): string {
    if (strlen($payloadBytes) > CHANNEL_PAYLOAD_MAX_BYTES) { fail('new channel payload exceeds 2 MiB cap'); }
    $signatureB64 = release_sign($payloadBytes, $privatePem);
    $envelope = json_encode(['format' => CHANNEL_ENVELOPE_FORMAT,
                             'payload' => base64_encode($payloadBytes),
                             'signature' => $signatureB64],
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (strlen($envelope) > CHANNEL_ENVELOPE_MAX_BYTES) { fail('new channel envelope exceeds 4 MiB cap'); }
    return $envelope;
}

function atomic_write(string $path, string $bytes): void {
    $temp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temp, $bytes) !== strlen($bytes)) { @unlink($temp); fail('cannot write ' . $path); }
    if (!rename($temp, $path)) { @unlink($temp); fail('cannot publish ' . $path); }
}

try {
$command = $argv[1] ?? fail('usage: release.php keys|baseline|build|withdraw|verify|lint ...');
$options = release_parse_options($argv);
// Legacy compatibility: `build --withdraw <v>` routes to the metadata-only
// withdrawal and never rebuilds or replaces a published package.
if ($command === 'build' && (string)($options['withdraw'] ?? '') !== '') {
    $command = 'withdraw';
    $options['version'] = (string)$options['withdraw'];
}

switch ($command) {
    case 'keys': {
        $directory = $argv[2] ?? fail('keys requires a target directory');
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) { fail('cannot create directory'); }
        $private = $directory . '/release-private.pem';
        $public = $directory . '/release-public.pem';
        if (is_file($private) || is_file($public)) { fail('key files already exist; refusing to overwrite'); }
        $config = release_openssl_config();
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $config]);
        if ($key === false) { fail('key generation failed: ' . openssl_error_string()); }
        openssl_pkey_export($key, $privatePem, null, ['config' => $config]) || fail('export failed');
        $details = openssl_pkey_get_details($key);
        file_put_contents($private, $privatePem);
        file_put_contents($public, $details['key']);
        chmod($private, 0600);
        echo "Generated RSA-3072 key pair in $directory (keep the private key offline).\n";
        break;
    }
    case 'baseline': {
        $root = rtrim($argv[2] ?? fail('baseline requires a source root'), '/\\');
        $info = release_version_info($root);
        $files = release_code_files($root);
        $bytes = release_baseline_bytes($root, $info, $files);
        if (isset($options['stdout'])) { echo $bytes; break; }
        $target = $root . '/private/code-baseline.json';
        file_put_contents($target, $bytes) || fail('cannot write baseline');
        echo 'Wrote private/code-baseline.json with ' . count($files) . " files for version {$info['version']}.\n";
        break;
    }
    case 'withdraw': {
        // Metadata-only withdrawal: the published package stays byte-for-byte.
        $out = $options['out'] ?? fail('withdraw requires --out <directory>');
        $version = (string)($options['version'] ?? fail('withdraw requires --version <x.y.z>'));
        $keyPath = $options['key'] ?? fail('withdraw requires --key <private.pem>');
        $privatePem = @file_get_contents($keyPath);
        if (!is_string($privatePem) || openssl_pkey_get_private($privatePem) === false) { fail('cannot load --key private key'); }
        if (!is_dir($out)) { fail('output directory not found: ' . $out); }
        // Withdraw has no source tree, so the expected product is the documented
        // fixed default (override with --product); it is never taken from the
        // untrusted metadata being validated.
        $expectedProduct = (string)($options['product'] ?? CHANNEL_DEFAULT_PRODUCT);
        if (strlen($expectedProduct) > 64 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $expectedProduct)) { fail('invalid --product'); }
        $publicKeyPem = release_public_key($privatePem);
        $pub = publication_lock($out);
        try {
            $state = channel_state($out, $publicKeyPem, $expectedProduct);
            if (!$state['ok']) { fail('refusing to touch metadata, existing channel invalid: ' . ($state['error'] ?? '')); }
            $found = false;
            foreach ($state['releases'] as &$release) {
                if ($release['version'] === $version) { $release['withdrawn'] = true; $found = true; }
            }
            unset($release);
            if (!$found) { fail('cannot withdraw: version not present in releases.json: ' . $version); }
            if ($state['sequence'] >= PHP_INT_MAX) { fail('channel sequence overflow; refusing to publish'); }
            $state['product'] = $expectedProduct;
            $state['sequence'] = (int)$state['sequence'] + 1;
            $state['generated_at'] = gmdate('c');
            // The modified payload is validated with the shared schema, then
            // signed and capped ONCE before the single live replacement.
            $payloadBytes = channel_payload_bytes($state);
            $decoded = json_decode($payloadBytes, true, 64);
            if (!is_array($decoded)) { fail('withdrawn channel state unparsable'); }
            $error = updater_channel_payload_error($decoded, $expectedProduct);
            if ($error !== null) { fail('withdrawn channel state invalid: ' . $error); }
            $envelopeBytes = channel_envelope_bytes($payloadBytes, $privatePem);
            atomic_write($out . '/releases.json', $envelopeBytes);
            echo "Withdrawn $version in signed metadata; package bytes untouched.\n";
        } finally { flock($pub, LOCK_UN); fclose($pub); }
        break;
    }
    case 'build': {
        $root = rtrim(($argv[2] ?? fail('build requires a source root')), '/\\');
        $keyPath = $options['key'] ?? fail('build requires --key <private.pem>');
        $out = $options['out'] ?? fail('build requires --out <directory>');
        $privatePem = @file_get_contents($keyPath);
        if (!is_string($privatePem) || openssl_pkey_get_private($privatePem) === false) { fail('cannot load --key private key'); }
        $publicKeyPem = release_public_key($privatePem);
        // Concurrent publishers may race directory creation: recheck on failure.
        if (!is_dir($out) && !@mkdir($out, 0775, true) && !is_dir($out)) { fail('cannot create output directory'); }
        $info = release_version_info($root);
        // New-entry options are validated before anything is written.
        $channelName = (string)($options['channel'] ?? 'stable');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D', $channelName)) { fail('invalid --channel name'); }
        $minUpgradable = (string)($options['min-upgradable'] ?? '2.0.0');
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $minUpgradable)) { fail('invalid --min-upgradable (x.y.z required)'); }
        // Publication is serialized per output directory; every artifact is
        // written atomically and published artifacts are immutable.
        $pub = publication_lock($out);
        $zipTemp = null;
        try {
            $state = channel_state($out, $publicKeyPem, $info['product']);
            if (!$state['ok']) { fail('refusing to publish, existing channel invalid: ' . ($state['error'] ?? '')); }
            if (count($state['releases']) >= CHANNEL_MAX_RELEASES) { fail('channel already holds the maximum of ' . CHANNEL_MAX_RELEASES . ' releases'); }
            $zipName = $info['product'] . '-' . $info['version'] . '.zip';
            $zipPath = $out . '/' . $zipName;
            if (is_file($zipPath)) { fail('package already published and immutable: ' . $zipName . ' (withdraw only changes metadata)'); }
            if (!$state['file'] && glob($out . '/*.zip')) {
                fail('packages exist without releases.json; refusing to publish into this directory');
            }
            foreach ($state['releases'] as $release) {
                if (($release['version'] ?? '') === $info['version']) { fail('version already published: ' . $info['version'] . ' (use withdraw to retire it)'); }
            }
            $files = release_code_files($root);
            $entries = [];
            $total = 0;
            foreach ($files as $relative) {
                if (!release_shippable($relative)) { continue; }
                $size = filesize($root . '/' . $relative);
                $entries[] = ['path' => $relative, 'sha256' => hash_file('sha256', $root . '/' . $relative), 'size' => $size];
                $total += $size;
            }
            $baselineBytes = release_baseline_bytes($root, $info, $files);
            $entries[] = ['path' => 'private/code-baseline.json', 'sha256' => hash('sha256', $baselineBytes), 'size' => strlen($baselineBytes)];
            $total += strlen($baselineBytes);
            $deletions = [];
            foreach (array_filter(array_map('trim', explode(',', (string)($options['delete'] ?? '')))) as $deletePath) {
                if ($deletePath === '') { continue; }
                foreach (array_keys($entries) as $index) { if ($entries[$index]['path'] === $deletePath) { fail('delete path also shipped: ' . $deletePath); } }
                if (!release_shippable($deletePath)) { fail('delete path not allowed: ' . $deletePath); }
                $deletions[] = $deletePath;
            }
            $notes = (string)($options['notes'] ?? '');
            if (isset($options['notes-file'])) {
                $noteFile = @file_get_contents((string)$options['notes-file']);
                if (!is_string($noteFile)) { fail('cannot read --notes-file'); }
                $notes = trim($noteFile);
            }
            $manifest = [
                'product' => $info['product'],
                'version' => $info['version'],
                'min_upgradable' => (string)($options['min-upgradable'] ?? '2.0.0'),
                'requires_php' => $info['requires_php'],
                'requires_extensions' => $info['requires_extensions'],
                'schema_min' => $info['schema_min'],
                'schema_max' => $info['schema_max'],
                'notes' => preg_match_all('/./us', $notes, $m) && count($m[0]) > 5000 ? (preg_match('/^.{0,5000}/us', $notes, $p) ? $p[0] : substr($notes, 0, 5000)) : $notes,
                'created_at' => gmdate('c'),
                'files' => $entries,
                'deletions' => $deletions,
                'total_size' => $total,
            ];
            $manifestBytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $signature = release_sign($manifestBytes, $privatePem);
            // Build the package at a temp path and publish with one rename so a
            // crash can never leave a half-written ZIP in place of an artifact.
            $zipTemp = $zipPath . '.tmp-' . bin2hex(random_bytes(4));
            $zip = new ZipArchive();
            if ($zip->open($zipTemp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fail('cannot create zip'); }
            $zip->addFromString('manifest.json', $manifestBytes);
            $zip->addFromString('manifest.sig', $signature);
            foreach ($entries as $entry) {
                if ($entry['path'] === 'private/code-baseline.json') {
                    // The baseline is generated per release, not read from the tree.
                    $zip->addFromString('payload/' . $entry['path'], $baselineBytes) || fail('cannot add baseline');
                } else {
                    $zip->addFile($root . '/' . $entry['path'], 'payload/' . $entry['path']) || fail('cannot add ' . $entry['path']);
                }
            }
            $zip->close() || fail('cannot close zip');
            // Compose, validate, sign and cap the complete candidate payload
            // and envelope BEFORE the package rename so a bad or oversized
            // candidate can never publish a ZIP without its channel entry.
            if ($state['sequence'] >= PHP_INT_MAX) { fail('channel sequence overflow; refusing to publish'); }
            $state['product'] = $info['product'];
            $state['sequence'] = (int)$state['sequence'] + 1;
            $state['releases'][] = [
                'version' => $info['version'], 'channel' => $channelName,
                'withdrawn' => false, 'published_at' => gmdate('c'),
                'min_upgradable' => $minUpgradable, 'requires_php' => $info['requires_php'],
                'schema_min' => $info['schema_min'], 'schema_max' => $info['schema_max'],
                'notes' => $manifest['notes'],
                'package' => ['file' => $zipName, 'sha256' => hash_file('sha256', $zipTemp), 'size' => filesize($zipTemp)],
            ];
            $state['generated_at'] = gmdate('c');
            $payloadBytes = channel_payload_bytes($state);
            $decoded = json_decode($payloadBytes, true, 64);
            if (!is_array($decoded)) { fail('new channel state unparsable'); }
            $stateError = updater_channel_payload_error($decoded, $info['product']);
            if ($stateError !== null) { fail('new channel state invalid: ' . $stateError); }
            $envelopeBytes = channel_envelope_bytes($payloadBytes, $privatePem);
            if (!rename($zipTemp, $zipPath)) { fail('cannot publish package (rename failed)'); }
            $zipTemp = null;
            // One same-filesystem atomic envelope replacement under the lock.
            atomic_write($out . '/releases.json', $envelopeBytes);
            echo 'Built ' . $zipName . ' (' . count($entries) . ' files, ' . count($deletions) . " deletions) and signed channel metadata in $out.\n";
        } finally {
            if (is_string($zipTemp) && is_file($zipTemp)) { @unlink($zipTemp); }
            flock($pub, LOCK_UN);
            fclose($pub);
        }
        break;
    }
    case 'allowable': {
        // Pure query used by the contract suite to keep this shipper aligned
        // with the runtime updater allowlist; prints 1 or 0 per path.
        foreach (array_slice($argv, 2) as $query) { echo (release_shippable((string)$query) ? '1' : '0') . "\n"; }
        break;
    }
    case 'verify': {
        $zipPath = $argv[2] ?? fail('verify requires a zip path');
        $publicKey = @file_get_contents((string)($options['key'] ?? fail('verify requires --key <public.pem>')));
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) { fail('cannot open zip'); }
        $manifestBytes = $zip->getFromName('manifest.json');
        $signature = $zip->getFromName('manifest.sig');
        if (!is_string($manifestBytes) || !is_string($signature)) { fail('missing manifest or signature'); }
        if (openssl_verify($manifestBytes, base64_decode($signature, true), $publicKey, OPENSSL_ALGO_SHA256) !== 1) { fail('signature invalid'); }
        $manifest = json_decode($manifestBytes, true);
        if (!is_array($manifest)) { fail('manifest json invalid'); }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) { $names[$zip->getNameIndex($i)] = true; }
        $expected = ['manifest.json' => 1, 'manifest.sig' => 1];
        foreach ($manifest['files'] as $entry) { $expected['payload/' . $entry['path']] = 1; }
        if (array_diff_key($names, $expected) || array_diff_key($expected, $names)) { fail('zip entries do not match manifest exactly'); }
        foreach ($manifest['files'] as $entry) {
            $data = $zip->getFromName('payload/' . $entry['path']);
            if (!is_string($data) || hash('sha256', $data) !== $entry['sha256'] || strlen($data) !== $entry['size']) {
                fail('hash mismatch: ' . $entry['path']);
            }
        }
        echo 'OK: ' . $manifest['product'] . ' ' . $manifest['version'] . ', ' . count($manifest['files']) . " files verified.\n";
        break;
    }
    case 'lint': {
        $root = rtrim($argv[2] ?? fail('lint requires a source root'), '/\\');
        $files = release_code_files($root);
        $checked = 0;
        foreach ($files as $relative) {
            if (!str_ends_with($relative, '.php')) { continue; }
            $output = [];
            $code = 1;
            exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($root . '/' . $relative) . ' 2>&1', $output, $code);
            if ($code !== 0) { fail("syntax error in $relative:\n" . implode("\n", $output)); }
            $checked++;
        }
        echo "Lint OK: $checked PHP files.\n";
        break;
    }
    default: fail('unknown command ' . $command);
}

} catch (Throwable $error) {
    fwrite(STDERR, 'release: ' . $error->getMessage() . "\n");
    exit(1);
}
