<?php
declare(strict_types=1);
const SESSION_TTL = 7 * 24 * 60 * 60;
const LOGIN_WINDOW = 15 * 60;
const LOGIN_LIMIT = 10;
const AUDIT_LIMIT = 5000;
const LOGIN_CHALLENGE_TTL = 5 * 60;

function new_login_challenge(): array {
    $left = random_int(0,100);
    $addition = random_int(0,1) === 1;
    $right = random_int(0,$addition ? 100-$left : $left);
    $_SESSION['login_challenge'] = [
        'id'=>bin2hex(random_bytes(16)),
        'question'=>$left . ($addition ? ' + ' : ' - ') . $right . ' = ?',
        'answer'=>$addition ? $left+$right : $left-$right,
        'expires_at'=>time()+LOGIN_CHALLENGE_TTL
    ];
    return array_intersect_key($_SESSION['login_challenge'],array_flip(['id','question','expires_at']));
}
function require_login_challenge(array $input): void {
    $challenge = $_SESSION['login_challenge'] ?? null;
    // PHP session locking makes consumption atomic across simultaneous requests.
    unset($_SESSION['login_challenge']);
    $answer = $input['challenge_answer'] ?? null;
    $id = $input['challenge_id'] ?? null;
    if (!is_array($challenge) || $challenge['expires_at'] <= time() || !is_string($id)
        || !hash_equals($challenge['id'],$id) || (!is_string($answer) && !is_int($answer))
        || !preg_match('/^[0-9]{1,3}$/D',(string)$answer) || (int)$answer !== $challenge['answer']) {
        throw new ApiError('算术校验不正确或已失效，请回答新题。');
    }
}

