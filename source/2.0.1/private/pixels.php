<?php // Included only by the public homepage. Never include this in admin pages. ?>
<?php if ($s['tiktok_enabled'] && $s['tiktok_pixel_id'] !== ''): ?>
<!-- TikTok Pixel: one initialization and one page view per page load. -->
<script>
!function(w,d,t){
  w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];
  ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"];
  ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};
  for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);
  ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};
  ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;
    ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=r;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};
    n=d.createElement("script");n.type="text/javascript";n.async=true;n.src=r+"?sdkid="+e+"&lib="+t;
    e=d.getElementsByTagName("script")[0];e.parentNode.insertBefore(n,e);
  };
  ttq.load(<?= json_encode($s['tiktok_pixel_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
  ttq.page();
}(window,document,'ttq');
</script>
<?php endif; ?>
<?php if ($s['meta_enabled'] && $s['meta_pixel_id'] !== ''): ?>
<!-- Meta Pixel: base code, PageView and optional Contact tracking. -->
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=true;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=true;t.src=v;
s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init',<?= json_encode($s['meta_pixel_id'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
fbq('track','PageView');
</script>
<?php endif; ?>
