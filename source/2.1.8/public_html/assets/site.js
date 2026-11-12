'use strict';
(() => {
  const config = JSON.parse(document.querySelector('script[data-site-config]').textContent);
  const languagePicker = document.querySelector('[data-language-picker]');
  if (languagePicker) {
    document.addEventListener('click', event => {
      if (!languagePicker.contains(event.target)) languagePicker.open = false;
    });
    languagePicker.addEventListener('keydown', event => {
      if (event.key !== 'Escape' || !languagePicker.open) return;
      languagePicker.open = false;
      languagePicker.querySelector('summary').focus();
    });
  }
  // First-party analytics beacon: independent of pixels, carousel and the link
  // itself, and registered before the single-slide early return below. A
  // blocked or failed beacon must never break navigation; undercounting from
  // unreachable beacons is a documented limitation of the data cube.
  const sendBeacon = payload => {
    try {
      fetch('/', {method:'POST', credentials:'same-origin', cache:'no-store', keepalive:true,
                  headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)}).catch(() => {});
    } catch { /* Navigation stays independent of analytics. */ }
  };
  try {
    const utmKeys = {utm_source:'s', utm_medium:'m', utm_campaign:'c', utm_content:'n', utm_term:'x'};
    const params = new URLSearchParams(location.search);
    const utm = {};
    for (const [name, short] of Object.entries(utmKeys)) {
      const value = params.get(name);
      if (value) utm[short] = value.slice(0, 120);
    }
    // One id per page load: a retried beacon deduplicates server-side while
    // genuine reloads keep their own id and still count.
    const pageEventId = (crypto.randomUUID?.() || (Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)));
    sendBeacon({ev:'pv', eid:pageEventId, ref: document.referrer ? String(document.referrer).slice(0, 500) : '', utm});
  } catch { /* The page still renders without analytics. */ }
  const trackContact = event => {
    if (event.type === 'auxclick' && event.button !== 1) return;
    // Independent try blocks keep a blocked/broken vendor SDK from breaking contact.
    const pixels = config.pixels || {};
    try {
      if (pixels.tiktok_enabled && pixels.tiktok_contact && typeof window.ttq?.track === 'function') window.ttq.track('Contact');
    } catch { /* The WhatsApp link must still work if analytics fails. */ }
    try {
      if (pixels.meta_enabled && pixels.meta_contact && typeof window.fbq === 'function') window.fbq('track', 'Contact');
    } catch { /* The other platform and navigation remain independent. */ }
    try {
      if (pixels.google_ads_enabled && pixels.google_ads_contact && pixels.google_ads_label && typeof window.gtag === 'function') {
        window.gtag('event', 'conversion', {send_to: pixels.google_ads_id + '/' + pixels.google_ads_label});
      }
    } catch { /* A failed Google tag must not interrupt the contact link. */ }
    try {
      // A fresh id per real click: retries with the same id dedupe, distinct
      // clicks never merge.
      const eventId = (crypto.randomUUID?.() || (Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)));
      sendBeacon({ev:'contact', eid:eventId});
    } catch { /* Clicks remain navigable when analytics cannot report. */ }
  };
  for (const contact of document.querySelectorAll('[data-wa-contact]')) {
    contact.addEventListener('click', trackContact);
    contact.addEventListener('auxclick', trackContact);
  }
  const carousel = document.querySelector('[data-carousel]');
  if (!carousel) return;
  const slides = [...carousel.querySelectorAll('[data-slide]')];
  if (slides.length < 2) return;
  const dots = [...carousel.querySelectorAll('[data-slide-index]')];
  let index = 0, timer;
  function show(next) {
    index = (next + slides.length) % slides.length;
    slides.forEach((slide, i) => { slide.hidden = i !== index; });
    dots.forEach((dot, i) => dot.setAttribute('aria-current', String(i === index)));
  }
  function schedule() {
    clearInterval(timer);
    // Slides switch without animation; pointer, focus and motion preferences do
    // not silently disable automatic playback when there is no play control.
    if (!document.hidden) timer = setInterval(() => show(index + 1), Number(carousel.dataset.interval) * 1000);
  }
  function move(next) { show(next); schedule(); }
  dots.forEach((dot, i) => { dot.onclick = () => move(i); });
  carousel.addEventListener('keydown', event => {
    if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') { event.preventDefault(); move(index + (event.key === 'ArrowRight' ? 1 : -1)); }
  });
  document.addEventListener('visibilitychange', schedule);
  let touchX;
  carousel.addEventListener('touchstart', event => { touchX = event.changedTouches[0].clientX; }, {passive:true});
  carousel.addEventListener('touchend', event => {
    if (touchX === undefined) return;
    const distance = event.changedTouches[0].clientX - touchX;
    if (Math.abs(distance) > 45) move(index + (distance < 0 ? 1 : -1));
    touchX = undefined;
  }, {passive:true});
  schedule();
})();