function secure_cookie(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function session_cookie(int $expires): void {
    setcookie(session_name(), session_id(), ['expires'=>$expires,'path'=>'/admin','secure'=>secure_cookie(),'httponly'=>true,'samesite'=>'Strict']);
}
function admin_headers(): void {
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; frame-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $directory = DATA_DIR . '/sessions';
    if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) { throw new RuntimeException('Cannot create private session directory.'); }
    // A dedicated path prevents shared-hosting /tmp cleanup from shortening seven days.
    session_save_path($directory);
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.gc_maxlifetime',(string)SESSION_TTL);
    session_name('ocpeptide_account');
    session_set_cookie_params(['lifetime'=>SESSION_TTL,'path'=>'/admin','secure'=>secure_cookie(),'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
}
function require_csrf(): void {
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { throw new ApiError('安全验证失败，请刷新页面后重试。',403); }
}
function current_admin(array $state, bool $renew = true): array {
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if (!$last || time()-$last >= SESSION_TTL) { throw new ApiError('登录已过期，请重新输入账号密码。',401); }
    foreach ($state['users'] ?? [] as $user) {
        if ($user['id'] === ($_SESSION['user_id'] ?? '') && $user['enabled'] && $user['auth_version'] === ($_SESSION['auth_version'] ?? null)) {
            if ($renew) { $_SESSION['last_activity'] = time(); session_cookie(time()+SESSION_TTL); }
            return $user;
        }
    }
    throw new ApiError('账号已停用、删除或密码已修改，请重新登录。',401);
}
function require_super(array $user): void {
    if ($user['role'] !== 'super') { throw new ApiError('只有超级管理员可以管理账号和查看日志。',403); }
}
function public_user(array $user): array {
    return array_intersect_key($user,array_flip(['id','username','role','enabled','created_at','last_login','must_change_credentials']));
}
function admin_view(array $state, array $user): array {
    return ['settings'=>$state['settings'],'version'=>$state['version'],'assets'=>$state['assets'],
            'admin'=>public_user($user),'csrf'=>$_SESSION['csrf'],'expires_at'=>(int)$_SESSION['last_activity']+SESSION_TTL];
}
function audit(array &$state, ?array $user, string $action, string $target = '', array $details = []): void {
    if (!$details) { $details = ['fields'=>[]]; }
    $state['logs'][] = ['id'=>bin2hex(random_bytes(12)),'at'=>gmdate('c'),'actor_id'=>$user['id'] ?? '',
        'username'=>$user['username'] ?? 'unknown','action'=>$action,'target'=>$target,'details'=>$details,
        'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? 'local'),0,45)];
    if (count($state['logs']) > AUDIT_LIMIT) { $state['logs'] = array_slice($state['logs'],-AUDIT_LIMIT); }
}
function username_field(mixed $value): string {
    if (!is_string($value) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{2,31}$/D',trim($value))) { throw new ApiError('账号须为 3–32 位，以字母开头，只含字母、数字、下划线、点或连字符。'); }
    return strtolower(trim($value));
}
function password_field(mixed $value): string {
    if (!is_string($value) || strlen($value)<8 || strlen($value)>72 || str_contains($value,"\0")) { throw new ApiError('密码须为 8–72 字节，建议使用随机英文字母、数字和符号。'); }
    return $value;
}
function new_user(string $username, string $password, string $role): array {
    if (!in_array($role,['super','admin'],true)) { throw new ApiError('请选择超级管理员或普通管理员。'); }
    return ['id'=>bin2hex(random_bytes(12)),'username'=>$username,'role'=>$role,'enabled'=>true,
        'password_hash'=>password_hash(password_field($password),PASSWORD_DEFAULT),'auth_version'=>1,'created_at'=>gmdate('c'),'last_login'=>null];
}
function throttle_keys(string $username): array {
    return [hash('sha256','account:'.$username),hash('sha256','ip:'.($_SERVER['REMOTE_ADDR'] ?? 'local'))];
}
function throttle_wait(array &$state, array $keys): int {
    $now = time();
    foreach ($state['login_limits'] as $key=>$entry) {
        if (($entry['until'] ?? $entry['started']+LOGIN_WINDOW) <= $now) { unset($state['login_limits'][$key]); }
    }
    $wait = 0;
    foreach ($keys as $key) { $wait = max($wait,(int)($state['login_limits'][$key]['until'] ?? 0)-$now); }
    return max(0,$wait);
}
function throttle_failed(array &$state, array $keys): int {
    foreach ($keys as $key) {
        $entry = $state['login_limits'][$key] ?? ['started'=>time(),'count'=>0];
        $entry['count']++;
        if ($entry['count'] >= LOGIN_LIMIT) { $entry['until'] = time()+LOGIN_WINDOW; }
        $state['login_limits'][$key] = $entry;
    }
    if (count($state['login_limits'])>5000) { $state['login_limits'] = array_slice($state['login_limits'],-5000,null,true); }
    return throttle_wait($state,$keys);
}
function login_with_password(string $username, string $password): array {
    $username = strtolower(trim(substr($username,0,100)));
    $result = transaction(function (&$state) use ($username,$password) {
        if (empty($state['users'])) { return ['error'=>'旧版站点尚未迁移账号，请先完成一次账号升级。','status'=>409]; }
        $keys = throttle_keys($username);
        $wait = throttle_wait($state,$keys);
        if ($wait) { return ['error'=>'登录暂时受限，请稍后再试。','status'=>429,'retry_after'=>$wait]; }
        $found = null;
        foreach ($state['users'] as $index=>$user) { if ($user['username']===$username) { $found = $index; break; } }
        $hash = $found === null ? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi' : $state['users'][$found]['password_hash'];
        $valid = strlen($password)<=72 && !str_contains($password,"\0") && password_verify($password,$hash);
        if (!$valid || $found === null || !$state['users'][$found]['enabled']) {
            $wait = throttle_failed($state,$keys);
            audit($state,['id'=>'','username'=>$username],'login_failed',$username,['locked'=>$wait>0]);
            return ['error'=>$wait ? '连续登录失败已达 10 次，请 15 分钟后重试。' : '账号或密码不正确。','status'=>$wait?429:401,'retry_after'=>$wait];
        }
        foreach ($keys as $key) { unset($state['login_limits'][$key]); }
        $state['users'][$found]['last_login'] = gmdate('c');
        if (password_needs_rehash($hash,PASSWORD_DEFAULT)) { $state['users'][$found]['password_hash'] = password_hash($password,PASSWORD_DEFAULT); }
        $user = $state['users'][$found];
        audit($state,$user,'login',$username);
        return ['user'=>$user];
    });
    if (isset($result['error'])) {
        if (!empty($result['retry_after'])) { header('Retry-After: '.$result['retry_after']); }
        throw new ApiError($result['error'],$result['status']);
    }
    session_regenerate_id(true);
    $user = $result['user'];
    $_SESSION = ['user_id'=>$user['id'],'auth_version'=>$user['auth_version'],'last_activity'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    session_cookie(time()+SESSION_TTL);
    return public_user($user);
}
function end_login(): void {
    $_SESSION = [];
    session_cookie(time()-3600);
    session_destroy();
}
function ensure_super_remains(array $users): void {
    foreach ($users as $user) { if ($user['role']==='super' && $user['enabled']) { return; } }
    throw new ApiError('至少保留一个启用的超级管理员，不能停用、删除或降级最后一个。');
}
function migrate_legacy_accounts(array &$state, string $adminPassword): array {
    if (!empty($state['users'])) { throw new ApiError('账号升级已完成，不可重复初始化。',409); }
    $credentials = [];
    $users = [];
    foreach ($state['tokens'] ?? [] as $token) {
        $super = $token['role']==='master';
        $username = $super ? 'admin' : 'member_'.substr($token['id'],0,12);
        $password = $super ? $adminPassword : bin2hex(random_bytes(12));
        $users[] = new_user($username,$password,$super?'super':'admin');
        if (!$super) { $credentials[] = ['username'=>$username,'password'=>$password]; }
    }
    if (!$users) { $users[] = new_user('admin',$adminPassword,'super'); }
    ensure_super_remains($users);
    $state['users'] = $users;
    $state['schema_version'] = 2;
    $state['login_limits'] = [];
    unset($state['tokens']);
    audit($state,$users[0],'accounts_migrated','admin',['count'=>count($users)]);
    return $credentials;
}
