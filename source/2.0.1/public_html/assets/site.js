'use strict';
(() => {
  const config = JSON.parse(document.querySelector('script[data-site-config]').textContent);
  const contact = document.querySelector('[data-wa-contact]');
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
  };
  contact.addEventListener('click', trackContact);
  contact.addEventListener('auxclick', trackContact);
  const carousel = document.querySelector('[data-carousel]');
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
