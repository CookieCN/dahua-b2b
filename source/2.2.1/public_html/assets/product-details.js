'use strict';

function initProductDetails(initialHtml) {
  const source = $('details-source');
  const visual = $('details-visual');
  const modeButton = $('details-mode');
  const preview = $('preview-details-html');
  let draft = initialHtml;
  let editor;
  let editorHtml = '';
  let sourceMode = true;
  let previewTimer;
  let revision = 0;

  function showPreview(html) {
    // Only HTML returned by the server's article sanitizer is rendered here.
    preview.innerHTML = html;
    $('preview-details').hidden = html === '';
  }

  function edit(html) {
    if (draft === html) return;
    draft = html;
    changed();
    clearTimeout(previewTimer);
    const request = ++revision;
    if (html.trim() === '') {
      showPreview('');
      return;
    }
    previewTimer = setTimeout(async () => {
      try {
        const result = await api('preview_product_details', {html});
        if (request === revision) showPreview(result.html);
      } catch {
        if (request === revision) showPreview('');
      }
    }, 300);
  }

  function changeMode(showSource) {
    sourceMode = showSource;
    source.hidden = !showSource;
    visual.hidden = showSource;
    modeButton.textContent = showSource ? '可视化编辑' : 'HTML 源码';
    modeButton.setAttribute('aria-pressed', String(showSource));
  }

  source.value = initialHtml;
  source.addEventListener('input', () => edit(source.value));
  for (const event of ['click', 'auxclick']) {
    preview.addEventListener(event, event => {
      if (event.target.closest('a')) event.preventDefault();
    });
  }
  showPreview(initialHtml);

  if (window.SUNEDITOR) {
    const {blockStyle, list, blockquote, link, image, table} = window.SUNEDITOR.plugins;
    editor = window.SUNEDITOR.create($('details-editor'), {
      lang: window.SUNEDITOR_LANG?.zh_cn,
      value: initialHtml,
      height: '220px',
      placeholder: '写下产品规格、特点或使用说明…',
      editableFrameAttributes: {'aria-label': '产品详情可视化编辑'},
      statusbar_showPathLabel: false,
      plugins: [blockStyle, list, blockquote, link, image, table],
      blockStyle: {items: ['p', 'h3', 'h4', 'h5']},
      buttonList: [['undo', 'redo'], ['blockStyle', 'bold', 'italic', 'underline'], ['list', 'blockquote'], ['link', 'image', 'table']],
      image: {
        uploadUrl: '/admin/api.php?action=upload&target=details',
        uploadHeaders: {'X-CSRF-Token': state.csrf},
        acceptedFormats: 'image/jpeg,image/png,image/webp,image/gif',
        createUrlInput: false,
        useFormatType: false,
        canResize: false
      },
      events: {
        onload() {
          editorHtml = editor.$.html.get();
          modeButton.hidden = false;
          changeMode(draft !== initialHtml);
        },
        onChange() {
          if (sourceMode) return;
          const html = editor.$.html.get();
          if (html === editorHtml) return;
          editorHtml = html;
          edit(html);
        },
        onImageLoad({infoList}) {
          const paths = infoList.map(info => new URL(info.src, location.origin))
            .filter(url => url.origin === location.origin && /^\/uploads\/[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/.test(url.pathname))
            .map(url => url.pathname);
          const added = paths.filter(path => !state.assets.includes(path));
          if (!added.length) return;
          state.assets.push(...new Set(added));
          renderLibrary();
        },
        onImageUploadError() { return '上传失败。请使用 8MB 以内的 JPG、PNG、WebP 或 GIF 图片。'; }
      }
    });
    modeButton.onclick = () => {
      if (!sourceMode) {
        source.value = draft;
        changeMode(true);
        source.focus();
        return;
      }
      operation(async () => {
        const {html} = await api('preview_product_details', {html: draft});
        clearTimeout(previewTimer);
        revision++;
        editor.$.html.set(html);
        editorHtml = editor.$.html.get();
        draft = html;
        showPreview(html);
        changeMode(false);
      });
    };
  }

  return {
    value: () => draft,
    usesImage(path) {
      const fragment = document.createElement('template');
      fragment.innerHTML = draft;
      return [...fragment.content.querySelectorAll('img[src]')].some(img => img.getAttribute('src') === path);
    },
    published(html) {
      clearTimeout(previewTimer);
      revision++;
      draft = source.value = html;
      showPreview(html);
    }
  };
}
