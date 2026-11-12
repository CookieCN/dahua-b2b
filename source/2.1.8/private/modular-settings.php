<?php
declare(strict_types=1);
?>
<section id="panel-languages" class="panel" hidden>
  <section class="form-section" id="language-settings">
    <h2>前台显示</h2>
    <label class="pixel-toggle"><input type="checkbox" id="language-menu">显示语言下拉菜单</label>
    <p class="help">浏览器会匹配已启用的语种。启用前请在「页面内容」填写该语种的标题、正文和 WhatsApp 按钮文案。</p>
    <fieldset class="language-choices"><legend>启用语言</legend>
      <?php foreach (MODULAR_LOCALES as $lang=>$label): ?>
      <label class="pixel-toggle"><input type="checkbox" value="<?= esc($lang) ?>" data-enabled-language><?= esc($label) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <label class="field">默认语言<select id="default-language"></select></label>
    <p class="help">访客浏览器未匹配时，显示这里选定的语言。</p>
  </section>
</section>
<section id="panel-products" class="panel" hidden>
  <section class="form-section">
    <div class="section-heading"><h2>产品卡片</h2><div class="product-actions"><button class="secondary" data-open-preview="product-language" type="button">预览当前修改</button><button id="add-product" class="primary" type="button">新增产品</button></div></div>
    <div class="type-controls product-controls">
      <label class="field">编辑语言<select id="product-language"><?php foreach (MODULAR_LOCALES as $lang=>$label): ?>
        <option value="<?= esc($lang) ?>"><?= esc($label) ?></option>
      <?php endforeach; ?></select></label>
      <label class="field">手机端排列<select id="product-mobile-columns"><option value="1">一行 1 个</option><option value="2">一行 2 个</option></select></label>
    </div>
    <p class="help">每个产品需选图并填写至少一种语言的名称。没有对应语种文案时，优先显示默认语言的产品内容。</p>
  </section>
  <p id="product-empty" class="help" hidden>暂无产品。点击「新增产品」添加卡片。</p>
  <div class="product-editor-grid" id="product-editor-grid"></div>
  <template id="product-card-template">
    <details class="form-section modular-product" data-modular-product>
      <summary><img alt="" loading="lazy"><span></span></summary>
      <label class="field">产品图片<select data-product-image required></select></label>
      <button class="secondary product-upload" data-product-upload type="button">上传并替换图片</button>
      <?php foreach (MODULAR_PRODUCT_FIELDS as $key=>[$field, $max]): ?>
      <label class="field" <?= $key === 'button_text' ? 'data-custom-label hidden' : '' ?>><?= esc($field) ?>
        <?php if ($max > 200): ?>
        <textarea data-product-field="<?= esc($key) ?>" maxlength="<?= $max ?>" rows="2"></textarea>
        <?php else: ?>
        <input data-product-field="<?= esc($key) ?>" maxlength="<?= $max ?>" <?= $key === 'button_text' ? 'placeholder="留空使用默认文案"' : '' ?>>
        <?php endif; ?>
      </label>
      <?php endforeach; ?>
      <label class="field">价格<input data-product-price type="text" maxlength="200" placeholder="例如 USD 3,500 起"></label>
      <label class="pixel-toggle"><input type="checkbox" data-product-price-hidden>隐藏价格</label>
      <label class="field">按钮模式<select data-product-button>
        <option value="inherit">使用联系按钮设置</option>
        <option value="custom">自定义按钮</option>
        <option value="hidden">隐藏按钮</option>
      </select></label>
      <label class="field" data-custom-url hidden>自定义链接<input data-product-url type="url" maxlength="2048" placeholder="留空使用 WhatsApp 联系设置"></label>
      <button class="danger product-delete" data-delete-product type="button">删除产品</button>
    </details>
  </template>
</section>
