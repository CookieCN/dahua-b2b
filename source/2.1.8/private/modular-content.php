<?php
declare(strict_types=1);

const MODULAR_LOCALES = ['vi'=>'Tiếng Việt', 'en'=>'English', 'zh'=>'简体中文'];
const MODULAR_LOCALE_COOKIE = 'modular_language';
const MODULAR_TEXT_FIELDS = [
    'title'=>['首屏标题', 200],
    'intro'=>['首屏正文', 1000],
    'brand'=>['页头与页脚品牌名', 100],
    'address'=>['页脚地址', 300],
    'company'=>['公司介绍', 1600],
    'processing'=>['加工方式', 180],
    'whatsapp_label'=>['WhatsApp 按钮文案', 100],
    'message'=>['WhatsApp 预填消息', 500],
];
const MODULAR_PRODUCT_FIELDS = ['name'=>['产品名称', 160], 'spec'=>['规格', 200], 'description'=>['描述', 500], 'button_text'=>['自定义按钮文案', 100]];

function modular_landing_defaults(array $landing): array {
    $count = count($landing['product_images']);
    $enabled = $landing['enabled_locales'] ?? ['en'];
    return $landing + [
        'language_menu'=>true, 'enabled_locales'=>$enabled, 'default_locale'=>in_array('en', $enabled, true) ? 'en' : ($enabled[0] ?? 'en'),
        'mobile_columns'=>1, 'product_buttons'=>array_fill(0, $count, ['mode'=>'inherit', 'url'=>'']),
        'product_ids'=>array_map(static fn(int $i): string => (string)($i + 1), array_keys($landing['product_images'])),
        'product_prices'=>array_fill(0, $count, ['text'=>'', 'hidden'=>true]),
        'contact_email'=>'', 'contact_phone'=>'',
    ];
}

function modular_new_landing(array $settings): array {
    $empty = array_fill_keys(array_keys(MODULAR_TEXT_FIELDS), '') + ['products'=>[]];
    preg_match('/^.{0,1000}/us', $settings['body'], $intro);
    preg_match('/^.{0,500}/us', $settings['message'], $message);
    $english = array_replace($empty, [
        'title'=>$settings['title'], 'intro'=>$intro[0], 'whatsapp_label'=>$settings['button_text'], 'message'=>$message[0],
    ]);
    return modular_landing_defaults([
        'images'=>$settings['images'], 'interval'=>$settings['interval'], 'founded'=>'', 'moq'=>null,
        'product_images'=>[],
        'locales'=>['vi'=>$empty, 'en'=>$english, 'zh'=>$empty],
    ]);
}

function modular_contact_details(array $input): array {
    $email = text_field($input, 'contact_email', 0, 254);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new ApiError('请输入有效的联系邮箱。');
    }
    $phone = preg_replace('/[\s().-]+/u', '', text_field($input, 'contact_phone', 0, 40));
    if ($phone !== '' && !preg_match('/^\+[1-9][0-9]{6,14}$/D', $phone)) {
        throw new ApiError('手机／微信请填写带 + 和国家区号的国际号码。');
    }
    return ['contact_email'=>$email, 'contact_phone'=>$phone];
}

function modular_locale_code(mixed $locale, array $enabled): ?string {
    return is_string($locale) && in_array($locale, $enabled, true) ? $locale : null;
}

function modular_locale(array $landing, array $query, mixed $preference = null, string $acceptLanguage = ''): string {
    $enabled = $landing['enabled_locales'];
    $selected = modular_locale_code($query['lang'] ?? null, $enabled) ?? modular_locale_code($preference, $enabled);
    if ($selected !== null) { return $selected; }

    $best = $landing['default_locale'];
    $weight = 0.0;
    foreach (explode(',', substr($acceptLanguage, 0, 4096)) as $range) {
        if (!preg_match('/^([a-z]{1,8})(?:-[a-z0-9]{1,8})*(?:\s*;\s*q\s*=\s*(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?))?$/iD', trim($range), $parts)) {
            continue;
        }
        $locale = modular_locale_code(strtolower($parts[1]), $enabled);
        $quality = (float)($parts[2] ?? 1);
        if ($locale !== null && $quality > $weight) {
            $best = $locale;
            $weight = $quality;
        }
    }
    return $best;
}

function modular_contact_message(array $landing, array $query, string $fallback): string {
    $lang = modular_locale($landing, $query);
    $copy = $landing['locales'][$lang];
    $message = $copy['message'] !== '' ? $copy['message'] : $fallback;
    $product = $query['product'] ?? null;
    $index = is_string($product) ? array_search($product, $landing['product_ids'], true) : false;
    if ($index !== false) {
        $name = modular_product($landing, $lang, $index)['name'];
        if ($name !== '') { $message .= ($message !== '' ? "\n" : '') . $name; }
    }
    return $message;
}

function modular_product(array $landing, string $lang, int $index): array {
    $current = $landing['locales'][$lang]['products'][$index];
    if ($current['name'] !== '') { return $current; }
    foreach (array_unique([$landing['default_locale'], ...array_keys(MODULAR_LOCALES)]) as $candidate) {
        $fallback = $landing['locales'][$candidate]['products'][$index];
        if ($fallback['name'] !== '') { return $fallback; }
    }
    return $current;
}

