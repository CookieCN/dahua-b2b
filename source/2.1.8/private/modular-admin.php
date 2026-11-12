<?php
declare(strict_types=1);

?>
<section class="form-section" id="modular-editor">
  <h2>首屏与公司介绍</h2>
  <div class="type-controls">
    <label class="field">成立日期<input id="modular-founded" type="date"></label>
    <label class="field">起订量<input id="modular-moq" type="number" min="1" max="100000"></label>
  </div>
  <label class="field">编辑语言<select id="modular-language"><?php foreach (MODULAR_LOCALES as $lang=>$label): ?>
        <option value="<?= esc($lang) ?>"><?= esc($label) ?></option>
      <?php endforeach; ?></select></label>
  <div data-modular-locale>
    <?php foreach (MODULAR_TEXT_FIELDS as $key=>[$field, $max]): ?>
    <label class="field"><?= esc($field) ?>
      <?php if ($max > 200): ?>
      <textarea data-modular-text="<?= esc($key) ?>" maxlength="<?= $max ?>" rows="3"></textarea>
      <?php else: ?>
      <input data-modular-text="<?= esc($key) ?>" maxlength="<?= $max ?>">
      <?php endif; ?>
    </label>
    <?php endforeach; ?>
  </div>
</section>
