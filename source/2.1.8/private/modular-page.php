<?php
declare(strict_types=1);

$landing = $s['landing'];
$labels = MODULAR_LOCALES;
$enabled = $landing['enabled_locales'];
$preview = isset($previewLang);
$campaign = array_filter(
    array_intersect_key($_GET, array_flip(['utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id','gclid','gbraid','wbraid','fbclid','ttclid'])),
    static fn($value): bool => is_string($value) && strlen($value) <= 512
);
if (!$preview) {
    header('Vary: Accept-Language, Cookie', false);
    $choice = $_GET['choose_lang'] ?? null;
    $selected = modular_locale_code($choice, $enabled);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($choice === 'auto' || $selected !== null)) {
        setcookie(MODULAR_LOCALE_COOKIE, $selected ?? '', [
            'expires'=>$selected === null ? time() - 3600 : time() + 180 * 86400,
            'path'=>'/', 'secure'=>secure_cookie(), 'httponly'=>true, 'samesite'=>'Lax',
        ]);
        $query = http_build_query(($selected === null ? [] : ['lang'=>$selected]) + $campaign, '', '&', PHP_QUERY_RFC3986);
        header('Location: /' . ($query === '' ? '' : '?' . $query), true, 303);
        return;
    }
}
$lang = $preview ? $previewLang : modular_locale($landing, $_GET, $_COOKIE[MODULAR_LOCALE_COOKIE] ?? null, $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
if (!$preview) { header('Content-Language: ' . $lang); }
$copy = $landing['locales'][$lang];
$hasProducts = $landing['product_ids'] !== [];
$ui = match ($lang) {
    'en' => [
        'nav'=>'Main navigation', 'languages'=>'Language', 'auto'=>'Use browser language',
        'products'=>'Products', 'about'=>'About us', 'quote'=>'Get a quote',
        'product_intro'=>'', 'inquire'=>'Ask about this product', 'custom'=>'', 'features'=>'', 'benefits'=>[],
        'founded'=>'Founded', 'moq'=>'Minimum order', 'unit'=>'unit(s)', 'processing'=>'Manufacturing options',
        'contact'=>'Contact us', 'contact_intro'=>'Tell us what you are looking for and the quantity you need.',
        'email'=>'Email', 'phone'=>'Mobile / WeChat',
        'unavailable'=>'WhatsApp is currently unavailable.',
        'images'=>'Product images', 'show'=>'Show image',
    ],
    'zh' => [
        'nav'=>'主导航', 'languages'=>'语言', 'auto'=>'跟随浏览器',
        'products'=>'产品', 'about'=>'公司介绍', 'quote'=>'获取报价',
        'product_intro'=>'', 'inquire'=>'咨询此款', 'custom'=>'', 'features'=>'', 'benefits'=>[],
        'founded'=>'成立时间', 'moq'=>'起订量', 'unit'=>'个', 'processing'=>'加工方式',
        'contact'=>'联系我们', 'contact_intro'=>'请告诉我们您需要的产品和数量。',
        'email'=>'邮箱', 'phone'=>'手机／微信',
        'unavailable'=>'WhatsApp 联系暂不可用。',
        'images'=>'产品图片', 'show'=>'显示图片',
    ],
    default => [
        'nav'=>'Điều hướng chính', 'languages'=>'Ngôn ngữ', 'auto'=>'Theo ngôn ngữ trình duyệt',
        'products'=>'Sản phẩm', 'about'=>'Giới thiệu công ty', 'quote'=>'Nhận báo giá',
        'product_intro'=>'', 'inquire'=>'Hỏi về sản phẩm này', 'custom'=>'', 'features'=>'', 'benefits'=>[],
        'founded'=>'Ngày thành lập', 'moq'=>'Đơn hàng tối thiểu', 'unit'=>'căn', 'processing'=>'Hình thức gia công',
        'contact'=>'Liên hệ với chúng tôi', 'contact_intro'=>'Hãy cho chúng tôi biết sản phẩm và số lượng bạn cần.',
        'email'=>'Email', 'phone'=>'Điện thoại / WeChat',
        'unavailable'=>'Hiện chưa thể liên hệ qua WhatsApp.',
        'images'=>'Hình ảnh sản phẩm', 'show'=>'Xem ảnh',
    ],
};
$ui = array_replace($ui, $copy['ui'] ?? []);
$brand = ($copy['brand'] ?? '') !== '' ? $copy['brand'] : $s['site_name'];
$address = $copy['address'] ?? '';
$hasFacts = $landing['founded'] !== '' || $landing['moq'] !== null || $copy['processing'] !== '';
$hasCompany = $copy['company'] !== '' || $hasFacts || $ui['benefits'] !== [];
$displayDate = $lang === 'vi' && $landing['founded'] !== '' ? implode('/', array_reverse(explode('-', $landing['founded']))) : $landing['founded'];
$hasContact = $s['numbers'] !== [];
$email = $landing['contact_email'];
$phone = $landing['contact_phone'];
$emailAt = strrpos($email, '@');
$mailHref = $emailAt === false ? '' : 'mailto:' . rawurlencode(substr($email, 0, $emailAt)) . substr($email, $emailAt);
$temporaryHost = preg_match('/\.hostingersite\.com(?::[0-9]+)?$/iD', $_SERVER['HTTP_HOST'] ?? '') === 1;
?>
<!doctype html>
<html lang="<?= esc($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#19392e">
  <?php if ($temporaryHost): ?><meta name="robots" content="noindex,follow"><?php endif; ?>
  <title><?= esc($copy['title'] . ' | ' . $brand) ?></title>
  <meta name="description" content="<?= esc($copy['intro']) ?>">
  <link rel="stylesheet" href="/assets/modular.css?v=<?= urlencode(CODE_VERSION) ?>">
  <?php if (!$preview) { require __DIR__ . '/pixels.php'; } ?>
</head>
<body>
  <?php if (!$preview && $s['meta_enabled'] && $s['meta_pixel_id'] !== ''): ?>
  <noscript><img width="1" height="1" alt="" style="display:none" src="https://www.facebook.com/tr?id=<?= esc($s['meta_pixel_id']) ?>&amp;ev=PageView&amp;noscript=1"></noscript>
  <?php endif; ?>
  <header class="site-header">
    <div class="wrap nav">
      <a class="brand" href="<?= $preview ? 'about:srcdoc' : '' ?>#home"><?= esc($brand) ?></a>
      <nav class="main-nav" aria-label="<?= esc($ui['nav']) ?>">
        <?php if ($hasProducts): ?><a href="<?= $preview ? 'about:srcdoc' : '' ?>#products"><?= esc($ui['products']) ?></a><?php endif; ?>
        <?php if ($hasCompany): ?><a href="<?= $preview ? 'about:srcdoc' : '' ?>#about"><?= esc($ui['about']) ?></a><?php endif; ?>
      </nav>
      <?php if ($landing['language_menu'] && count($enabled) > 1): ?>
      <details class="language-picker" data-language-picker>
        <summary aria-label="<?= esc($ui['languages'] . ' · ' . $labels[$lang]) ?>"><span lang="<?= esc($lang) ?>"><?= esc($labels[$lang]) ?></span></summary>
        <nav aria-label="<?= esc($ui['languages']) ?>">
          <?php foreach ($enabled as $code): ?>
          <a <?php if (!$preview): ?>href="/?<?= esc(http_build_query(['choose_lang'=>$code] + $campaign, '', '&', PHP_QUERY_RFC3986)) ?>"<?php endif; ?> hreflang="<?= esc($code) ?>" lang="<?= esc($code) ?>" <?= $lang === $code ? 'aria-current="true"' : '' ?>><?= esc($labels[$code]) ?></a>
          <?php endforeach; ?>
          <a class="language-auto" <?php if (!$preview): ?>href="/?<?= esc(http_build_query(['choose_lang'=>'auto'] + $campaign, '', '&', PHP_QUERY_RFC3986)) ?>"<?php endif; ?>><?= esc($ui['auto']) ?></a>
        </nav>
      </details>
      <?php endif; ?>
    </div>
  </header>
  <main id="home">
    <section class="hero">
      <div class="hero-visual" data-carousel data-interval="<?= (int)$landing['interval'] ?>" aria-label="<?= esc($ui['images']) ?>" role="region">
        <?php foreach ($landing['images'] as $index=>$image): ?>
        <img src="<?= esc($image) ?>" alt="" <?= $index === 0 ? 'fetchpriority="high"' : 'hidden loading="lazy"' ?> data-slide>
        <?php endforeach; ?>
        <?php if (count($landing['images']) > 1): ?>
        <div class="hero-dots">
          <?php foreach ($landing['images'] as $index=>$_): ?>
          <button type="button" data-slide-index="<?= $index ?>" aria-label="<?= esc($ui['show'] . ' ' . ($index + 1)) ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>"></button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="wrap hero-copy">
        <h1><?= esc($copy['title']) ?></h1>
        <p><?= esc($copy['intro']) ?></p>
        <div class="hero-actions">
          <a class="button" href="<?= $preview ? 'about:srcdoc' : '' ?>#contact"><?= esc($ui['quote']) ?></a>
          <?php if ($hasProducts): ?><a class="button outline" href="<?= $preview ? 'about:srcdoc' : '' ?>#products"><?= esc($ui['products']) ?></a><?php endif; ?>
        </div>
      </div>
    </section>
    <?php if ($hasProducts): ?>
    <section class="block" id="products">
      <div class="wrap">
        <div class="section-head">
          <h2><?= esc($ui['products']) ?></h2>
          <?php if ($ui['product_intro'] !== ''): ?><p><?= esc($ui['product_intro']) ?></p><?php endif; ?>
        </div>
        <div class="product-grid" data-mobile-columns="<?= (int)$landing['mobile_columns'] ?>">
        <?php foreach ($landing['product_ids'] as $index=>$id):
            $product = modular_product($landing, $lang, $index);
            $price = $landing['product_prices'][$index];
            $button = $landing['product_buttons'][$index];
            $external = $button['mode'] === 'custom' && $button['url'] !== '';
            $buttonText = $external ? $ui['inquire'] : $copy['whatsapp_label'];
            $customLabel = $copy['products'][$index]['button_text'] ?? '';
            if ($button['mode'] === 'custom' && $customLabel !== '') { $buttonText = $customLabel; }
            $link = $external ? $button['url'] : '/contact.php?' . http_build_query(['lang'=>$lang, 'product'=>$id]);
        ?>
          <article class="card">
            <img class="product-image" src="<?= esc($landing['product_images'][$index]) ?>" alt="<?= esc($product['name']) ?>" loading="lazy" decoding="async">
            <div class="card-body">
              <?php if ($ui['custom'] !== ''): ?><span class="product-tag"><?= esc($ui['custom']) ?></span><?php endif; ?>
              <h3><?= esc($product['name']) ?></h3>
              <?php if ($product['spec'] !== ''): ?><p class="spec"><?= esc($product['spec']) ?></p><?php endif; ?>
              <?php if ($product['description'] !== ''): ?><p><?= esc($product['description']) ?></p><?php endif; ?>
              <?php if (!$price['hidden'] && $price['text'] !== ''): ?><p class="product-price"><?= esc($price['text']) ?></p><?php endif; ?>
              <?php if ($button['mode'] !== 'hidden' && ($external || $hasContact)): ?>
              <a class="product-link <?= $external ? '' : esc($s['button_class']) ?>" <?= $external || $preview ? '' : 'data-wa-contact' ?> <?php if (!$preview): ?>target="_blank" rel="noopener noreferrer" href="<?= esc($link) ?>"<?php endif; ?>><?= esc($buttonText) ?> <span aria-hidden="true">→</span></a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
        </div>
      </div>
    </section>
    <?php endif; ?>
    <?php if ($hasCompany): ?>
    <section class="block about" id="about">
      <div class="wrap">
        <div class="company">
          <div>
            <h2><?= esc($ui['about']) ?></h2>
            <?php if ($copy['company'] !== ''): ?><p><?= esc($copy['company']) ?></p><?php endif; ?>
          </div>
          <?php if ($hasFacts): ?><dl class="company-facts">
            <?php if ($landing['founded'] !== ''): ?><div><dt><?= esc($ui['founded']) ?></dt><dd><time datetime="<?= esc($landing['founded']) ?>"><?= esc($displayDate) ?></time></dd></div><?php endif; ?>
            <?php if ($landing['moq'] !== null): ?><div><dt><?= esc($ui['moq']) ?></dt><dd><?= (int)$landing['moq'] ?> <?= esc($ui['unit']) ?></dd></div><?php endif; ?>
            <?php if ($copy['processing'] !== ''): ?><div><dt><?= esc($ui['processing']) ?></dt><dd><?= esc($copy['processing']) ?></dd></div><?php endif; ?>
          </dl><?php endif; ?>
        </div>
        <?php if ($ui['benefits'] !== []): ?>
        <h3 class="benefits-title"><?= esc($ui['features']) ?></h3>
        <div class="benefits">
          <?php foreach ($ui['benefits'] as [$heading, $description]): ?>
          <div><h4><?= esc($heading) ?></h4><p><?= esc($description) ?></p></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
    <section class="block contact" id="contact">
      <div class="wrap">
        <h2><?= esc($ui['contact']) ?></h2>
        <p><?= esc($ui['contact_intro']) ?></p>
        <?php if ($hasContact): ?>
        <a class="button <?= esc($s['button_class']) ?>" <?php if (!$preview): ?>data-wa-contact <?= $s['button_id'] !== '' ? 'id="' . esc($s['button_id']) . '"' : '' ?> target="_blank" rel="noopener noreferrer" href="/contact.php?lang=<?= esc($lang) ?>"<?php endif; ?>><?= esc($copy['whatsapp_label']) ?></a>
        <?php elseif ($email === '' && $phone === ''): ?>
        <p><?= esc($ui['unavailable']) ?></p>
        <?php endif; ?>
        <?php if ($email !== '' || $phone !== ''): ?>
        <dl class="contact-details">
          <?php if ($email !== ''): ?>
          <div><dt><?= esc($ui['email']) ?></dt><dd><a <?php if (!$preview): ?>href="<?= esc($mailHref) ?>"<?php endif; ?>><?= esc($email) ?></a></dd></div>
          <?php endif; ?>
          <?php if ($phone !== ''): ?>
          <div><dt><?= esc($ui['phone']) ?></dt><dd><a <?php if (!$preview): ?>href="tel:<?= esc($phone) ?>"<?php endif; ?>><?= esc($phone) ?></a></dd></div>
          <?php endif; ?>
        </dl>
        <?php endif; ?>
      </div>
    </section>
  </main>
  <footer class="site-footer"><div class="wrap footer-row"><strong><?= esc($brand) ?></strong><?php if ($address !== ''): ?><span><?= esc($address) ?></span><?php endif; ?></div></footer>
  <?php if (!$preview): ?>
  <script type="application/json" data-site-config><?= json_encode(['pixels'=>array_intersect_key($s, pixel_defaults())], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
  <script src="/assets/site.js?v=<?= urlencode(CODE_VERSION) ?>" defer></script>
  <?php endif; ?>
</body>
</html>
