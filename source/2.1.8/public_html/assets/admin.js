'use strict';
const $ = id => document.getElementById(id);
let state, dirty = false, busy = false, authenticated = true;
let landingDraft, productUpload = null;
const settingsFields = ['title','body','footer_text','title_size','body_size','title_weight','body_weight','title_align','button_text','button_id','button_class','interval','tiktok_pixel_id','meta_pixel_id','google_ads_id','google_ads_label'];
const booleanFields = ['tiktok_enabled','tiktok_contact','meta_enabled','meta_contact','google_ads_enabled','google_ads_contact'];
const numericFields = ['title_size','body_size','title_weight','body_weight','interval'];
const panelCopy = {content:['页面内容','调整访客第一眼看到的图片与文字。'],languages:['多语种','选择前台显示的语言和默认语言。'],products:['产品设置','管理每款产品的图文、按钮和手机端布局。'],contact:['联系按钮','管理 WhatsApp 分流与 Contact 按钮。'],pixels:['广告像素','配置 TikTok、Facebook 和 Google Ads 的追踪。'],analytics:['数据魔方','页面访问、联系点击与号码分配统计。'],admins:['管理员','管理账号、角色，并查看登录与操作日志。'],password:['修改密码','修改你自己的登录密码。']};
function node(tag, text, className) {
  const el = document.createElement(tag);
  if (text !== undefined) el.textContent = text;
  if (className) el.className = className;
  return el;
}
function notify(text, error = false) {
  $('message-content').textContent = text;
  $('message').className = error ? 'dismissible-message error' : 'dismissible-message';
  $('message').hidden = false;
}
$('dismiss-message').onclick = () => { $('message').hidden = true; };
function updateSave() {
  $('brand-edit').disabled = !state || busy || !authenticated || !!state.admin.must_change_credentials;
  $('brand-fields').disabled = $('brand-edit').disabled;
  $('display_mode').disabled = $('brand-edit').disabled;
  $('save').disabled = !state || !dirty || busy || !authenticated;
  $('save-state').textContent = busy ? '正在处理…' : dirty ? '有未保存的修改' : '已保存';
}
function changed() { dirty = true; updateSave(); preview(); }
function applySiteName() {
  $('brand-name').textContent = state.settings.site_name;
  const chars = Array.from(state.settings.site_name.trim());
  $('brand-avatar').textContent = /^\p{Script=Han}$/u.test(chars[0] || '') ? chars[0] : chars.slice(0,2).join('');
  document.title = state.settings.site_name + ' · 网站管理';
}
function closeBrandEditor() {
  $('brand-form').hidden = true;
  $('brand-edit').setAttribute('aria-expanded','false');
  $('brand-edit').focus();
}
function initBrandEditor() {
  $('brand-edit').onclick = () => {
    if (!$('brand-form').hidden) return closeBrandEditor();
    $('site_name').value = state.settings.site_name;
    $('brand-error').hidden = true;
    $('brand-form').hidden = false;
    $('brand-edit').setAttribute('aria-expanded','true');
    $('site_name').focus(); $('site_name').select();
  };
  $('brand-cancel').onclick = closeBrandEditor;
  $('brand-form').addEventListener('keydown', event => {
    if (event.key === 'Escape' && !busy) { event.preventDefault(); closeBrandEditor(); }
  });
  $('brand-form').onsubmit = event => {
    event.preventDefault();
    operation(async () => {
      $('brand-error').hidden = true;
      try {
        const result = await api('save_brand',{site_name:$('site_name').value,version:state.version});
        state.settings.site_name = result.site_name; state.version = result.version;
        applySiteName(); closeBrandEditor(); notify('网站名称已保存。');
      } catch (error) {
        $('brand-error').textContent = error.message; $('brand-error').hidden = false;
        throw error;
      }
    });
  };
}
async function api(action, data, form = false) {
  const response = await fetch('/admin/api.php' + (action ? '?action=' + action : ''), {
    method: action ? 'POST' : 'GET', credentials:'same-origin', cache:'no-store',
    headers:action ? {'X-CSRF-Token':state.csrf,...(form ? {} : {'Content-Type':'application/json'})} : {},
    body:action ? (form ? data : JSON.stringify(data)) : undefined
  });
  let result;
  try { result = await response.json(); } catch { throw new Error('服务器返回异常，请检查 PHP 配置后重试。'); }
  if (!response.ok) {
    if (response.status === 401) {
      authenticated = false;
      $('editor').inert = true;
      $('save').disabled = true;
    }
    throw new Error(result.error || '请求失败，请重试。');
  }
  return result;
}
async function operation(fn) {
  if (busy || !authenticated) return;
  busy = true;
  $('editor').inert = true;
  updateSave();
  try { await fn(); } catch (e) { notify(e.message, true); }
  finally { busy = false; $('editor').inert = !authenticated; updateSave(); }
}
function settings() {
  const values = {...state.settings};
  for (const key of settingsFields) values[key] = numericFields.includes(key) ? Number($(key).value) : $(key).value;
  for (const key of booleanFields) values[key] = $(key).checked;
  values.message = $('message-text').value;
  values.numbers = [...document.querySelectorAll('.number-input')].map(input=>input.value.trim());
  if (values.numbers.length === 1 && values.numbers[0] === '') values.numbers = [];
  if (landingDraft) {
    values.landing = {...landingDraft, interval:values.interval};
    values.interval = state.settings.interval;
  }
  return values;
}
function initDisplayMode() {
  const control = $('display_mode');
  const showMode = mode => {
    control.checked = mode === 'multi';
    $('display-mode-value').textContent = control.checked ? '多产品展示' : '单品展示';
  };
  showMode(state.settings.display_mode);
  control.onchange = () => {
    const saved = state.settings.display_mode;
    const next = control.checked ? 'multi' : 'single';
    if (dirty) {
      showMode(saved);
      notify('有未保存的修改，请先保存再切换展示模式。', true);
      return;
    }
    showMode(next);
    operation(async () => {
      try {
        const payload = {...state.settings, display_mode:next};
        delete payload.landing;
        await api('save', {settings:payload, version:state.version});
        location.reload();
      } catch (error) {
        showMode(saved);
        throw error;
      }
    });
  };
}
function heroSettings() { return landingDraft ?? state.settings; }
function preview() {
  if (!state) return;
  const s = settings();
  if (!landingDraft) {
    $('preview-title').textContent = s.title;
    $('preview-body').textContent = s.body;
    document.querySelector('.preview-footer').textContent = s.footer_text;
    document.querySelector('.preview-footer').hidden = !s.footer_text.trim();
    document.querySelector('.preview-contact').textContent = '◉ \u00a0 ' + s.button_text;
    $('preview-title').style.fontSize = (s.title_size * .78) + 'px';
    $('preview-title').style.fontWeight = s.title_weight;
    $('preview-title').style.textAlign = s.title_align;
    $('preview-body').style.fontSize = (s.body_size * .78) + 'px';
    $('preview-body').style.fontWeight = s.body_weight;
  }
  $('selector-preview').textContent = [s.button_id ? '#' + s.button_id : '', ...s.button_class.trim().split(/\s+/).filter(Boolean).map(c=>'.'+c)].filter(Boolean).join('  ') || '[data-wa-contact]';
}
function renderProductImages() {
  if (!landingDraft) return;
  for (const select of document.querySelectorAll('[data-product-image]')) {
    const card = select.closest('details');
    const index = Number(card.dataset.modularProduct);
    const placeholder = node('option', '请选择产品图片');
    placeholder.value = '';
    select.replaceChildren(placeholder);
    state.assets.forEach((path, i) => {
      const option = node('option', '图片 ' + (i + 1) + ' · ' + path.split('/').pop().slice(0, 8));
      option.value = path;
      select.append(option);
    });
    select.value = landingDraft.product_images[index];
    const image = card.querySelector('summary img');
    if (select.value) image.src = select.value;
    else image.removeAttribute('src');
  }
}
function renderModularContent() {
  const lang = $('modular-language').value;
  const content = document.querySelector('[data-modular-locale]');
  content.dataset.modularLocale = lang;
  for (const input of content.querySelectorAll('[data-modular-text]')) {
    const key = input.dataset.modularText;
    input.value = landingDraft.locales[lang][key] ?? '';
    input.required = landingDraft.enabled_locales.includes(lang) && ['title','intro','whatsapp_label'].includes(key);
  }
  preview();
}
function renderProductText() {
  const products = landingDraft.locales[$('product-language').value].products;
  for (const card of document.querySelectorAll('[data-modular-product]')) {
    const product = products[Number(card.dataset.modularProduct)];
    card.querySelector('summary span').textContent = product.name || '产品 ' + (Number(card.dataset.modularProduct) + 1);
    for (const input of card.querySelectorAll('[data-product-field]')) {
      input.value = product[input.dataset.productField] ?? '';
    }
  }
}
function initLandingPreview() {
  const dialog = $('modular-preview-dialog');
  const frame = $('modular-preview-frame');
  const status = $('modular-preview-status');
  let request = 0;
  $('modular-preview-size').onchange = event => { frame.dataset.size = event.target.value; };
  dialog.addEventListener('close', () => { request++; });
  for (const button of document.querySelectorAll('[data-open-preview]')) {
    button.onclick = async () => {
      const id = ++request;
      dialog.showModal();
      frame.hidden = true;
      status.textContent = '正在生成预览…';
      try {
        const result = await api('preview_landing', {settings:settings(), version:state.version, lang:$(button.dataset.openPreview).value});
        if (id !== request || !dialog.open) return;
        frame.srcdoc = result.html;
        frame.hidden = false;
        status.textContent = '';
      } catch (error) {
        if (id === request && dialog.open) status.textContent = error.message;
      }
    };
  }
}
function renderLanguageSettings() {
  const select = $('default-language');
  select.replaceChildren();
  for (const input of document.querySelectorAll('[data-enabled-language]')) {
    input.checked = landingDraft.enabled_locales.includes(input.value);
    input.disabled = input.checked && landingDraft.enabled_locales.length === 1;
    if (input.checked) {
      const option = node('option', input.labels[0].textContent.trim());
      option.value = input.value;
      select.append(option);
    }
  }
  if (!landingDraft.enabled_locales.includes(landingDraft.default_locale)) {
    landingDraft.default_locale = landingDraft.enabled_locales[0];
  }
  select.value = landingDraft.default_locale;
}
function initModularEditor() {
  if (!$('modular-editor')) return;
  landingDraft = structuredClone(state.settings.landing);
  for (const key of ['contact_email','contact_phone']) {
    const input = $(key);
    input.value = landingDraft[key];
    input.oninput = () => { landingDraft[key] = input.value; changed(); };
  }
  panelCopy.content[1] = '管理首屏图片、各语种文案和公司介绍。';
  $('panel-description').textContent = panelCopy.content[1];
  for (const key of ['founded','moq']) {
    const input = $('modular-' + key);
    input.value = landingDraft[key];
    input.oninput = () => { landingDraft[key] = key === 'moq' ? (input.value === '' ? null : input.valueAsNumber) : input.value; changed(); };
  }
  $('modular-language').value = landingDraft.default_locale;
  $('product-language').value = landingDraft.default_locale;
  for (const input of document.querySelectorAll('[data-modular-text]')) {
    input.oninput = () => {
      landingDraft.locales[$('modular-language').value][input.dataset.modularText] = input.value;
      changed();
    };
  }
  $('modular-language').onchange = renderModularContent;
  $('product-language').onchange = renderProductText;
  $('add-product').onclick = () => {
    const id = crypto.randomUUID();
    landingDraft.product_ids.push(id);
    landingDraft.product_images.push('');
    landingDraft.product_prices.push({text:'',hidden:true});
    landingDraft.product_buttons.push({mode:'inherit',url:''});
    for (const copy of Object.values(landingDraft.locales)) {
      copy.products.push({name:'',spec:'',description:'',button_text:''});
    }
    renderProductCards(id);
    $('product-editor-grid').lastElementChild.querySelector('[data-product-field="name"]').focus();
    changed();
  };
  $('product-mobile-columns').value = String(landingDraft.mobile_columns);
  $('product-mobile-columns').onchange = event => { landingDraft.mobile_columns = Number(event.target.value); changed(); };
  $('language-menu').checked = landingDraft.language_menu;
  $('language-menu').onchange = event => { landingDraft.language_menu = event.target.checked; changed(); };
  $('default-language').onchange = event => { landingDraft.default_locale = event.target.value; changed(); };
  const languageInputs = [...document.querySelectorAll('[data-enabled-language]')];
  for (const input of languageInputs) {
    input.onchange = () => {
      landingDraft.enabled_locales = languageInputs.filter(field => field.checked).map(field => field.value);
      renderLanguageSettings();
      renderModularContent();
      changed();
    };
  }
  renderLanguageSettings();
  renderModularContent();
  renderProductCards();
  initLandingPreview();
}
function renderProductCards(openId) {
  const grid = $('product-editor-grid');
  const expanded = new Set([...grid.querySelectorAll('details[open]')].map(card => card.dataset.productId));
  grid.replaceChildren();
  $('product-empty').hidden = landingDraft.product_ids.length > 0;
  landingDraft.product_ids.forEach((id, index) => {
    const card = $('product-card-template').content.firstElementChild.cloneNode(true);
    card.dataset.modularProduct = index;
    card.dataset.productId = id;
    card.open = id === openId || expanded.has(id);
    grid.append(card);
    const image = card.querySelector('[data-product-image]');
    const mode = card.querySelector('[data-product-button]');
    const url = card.querySelector('[data-product-url]');
    const button = landingDraft.product_buttons[index];
    const showButtonFields = () => {
      for (const label of card.querySelectorAll('[data-custom-label], [data-custom-url]')) {
        label.hidden = button.mode !== 'custom';
      }
    };
    image.onchange = () => {
      landingDraft.product_images[index] = image.value;
      renderProductImages();
      changed();
    };
    mode.value = button.mode;
    url.value = button.url;
    mode.onchange = () => { button.mode = mode.value; showButtonFields(); changed(); };
    url.oninput = () => { button.url = url.value.trim(); changed(); };
    showButtonFields();
    const price = landingDraft.product_prices[index];
    const priceInput = card.querySelector('[data-product-price]');
    const hidden = card.querySelector('[data-product-price-hidden]');
    priceInput.value = price.text;
    hidden.checked = price.hidden;
    priceInput.oninput = () => { price.text = priceInput.value; changed(); };
    hidden.onchange = () => { price.hidden = hidden.checked; changed(); };
    card.querySelector('[data-delete-product]').onclick = () => {
      for (const field of ['product_ids','product_images','product_buttons','product_prices']) landingDraft[field].splice(index, 1);
      for (const copy of Object.values(landingDraft.locales)) copy.products.splice(index, 1);
      renderProductCards();
      const next = grid.children[Math.min(index, grid.children.length - 1)];
      (next?.querySelector('summary') ?? $('add-product')).focus();
      changed();
    };
    card.querySelector('[data-product-upload]').onclick = () => {
      productUpload = index;
      $('image-upload').multiple = false;
      $('image-upload').click();
    };
    for (const input of card.querySelectorAll('[data-product-field]')) {
      const field = input.dataset.productField;
      input.oninput = () => {
        landingDraft.locales[$('product-language').value].products[index][field] = input.value;
        if (field === 'name') card.querySelector('summary span').textContent = input.value || '产品 ' + (index + 1);
        changed();
      };
    }
  });
  renderProductImages();
  renderProductText();
}
function validateModularEditor() {
  if (!landingDraft) return;
  for (const input of document.querySelectorAll('#modular-editor input, #modular-editor textarea, #panel-products input, #panel-products textarea, #panel-products [data-product-image]')) {
    if (input.closest('label')?.hidden) continue;
    if (input.checkValidity() && (!input.required || input.value.trim())) continue;
    const product = input.closest('details');
    switchPanel(product ? 'products' : 'content');
    if (product) product.open = true;
    requestAnimationFrame(() => { input.focus(); input.reportValidity(); });
    throw new Error('请检查页面内容或产品卡片中的字段。');
  }
}
function deleteImage(path) {
  if (landingDraft?.product_images.includes(path)) return notify('这张图片正在用于产品，请先替换产品图片并保存。',true);
  const remaining = heroSettings().images.filter(image=>image!==path);
  if (!remaining.length) return notify('至少保留一张头图，请先上传或选择新图片，再删除旧图。',true);
  if (!confirm('彻底删除这张图片？它会立即从网站和图片库中删除，无法恢复。')) return;
  operation(async()=>{
    const result = await api('delete_image',{image:path,replacement:remaining[0],version:state.version});
    state.assets = result.assets; state.version = result.version;
    state.settings = result.settings;
    heroSettings().images = remaining;
    renderImages(); preview();
    const current = settings();
    dirty = Object.keys(result.settings).some(key=>JSON.stringify(current[key])!==JSON.stringify(result.settings[key]));
    notify('图片已彻底删除，网站已同步更新。');
  });
}
function renderImages() {
  const list = $('image-list'); list.replaceChildren();
  const images = heroSettings().images;
  images.forEach((path, index) => {
    const card = node('div', undefined, 'image-item');
    const img = node('img'); img.src = path; img.alt = '头图 ' + (index+1);
    const bar = node('div', undefined, 'image-item-bar');
    bar.append(node('span', '第 ' + (index+1) + ' 张'));
    const actions = node('div');
    for (const [label, delta] of [['←',-1],['→',1]]) {
      const button = node('button', label); button.type = 'button';
      button.setAttribute('aria-label', delta < 0 ? '前移图片' : '后移图片');
      button.disabled = index + delta < 0 || index + delta >= images.length;
      button.onclick = () => {
        [images[index], images[index+delta]] = [images[index+delta], images[index]];
        renderImages(); changed();
      };
      actions.append(button);
    }
    if (state.settings.landing || landingDraft) {
      const unselect = node('button','移出头图'); unselect.type = 'button';
      unselect.disabled = images.length === 1;
      unselect.title = '保留在图片库中';
      unselect.onclick = () => { images.splice(index, 1); renderImages(); changed(); };
      actions.append(unselect);
    }
    const remove = node('button','删除图片','remove'); remove.type = 'button';
    remove.disabled = images.length === 1;
    remove.title = remove.disabled ? '请先上传或选择新图片，再删除最后一张头图。' : '从网站、图片库和服务器彻底删除';
    remove.onclick = () => deleteImage(path);
    actions.append(remove); bar.append(actions); card.append(img,bar); list.append(card);
  });
  const count = images.length;
  $('image-mode').textContent = count > 1 ? count + ' 张 · 自动轮播' : count === 1 ? '1 张 · 静态展示' : '请添加至少 1 张图片';
  $('interval-field').hidden = count < 2;
  const hero = $('preview-hero');
  if (hero) {
    hero.replaceChildren();
    if (count) { const img = node('img'); img.src = images[0]; img.alt = '第一张头图预览'; hero.append(img); }
  }
  renderLibrary();
  renderProductImages();
}
function renderLibrary() {
  $('library').replaceChildren();
  const images = heroSettings().images;
  for (const path of state.assets) {
    const item = node('div',undefined,'library-item');
    const button = node('button'); button.type='button';
    button.disabled = images.includes(path);
    button.setAttribute('aria-label', '将图片加入头图');
    const img = node('img'); img.src=path; img.alt='已上传图片'; img.loading='lazy'; button.append(img,node('span',button.disabled?'已在轮播':'加入轮播'));
    button.onclick = () => {
      if (images.length >= 12) return notify('最多展示 12 张头图。', true);
      images.push(path); renderImages(); changed();
    };
    const remove = node('button','删除图片','library-delete'); remove.type='button';
    remove.disabled = images.length===1 && images.includes(path);
    remove.title = remove.disabled ? '请先上传或选择新图片，再删除最后一张头图。' : '彻底删除此图片';
    remove.onclick = () => deleteImage(path);
    item.append(button,remove); $('library').append(item);
  }
}
function renderNumbers(numbers) {
  if (numbers.length === 0) numbers = [''];
  $('number-list').replaceChildren();
  numbers.forEach((number,index) => {
    const row=node('div',undefined,'number-row');
    const input=node('input'); input.type='tel'; input.inputMode='tel'; input.className='number-input'; input.value=number; input.placeholder='请输入带国家区号的 WhatsApp 号码'; input.maxLength=30; input.setAttribute('aria-label','WhatsApp 号码 '+(index+1)); input.oninput=changed;
    const remove=node('button','删除','remove-number'); remove.disabled=numbers.length===1;
    remove.onclick=()=>{const next=settings().numbers;next.splice(index,1);renderNumbers(next);changed();};
    row.append(node('span',String(index+1).padStart(2,'0')),input,remove); $('number-list').append(row);
  });
  $('number-count').textContent=numbers.length+' / 10'; $('add-number').disabled=numbers.length>=10;
}
function switchPanel(name) {
  if(state.admin.must_change_credentials&&name!=='password'){notify('首次登录请先修改默认密码。',true);name='password';}
  for (const el of document.querySelectorAll('.panel')) el.hidden=el.id!=='panel-'+name;
  for (const el of document.querySelectorAll('[data-panel]')) {
    el.classList.toggle('active',el.dataset.panel===name);
    if (el.dataset.panel===name) el.setAttribute('aria-current','page'); else el.removeAttribute('aria-current');
  }
  $('panel-title').textContent=panelCopy[name][0]; $('panel-description').textContent=panelCopy[name][1];
  document.querySelector('.save-actions').hidden=['admins','password','analytics'].includes(name);
  if(name==='admins'&&state.admin.role==='super')operation(refreshUsers);
}
async function init() {
  document.querySelector('.domain').textContent = location.host;
  for (const key of ['title_weight','body_weight']) {
    for(let weight=100;weight<=900;weight+=100){const option=node('option',weight+({'400':' 常规','700':' 粗体','800':' 特粗'}[weight]||''));option.value=weight;$(key).append(option);}
  }
  state=await api();
  applySiteName();
  initBrandEditor();
  for (const key of settingsFields) $(key).value=state.settings[key];
  for (const key of booleanFields) $(key).checked=state.settings[key];
  initDisplayMode();
  initModularEditor();
  $('interval').value = heroSettings().interval;
  $('message-text').value=state.settings.message;
  $('role-label').textContent=state.admin.username+' · '+(state.admin.role==='super'?'超级管理员':'普通管理员');
  $('new-own-username').value=state.admin.username;
  $('first-login-notice').hidden=!state.admin.must_change_credentials;
  document.querySelectorAll('.nav-item').forEach(button=>{button.disabled=!!state.admin.must_change_credentials;});
  if(state.admin.role==='super'){$('admin-nav').hidden=false;$('update-nav').hidden=false;}
  initAccounts();
  renderImages();renderNumbers(state.settings.numbers);preview();updateSave();
  $('editor').inert=false;$('editor').setAttribute('aria-busy','false');
  for(const key of [...settingsFields,...booleanFields,'message-text']) $(key).addEventListener('input',changed);
  for(const el of document.querySelectorAll('[data-panel]'))el.onclick=()=>switchPanel(el.dataset.panel);
  $('show-library').onclick=()=>{$('library').hidden=!$('library').hidden;};
  $('upload-trigger').onclick=()=>{productUpload=null;$('image-upload').multiple=true;$('image-upload').click();};
  $('add-number').onclick=()=>{const numbers=settings().numbers;if(numbers.length<10){numbers.push('');renderNumbers(numbers);changed();$('number-list').lastElementChild.querySelector('input').focus();}};
  $('save').onclick=()=>operation(async()=>{
    validateModularEditor();
    const s=settings();
    if(heroSettings().images.length<1)throw new Error('请添加至少 1 张头图。');
    for(const key of settingsFields){if(!$(key).checkValidity()){$(key).reportValidity();throw new Error('请检查字号、字重和文字长度。');}}
    const payload = {...s};
    if (!landingDraft) delete payload.landing;
    const result=await api('save',{settings:payload,version:state.version});
    dirty=false;
    state.settings=s;state.version=result.version;applySiteName();notify('已保存，网站内容已生效。');
  });
  $('image-upload').onchange=event=>{
    const files=[...event.target.files];event.target.value='';
    const productIndex = productUpload;
    if(!files.length)return;
    if(productIndex === null && heroSettings().images.length+files.length>12)return notify('最多展示 12 张头图，请先移除部分图片。',true);
    operation(async()=>{
      let added=0;
      try {
        for(const file of files){
          if(file.size>8*1024*1024)throw new Error(file.name+' 超过 8MB，请压缩后重试。');
          const data=new FormData();data.append('image',file);
          const result=await api('upload',data,true);
          state.assets.push(result.image);
          if (productIndex === null) heroSettings().images.push(result.image);
          else landingDraft.product_images[productIndex] = result.image;
          changed();
          added++;renderImages();
        }
        notify(productIndex === null ? '已添加 ' + added + ' 张图片。点击“保存并生效”发布。' : '已替换产品图片。点击“保存并生效”发布。');
      } catch(e){throw new Error(e.message+(added?' 已成功添加 '+added+' 张，可保存或继续上传。':''));}
    });
  };
  $('logout').onclick=()=>operation(async()=>{if(dirty&&!confirm('有未保存修改，仍然退出？'))return;await api('logout',{});dirty=false;location.replace('/admin/');});
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
  if(state.admin.must_change_credentials){switchPanel('password');$('old-password').focus();}
}
init().catch(e=>{notify(e.message,true);$('save-state').textContent='载入失败，请刷新重试';});