function validate_modular_content(mixed $input, array $state): array {
    if (!is_array($input)) { throw new ApiError('页面内容格式不正确。'); }
    $previous = $state['settings']['landing'];
    $input += $previous;
    $ids = $input['product_ids'];
    if (!is_array($ids) || !array_is_list($ids)) { throw new ApiError('产品清单格式不正确。'); }
    $previousPositions = array_flip($previous['product_ids']);
    foreach ($ids as $id) {
        if (!is_string($id) || (!isset($previousPositions[$id]) && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id))) {
            throw new ApiError('产品标识格式不正确。');
        }
    }
    $count = count($ids);
    if ($count !== count(array_unique($ids))) { throw new ApiError('产品标识不能重复。'); }
    $images = $input['product_images'] ?? null;
    if (!is_array($images) || !array_is_list($images) || count($images) !== $count) {
        throw new ApiError('产品图片清单格式不正确。');
    }
    foreach ($images as $image) {
        if (!is_string($image) || !in_array($image, $state['assets'], true) || managed_image_file($image) === null) {
            throw new ApiError('产品图片不存在，请从图片库重新选择。');
        }
    }
    $founded = text_field($input, 'founded', 0, 10);
    if ($founded !== '' && (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $founded, $date)
        || !checkdate((int)$date[2], (int)$date[3], (int)$date[1]))) {
        throw new ApiError('请填写有效的成立日期。');
    }
    $menu = $input['language_menu'];
    $locales = $input['locales'];
    $enabled = $input['enabled_locales'];
    if (!is_bool($menu) || !is_array($locales) || !is_array($enabled) || !array_is_list($enabled) || !$enabled) {
        throw new ApiError('请至少启用一种语言。');
    }
    $locales += $previous['locales'];
    if (array_diff_key($locales, MODULAR_LOCALES)) { throw new ApiError('包含不支持的语言。'); }
    foreach ($enabled as $code) {
        if (!is_string($code) || !isset($locales[$code])) { throw new ApiError('启用语言前请先准备该语言的文案。'); }
    }
    $default = modular_locale_code($input['default_locale'], $enabled);
    if ($default === null) { throw new ApiError('默认语言必须已启用。'); }
    $output = [
        'founded'=>$founded, 'moq'=>$input['moq'] === null ? null : int_field($input, 'moq', 1, 100000), 'product_images'=>$images, 'locales'=>[],
        'images'=>validate_hero_images($input['images'], $state['assets']), 'interval'=>int_field($input, 'interval', 2, 15),
        'language_menu'=>$menu, 'enabled_locales'=>array_values(array_unique($enabled)), 'default_locale'=>$default,
        'mobile_columns'=>int_field($input, 'mobile_columns', 1, 2), 'product_buttons'=>[],
        'product_ids'=>$ids, 'product_prices'=>[],
    ] + modular_contact_details($input);
    $prices = $input['product_prices'];
    if (!is_array($prices) || !array_is_list($prices) || count($prices) !== $count) {
        throw new ApiError('产品价格清单格式不正确。');
    }
    foreach ($prices as $price) {
        if (!is_array($price) || !is_bool($price['hidden'] ?? null)) { throw new ApiError('产品价格设置不正确。'); }
        $output['product_prices'][] = ['text'=>text_field($price, 'text', 0, 200), 'hidden'=>$price['hidden']];
    }
    $buttons = $input['product_buttons'];
    if (!is_array($buttons) || !array_is_list($buttons) || count($buttons) !== $count) {
        throw new ApiError('产品按钮清单格式不正确。');
    }
    foreach ($buttons as $button) {
        if (!is_array($button) || !in_array($button['mode'] ?? null, ['inherit','custom','hidden'], true)) {
            throw new ApiError('产品按钮设置不正确。');
        }
        $url = text_field($button, 'url', 0, 2048);
        if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
            throw new ApiError('产品自定义按钮链接须以 https:// 开头。');
        }
        $output['product_buttons'][] = ['mode'=>$button['mode'], 'url'=>$url];
    }
    foreach (MODULAR_LOCALES as $lang=>$label) {
        $local = $locales[$lang] ?? null;
        $products = $local['products'] ?? null;
        if (!is_array($local) || !is_array($products) || !array_is_list($products) || count($products) !== $count) {
            throw new ApiError($label . '产品内容不完整。');
        }
        $copy = ['products'=>[]];
        $local += ['brand'=>$previous['locales'][$lang]['brand'] ?? '', 'address'=>$previous['locales'][$lang]['address'] ?? ''];
        foreach (MODULAR_TEXT_FIELDS as $key=>[, $max]) {
            $required = in_array($lang, $enabled, true) && in_array($key, ['title','intro','whatsapp_label'], true);
            $copy[$key] = text_field($local, $key, $required ? 1 : 0, $max);
        }
        if (isset($previous['locales'][$lang]['ui'])) { $copy['ui'] = $previous['locales'][$lang]['ui']; }
        foreach ($products as $index=>$product) {
            if (!is_array($product)) { throw new ApiError($label . '产品内容格式不正确。'); }
            $fields = [];
            $previousIndex = $previousPositions[$ids[$index]] ?? null;
            $oldButton = $previousIndex === null ? '' : ($previous['locales'][$lang]['products'][$previousIndex]['button_text'] ?? '');
            $product += ['button_text'=>$oldButton];
            foreach (MODULAR_PRODUCT_FIELDS as $key=>[, $max]) { $fields[$key] = text_field($product, $key, 0, $max); }
            $copy['products'][] = $fields;
        }
        $output['locales'][$lang] = $copy;
    }
    foreach ($ids as $index=>$_) {
        if (modular_product($output, $default, $index)['name'] === '') {
            throw new ApiError('请至少为每个产品填写一种语言的名称。');
        }
    }
    return $output;
}
