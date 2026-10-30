<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
if (defined('OCPEPTIDE_UPDATE_PROBE') && OCPEPTIDE_UPDATE_PROBE && ($_GET['resource'] ?? '') === 'update_health') {
    header('Cache-Control: no-store, private');
    try {
        $state = read_state();
        if (!is_array($state['settings']) || !is_array($state['users']) || !is_array($state['assets'])
            || !function_exists('transaction') || !function_exists('login_with_password') || !function_exists('contact_selection')) {
            throw new RuntimeException('Business configuration unavailable.');
        }
        // Exercise the real challenge generator in disposable memory only:
        // no session_start, cookie, state transaction or persistent session.
        $_SESSION = [];
        $challenge = new_login_challenge();
        $ready = is_string($challenge['id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $challenge['id'])
            && is_string($challenge['question'] ?? null) && preg_match('/^[0-9]{1,3} [+-] [0-9]{1,3} = \?$/D', $challenge['question'])
            && ($challenge['expires_at'] ?? 0) > time();
        if ($ready) {
            require_login_challenge(['challenge_id' => $challenge['id'], 'challenge_answer' => $_SESSION['login_challenge']['answer']]);
            $ready = !isset($_SESSION['login_challenge']);
        }
        unset($_SESSION);
        if (!$ready) { throw new RuntimeException('Login challenge unavailable.'); }
        json_out(['product' => PRODUCT_ID, 'version' => CODE_VERSION, 'configuration_readable' => true, 'login_ready' => true]);
    } catch (Throwable) { json_out(['error' => 'Update health check failed.'], 503); }
}
admin_headers();
try {
    $state = read_state();
    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $_GET['resource'] ?? '';
    if ($method === 'GET' && $resource === 'bootstrap') { json_out(['csrf'=>$_SESSION['csrf'],'migration_required'=>empty($state['users']),'challenge'=>new_login_challenge()]); }
    if ($method === 'GET') {
        $admin = current_admin($state);
        if ($resource === '') { json_out(admin_view($state,$admin)); }
        if (!empty($admin['must_change_credentials'])) { throw new ApiError('首次登录请先修改默认密码。',403); }
        require_super($admin);
        if ($resource === 'users') { json_out(['users'=>array_map('public_user',$state['users'])]); }
        if ($resource === 'logs') {
            $page = max(1,(int)($_GET['page'] ?? 1));
            $filter = strtolower(substr((string)($_GET['username'] ?? ''),0,100));
            $logs = array_reverse($state['logs']);
            if ($filter !== '') { $logs = array_values(array_filter($logs,fn($entry)=>str_contains(strtolower($entry['username']),$filter))); }
            json_out(['logs'=>array_slice($logs,($page-1)*50,50),'page'=>$page,'total'=>count($logs),'page_size'=>50]);
        }
        throw new ApiError('未知页面。',404);
    }
    if ($method !== 'POST') { throw new ApiError('不支持此请求方式。',405); }
    require_csrf();
    $action = $_GET['action'] ?? '';
    if (!in_array($action,['login','upgrade'],true)) {
        $current=current_admin($state,false);
        if (!empty($current['must_change_credentials']) && !in_array($action,['change_password','logout','touch'],true)) { throw new ApiError('首次登录请先修改默认密码。',403); }
    }
    if ($action === 'upload') {
        current_admin($state,false);
        $upload = $_FILES['image'] ?? null;
        if (!$upload || !is_int($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) { throw new ApiError('上传失败，请选择不超过 8MB 的图片并检查主机上传限制。'); }
        if ($upload['size']>8*1024*1024 || $upload['size']<1) { throw new ApiError('每张图片必须小于 8MB。'); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $types = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        $size = @getimagesize($upload['tmp_name']);
        if (!isset($types[$mime]) || !$size || $size[0]*$size[1]>40000000 || $size['mime']!==$mime) { throw new ApiError('仅支持真实的 JPG、PNG、WebP、GIF 图片，像素总数不能超过 4000 万。'); }
        $path = '/uploads/'.bin2hex(random_bytes(16)).'.'.$types[$mime];
        transaction(function (&$state) use ($path,$upload) {
            $admin = current_admin($state);
            $files = glob(PUBLIC_DIR.'/uploads/*') ?: [];
            $bytes = array_sum(array_map('filesize',array_filter($files,'is_file')));
            $uploadedCount = count(array_filter($state['assets'],fn($image)=>str_starts_with($image,'/uploads/')));
            if ($uploadedCount>=100 || $bytes+$upload['size']>200*1024*1024) { throw new ApiError('图片库已达到 100 张或 200MB 上限，请先在图片库删除不需要的图片。'); }
            if (!move_uploaded_file($upload['tmp_name'],PUBLIC_DIR.$path)) { throw new RuntimeException('Cannot store upload.'); }
            chmod(PUBLIC_DIR.$path,0644);
            $state['assets'][] = $path;
            audit($state,$admin,'image_uploaded',$path);
        });
        json_out(['image'=>$path]);
    }
    $raw = file_get_contents('php://input');
    if (strlen($raw)>50000) { throw new ApiError('请求内容过大。',413); }
    try { $input = json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new ApiError('请求格式不正确。'); }
    if (!is_array($input)) { throw new ApiError('请求格式不正确。'); }
    if ($action === 'login') {
        require_login_challenge($input);
        if (!is_string($input['username'] ?? null) || !is_string($input['password'] ?? null)) { throw new ApiError('请输入账号和密码。'); }
        $user = login_with_password($input['username'],$input['password']);
        json_out(['ok'=>true,'admin'=>$user]);
    }
    if ($action === 'upgrade') {
        $password = password_field($input['new_password'] ?? null);
        if ($password !== ($input['confirm_password'] ?? null)) { throw new ApiError('两次新密码不一致。'); }
        $legacy = $input['legacy_token'] ?? '';
        $result = transaction(function (&$state) use ($password,$legacy) {
            if (!empty($state['users'])) { throw new ApiError('账号升级已完成，请使用账号密码登录。',409); }
            $keys = throttle_keys('legacy-upgrade');
            $wait = throttle_wait($state,$keys);
            if ($wait) { return ['error'=>'尝试次数过多，请稍后再试。','status'=>429,'retry_after'=>$wait]; }
            $valid = false;
            if (is_string($legacy) && preg_match('/^[a-f0-9]{64}$/D',$legacy)) {
                foreach ($state['tokens'] ?? [] as $token) { if ($token['role']==='master' && hash_equals($token['hash'],hash('sha256',$legacy))) { $valid=true; } }
            }
            if (!$valid) {
                $wait = throttle_failed($state,$keys);
                audit($state,['id'=>'','username'=>'legacy-admin'],'upgrade_failed');
                return ['error'=>'原主管理员 token 不正确。','status'=>$wait?429:403,'retry_after'=>$wait];
            }
            return ['ok'=>true,'credentials'=>migrate_legacy_accounts($state,$password)];
        });
        if (isset($result['error'])) {
            if ($result['retry_after']) { header('Retry-After: '.$result['retry_after']); }
            throw new ApiError($result['error'],$result['status']);
        }
        json_out($result);
    }
    current_admin($state,false);
    $imageFile = null;
    $result = transaction(function (&$state) use ($action,$input,&$imageFile) {
        $admin = current_admin($state);
        if ($action === 'touch') { return ['ok'=>true,'expires_at'=>time()+SESSION_TTL]; }
        if ($action === 'logout') { audit($state,$admin,'logout',$admin['username']); return ['ok'=>true,'logout'=>true]; }
        if ($action === 'delete_image') {
            if (($input['version'] ?? null) !== $state['version']) { throw new ApiError('其他管理员已更新图片，请刷新后台后重试。',409); }
            $path = $input['image'] ?? null;
            if (!is_string($path) || !in_array($path,$state['assets'],true)) { throw new ApiError('图片不存在或已经删除。',404); }
            $imageFile = managed_image_file($path);
            $remaining = array_values(array_filter($state['settings']['images'],fn($image)=>$image !== $path));
            if (!$remaining) {
                $replacement = $input['replacement'] ?? null;
                if (!is_string($replacement) || $replacement === $path || !in_array($replacement,$state['assets'],true)
                    || managed_image_file($replacement) === null) {
                    throw new ApiError('至少保留一张头图，请先上传或选择新图片，再删除旧图。');
                }
                $remaining = [$replacement];
            }
            $state['settings']['images'] = $remaining;
            $state['assets'] = array_values(array_filter($state['assets'],fn($image)=>$image !== $path));
            $state['version']++;
            audit($state,$admin,'image_deleted',$path,['fields'=>['images'],'version'=>$state['version']]);
            return ['ok'=>true,'version'=>$state['version'],'settings'=>$state['settings'],'assets'=>$state['assets']];
        }
        if ($action === 'save_brand') {
            if (($input['version'] ?? null)!==$state['version']) { throw new ApiError('其他管理员已更新此页面，请重新载入后再修改。',409); }
            $name = text_field($input, 'site_name', 1, 60);
            $fields = $state['settings']['site_name'] === $name ? [] : ['site_name'];
            $state['settings']['site_name'] = $name;
            $state['version']++;
            audit($state,$admin,'site_saved','backend',['fields'=>$fields,'version'=>$state['version']]);
            return ['ok'=>true,'site_name'=>$name,'version'=>$state['version']];
        }
        if ($action === 'save') {
            if (($input['version'] ?? null)!==$state['version']) { throw new ApiError('其他管理员已更新此页面，请重新载入后再修改。',409); }
            if (!is_array($input['settings'] ?? null)) { throw new ApiError('设置格式不正确。'); }
            $settings = validate_settings($input['settings'],$state);
            $fields = [];
            foreach ($settings as $key=>$value) { if (($state['settings'][$key] ?? null)!==$value) { $fields[]=$key; } }
            if ($state['settings']['numbers'] !== $settings['numbers']) { $state['wa_next_index'] = 0; }
            $state['settings']=$settings;$state['version']++;
            audit($state,$admin,'site_saved','homepage',['fields'=>$fields,'version'=>$state['version']]);
            return ['ok'=>true,'version'=>$state['version']];
        }
        if ($action === 'change_password') {
            $password=password_field($input['new_password'] ?? null);
            if ($password!==($input['confirm_password'] ?? null)) { throw new ApiError('两次新密码不一致。'); }
            $username=username_field($input['new_username'] ?? $admin['username']);
            foreach ($state['users'] as $user) {
                if ($user['id']!==$admin['id'] && $user['username']===$username) { throw new ApiError('此账号名称已被使用。'); }
            }
            $keys=throttle_keys('change-password:'.$admin['username']);$wait=throttle_wait($state,$keys);
            if ($wait) { return ['error'=>'尝试次数过多，请 15 分钟后重试。','status'=>429]; }
            $old=$input['old_password'] ?? null;
            if (!is_string($old) || strlen($old)>72 || str_contains($old,"\0") || !password_verify($old,$admin['password_hash'])) {
                $wait=throttle_failed($state,$keys);audit($state,$admin,'password_change_failed',$admin['username']);
                return ['error'=>'原密码不正确。','status'=>$wait?429:422];
            }
            if (hash_equals($old,$password)) { throw new ApiError('新密码不能与原密码相同。'); }
            foreach ($state['users'] as &$user) {
                if ($user['id']===$admin['id']) {
                    $user['password_hash']=password_hash($password,PASSWORD_DEFAULT);$user['username']=$username;
                    $user['must_change_credentials']=false;$user['auth_version']++;break;
                }
            }
            unset($user);
            foreach ($keys as $key) { unset($state['login_limits'][$key]); }
            audit($state,$admin,'password_changed',$username,['username_changed'=>$username!==$admin['username'],'old_username'=>$admin['username']]);
            return ['ok'=>true,'logout'=>true];
        }
        require_super($admin);
        if ($action === 'create_user') {
            if (count($state['users'])>=50) { throw new ApiError('最多创建 50 个管理员账号。'); }
            $username=username_field($input['username'] ?? null);
            foreach ($state['users'] as $user) { if ($user['username']===$username) { throw new ApiError('此账号已存在。'); } }
            $role=$input['role'] ?? '';
            if (!is_string($role)) { throw new ApiError('角色格式不正确。'); }
            $user=new_user($username,password_field($input['password'] ?? null),$role);
            $state['users'][]=$user;audit($state,$admin,'user_created',$username,['role'=>$role]);
            return ['ok'=>true];
        }
        if ($action === 'update_user' || $action === 'delete_user') {
            $found=null;
            foreach ($state['users'] as $index=>$user) { if ($user['id']===($input['id'] ?? '')) { $found=$index;break; } }
            if ($found===null) { throw new ApiError('账号已不存在。',404); }
            $target=$state['users'][$found];
            if ($action==='delete_user') {
                array_splice($state['users'],$found,1);ensure_super_remains($state['users']);
                audit($state,$admin,'user_deleted',$target['username']);
            } else {
                $role=$input['role'] ?? $target['role'];$enabled=$input['enabled'] ?? $target['enabled'];
                if (!in_array($role,['super','admin'],true) || !is_bool($enabled)) { throw new ApiError('角色或启用状态不正确。'); }
                $state['users'][$found]['role']=$role;$state['users'][$found]['enabled']=$enabled;
                $state['users'][$found]['auth_version']++;ensure_super_remains($state['users']);
                audit($state,$admin,'user_updated',$target['username'],['old_role'=>$target['role'],'role'=>$role,'enabled'=>$enabled]);
            }
            return ['ok'=>true,'logout'=>$target['id']===$admin['id']];
        }
        throw new ApiError('未知操作。',404);
    }, $action === 'delete_image' ? function () use (&$imageFile): void {
        // Persist metadata before unlink; on failure transaction restores it while still locked.
        if ($imageFile !== null && !@unlink($imageFile)) { throw new ApiError('图片文件删除失败，请检查目录权限后重试。',500); }
    } : null);
    if (isset($result['error'])) { throw new ApiError($result['error'],$result['status']); }
    if (!empty($result['logout'])) { end_login(); }
    json_out($result);
} catch (ApiError $e) {
    $error = ['error'=>$e->getMessage()];
    if (($action ?? '') === 'login') { $error['challenge'] = new_login_challenge(); }
    json_out($error,$e->status);
} catch (Throwable $e) {
    error_log((string)$e);
    $error = ['error'=>'服务器暂时无法处理，请检查私有数据目录、上传目录写权限与 PHP 配置。'];
    if (($action ?? '') === 'login') { $error['challenge'] = new_login_challenge(); }
    json_out($error,500);
}
