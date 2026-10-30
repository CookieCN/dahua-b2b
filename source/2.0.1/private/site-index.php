<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
try {
    $s = read_state()['settings'];
    $dimensions = @getimagesize(PUBLIC_DIR . $s['images'][0]) ?: [760, 760];
} catch (Throwable $e) {
    error_log((string)$e);
    http_response_code(503);
    header('Retry-After: 300');
    exit('Site temporarily unavailable. Please try again shortly.');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#ffffff">
  <title><?= esc($s['title']) ?></title>
  <meta name="description" content="Factory direct supply, COA available, responsive support. For Research Use Only.">
  <link rel="stylesheet" href="/assets/site.css?v=<?= urlencode(CODE_VERSION) ?>">
<?php require __DIR__ . '/../private/pixels.php'; ?>

</head>
<body style="--title-size: <?= (int)$s['title_size'] ?>px; --title-align: <?= esc($s['title_align']) ?>; --title-weight: <?= (int)$s['title_weight'] ?>; --body-size: <?= (int)$s['body_size'] ?>px; --body-weight: <?= (int)$s['body_weight'] ?>">
  <?php if ($s['meta_enabled'] && $s['meta_pixel_id'] !== ''): ?>
  <noscript><img height="1" width="1" alt="" style="display:none" src="https://www.facebook.com/tr?id=<?= esc($s['meta_pixel_id']) ?>&amp;ev=PageView&amp;noscript=1"></noscript>
  <?php endif; ?>
  <main class="page">
    <div class="carousel" style="--hero-ratio: <?= (int)$dimensions[0] ?> / <?= (int)$dimensions[1] ?>" data-carousel data-interval="<?= (int)$s['interval'] ?>" aria-label="Product images" <?php if (count($s['images']) > 1): ?>role="region" aria-roledescription="carousel"<?php endif; ?>>
      <div class="carousel-stage">
      <?php foreach ($s['images'] as $i=>$image): ?>
      <img class="hero" src="<?= esc($image) ?>" alt="OCPEPTIDE product display <?= $i+1 ?>" <?= $i === 0 ? 'fetchpriority="high"' : 'hidden loading="lazy"' ?> data-slide>
      <?php endforeach; ?>
      </div>
      <?php if (count($s['images']) > 1): ?>
      <div class="carousel-controls"><?php foreach ($s['images'] as $i=>$_): ?><button type="button" class="slide-dot" data-slide-index="<?= $i ?>" aria-label="Show image <?= $i+1 ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>"></button><?php endforeach; ?></div>
      <?php endif; ?>
    </div>

    <section class="content">
      <h1><?= esc($s['title']) ?></h1>
      <p><?= esc($s['body']) ?></p>

      <div class="ruo">For Research Use Only.</div>

      <a class="wa <?= esc($s['button_class']) ?>" data-wa-contact
         href="/contact.php" <?= $s['button_id'] !== '' ? 'id="' . esc($s['button_id']) . '"' : '' ?>
         target="_blank"
         rel="noopener noreferrer"
         aria-label="<?= esc($s['button_text']) ?>">
        <svg viewBox="0 0 32 32" aria-hidden="true">
          <path d="M19.11 17.21c-.27-.14-1.6-.79-1.85-.88-.25-.09-.43-.14-.61.14-.18.27-.7.88-.86 1.06-.16.18-.32.2-.59.07-.27-.14-1.15-.42-2.19-1.35-.81-.72-1.36-1.61-1.52-1.88-.16-.27-.02-.42.12-.56.12-.12.27-.32.41-.47.14-.16.18-.27.27-.45.09-.18.05-.34-.02-.47-.07-.14-.61-1.47-.84-2.01-.22-.53-.45-.46-.61-.47h-.52c-.18 0-.47.07-.72.34-.25.27-.95.93-.95 2.26 0 1.33.97 2.62 1.11 2.8.14.18 1.91 2.92 4.63 4.1.65.28 1.15.45 1.55.58.65.21 1.24.18 1.71.11.52-.08 1.6-.65 1.83-1.28.23-.63.23-1.17.16-1.28-.07-.11-.25-.18-.52-.32z"/>
          <path d="M16.02 3C8.84 3 3 8.76 3 15.84c0 2.5.74 4.94 2.15 7.02L3 29l6.32-2.08a13.1 13.1 0 006.7 1.82C23.2 28.74 29 22.98 29 15.84 29 8.76 23.2 3 16.02 3zm0 23.55c-2.07 0-4.09-.56-5.84-1.63l-.42-.25-3.75 1.23 1.26-3.65-.27-.43a10.58 10.58 0 01-1.8-5.98c0-5.87 4.85-10.64 10.82-10.64 5.96 0 10.81 4.77 10.81 10.64 0 5.9-4.85 10.71-10.81 10.71z"/>
        </svg>
        <span data-wa-label><?= esc($s['button_text']) ?></span>
      </a>

      <?php if ($s['footer_text'] !== ''): ?><div class="small" data-footer-text><?= esc($s['footer_text']) ?></div><?php endif; ?>
    </section>
  </main>

<script type="application/json" data-site-config><?= json_encode(['numbers'=>$s['numbers'], 'message'=>$s['message'], 'pixels'=>array_intersect_key($s, pixel_defaults())], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script src="/assets/site.js?v=<?= urlencode(CODE_VERSION) ?>" defer></script>

</body>
</html>
