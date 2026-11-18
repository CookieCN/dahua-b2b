'use strict';
/* Data cube panel: fetches the authenticated report API and renders metric
   cards, trends and dimension tables. Standalone on purpose — it shares the
   admin design system but does not depend on admin.js state. */
(() => {
  const $ = id => document.getElementById(id);
  const render = {
    range: $('analytics-range'),
    custom: $('analytics-custom'), from: $('analytics-from'), to: $('analytics-to'), customHelp: $('analytics-custom-help'),
    csv: $('analytics-csv'), meta: $('analytics-meta'), status: $('analytics-status'), error: $('analytics-error'),
    empty: $('analytics-empty'), content: $('analytics-content'), metrics: $('analytics-metrics'),
    trend: $('analytics-trend'), sources: $('analytics-sources'), campaigns: $('analytics-campaigns'),
    devices: $('analytics-devices'), browsers: $('analytics-browsers'), os: $('analytics-os'), numbers: $('analytics-numbers'),
    trendNote: $('analytics-trend-note'), trendDetail: $('analytics-trend-detail'), form: $('analytics-filter'),
  };
  if (!render.range || !render.metrics) return;

  const text = (value) => document.createTextNode(String(value ?? ''));
  const cell = (value, className) => {
    const td = document.createElement('td');
    td.append(text(value));
    if (className) td.className = className;
    return td;
  };
  const head = row => { const tr = document.createElement('tr'); for (const label of row) { const th = document.createElement('th'); th.scope = 'col'; th.append(text(label)); tr.append(th); } return tr; };
  const fill = (table, columns, rows, rowOf) => {
    const thead = document.createElement('thead'); thead.append(head(columns));
    const tbody = document.createElement('tbody');
    if (!rows.length) {
      const tr = document.createElement('tr');
      const td = document.createElement('td');
      td.colSpan = columns.length; td.className = 'table-empty';
      td.append(text('暂无数据'));
      tr.append(td); tbody.append(tr);
    } else {
      for (const row of rows) { const tr = document.createElement('tr'); for (const value of rowOf(row)) { tr.append(value); } tbody.append(tr); }
    }
    table.replaceChildren(thead, tbody);
  };
  const rateText = rate => rate === null || rate === undefined ? '—' : rate + '%';
  // Deltas are suppressed whenever any period was truncated: an incomplete
  // basis must not produce a made-up percentage.
  const deltaFor = reliable => (current, previous) => {
    if (!reliable) return '环比不可用（历史不完整）';
    if (!Number.isFinite(previous) || previous <= 0) return '上期 —';
    const change = Math.round((current - previous) * 1000 / previous) / 10;
    return '上期 ' + previous + ' · ' + (change >= 0 ? '+' : '') + change + '%';
  };

  function metricCard(label, valueText, subText) {
    const card = document.createElement('div'); card.className = 'metric-card';
    const labelNode = document.createElement('span'); labelNode.className = 'metric-label'; labelNode.append(text(label));
    const valueNode = document.createElement('strong'); valueNode.className = 'metric-value'; valueNode.append(text(valueText));
    const sub = document.createElement('small'); sub.className = 'metric-sub'; sub.append(text(subText));
    card.append(labelNode, valueNode, sub);
    return card;
  }

  const trendSeries = [
    ['pv', '页面浏览 PV'], ['uv', '独立访客 UV'],
    ['clicks', 'WhatsApp 点击'], ['allocations', '号码分配'],
  ];
  const axisNumber = new Intl.NumberFormat('zh-CN', { notation: 'compact', maximumFractionDigits: 1 });
  const svgNode = (tag, attributes, label) => {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [name, value] of Object.entries(attributes)) element.setAttribute(name, value);
    if (label !== undefined) element.textContent = label;
    return element;
  };
  const plot = render.trend.parentElement;
  let trendRows = [], activePoint = 0, plotWidth = 0, plotX, cursor;

  function showTrendPoint(index) {
    const row = trendRows[index];
    if (!row) return;
    activePoint = index;
    cursor.setAttribute('transform', 'translate(' + plotX(index) + ' 0)');
    const values = trendSeries.map(([key, label]) => label + ' ' + (row[key] ?? '—'));
    render.trendDetail.textContent = row.label + ' · ' + (row.pv === null ? '数据未覆盖' : values.join(' · '));
  }

  function drawTrend() {
    if (!trendRows.length || !plot.clientWidth) return;
    const width = plotWidth = plot.clientWidth;
    const height = width < 480 ? 190 : 242;
    const left = 39, right = width - 21, top = 12, bottom = height - 30;
    const rows = trendRows;
    const max = Math.max(0, ...rows.flatMap(row => trendSeries.map(([key]) => row[key] ?? 0)));
    const step = Math.max(1, Math.ceil(max / 4));
    const y = value => bottom - value * (bottom - top) / (step * 4);
    plotX = index => rows.length === 1 ? (left + right) / 2 : left + index * (right - left) / (rows.length - 1);

    const svg = render.trend;
    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
    svg.setAttribute('width', width);
    svg.setAttribute('height', height);
    const drawing = document.createDocumentFragment();
    for (let tick = 0; tick <= 4; tick++) {
      const value = tick * step;
      const level = y(value);
      drawing.append(
        svgNode('line', { x1: left, x2: right, y1: level, y2: level, class: 'trend-grid' }),
        svgNode('text', { x: left - 7, y: level + 4, 'text-anchor': 'end', class: 'trend-axis' }, axisNumber.format(value)),
      );
    }
    const tickCount = Math.min(rows.length, width < 480 ? 4 : 7);
    for (let tick = 0; tick < tickCount; tick++) {
      const index = Math.round(tick * (rows.length - 1) / Math.max(1, tickCount - 1));
      const label = rows[index].label;
      drawing.append(svgNode('text', {
        x: plotX(index), y: bottom + 19, 'text-anchor': 'middle', class: 'trend-axis',
      }, label.length > 10 ? label.slice(11) : label.slice(5)));
    }
    for (const [key] of trendSeries) {
      let path = '', continues = false;
      rows.forEach((row, index) => {
        if (row[key] === null) { continues = false; return; }
        const point = plotX(index).toFixed(2) + ',' + y(row[key]).toFixed(2);
        path += continues ? 'L' + point : 'M' + point + 'h0';
        continues = true;
      });
      drawing.append(svgNode('path', { d: path, class: 'trend-line trend-' + key }));
    }
    cursor = svgNode('line', { x1: 0, x2: 0, y1: top, y2: bottom, class: 'trend-cursor' });
    drawing.append(cursor);
    svg.replaceChildren(drawing);
    showTrendPoint(activePoint);
  }

  new ResizeObserver(() => {
    if (plot.clientWidth !== plotWidth) drawTrend();
  }).observe(plot);

  function selectTrendPoint(event) {
    if (!trendRows.length || !cursor) return;
    const bounds = render.trend.getBoundingClientRect();
    const x = (event.clientX - bounds.left) * plotWidth / bounds.width;
    const span = plotX(trendRows.length - 1) - plotX(0);
    const index = span ? Math.round((x - plotX(0)) / span * (trendRows.length - 1)) : 0;
    const selected = Math.max(0, Math.min(trendRows.length - 1, index));
    if (selected !== activePoint) showTrendPoint(selected);
  }
  render.trend.addEventListener('pointermove', selectTrendPoint);
  render.trend.addEventListener('pointerdown', selectTrendPoint);
  render.trend.addEventListener('keydown', event => {
    if (!trendRows.length || !cursor) return;
    const last = trendRows.length - 1;
    const next = { ArrowLeft: Math.max(0, activePoint - 1), ArrowRight: Math.min(last, activePoint + 1), Home: 0, End: last }[event.key];
    if (next === undefined) return;
    event.preventDefault();
    showTrendPoint(next);
  });

  function renderReport(report) {
    const totals = report.totals || {};
    const previous = (report.previous && report.previous.totals) || {};
    const meta = report.meta || {};
    render.meta.textContent = '时区 ' + (meta.timezone || 'Asia/Shanghai') + ' · 范围 ' + (meta.range_label || '')
      + '（' + (meta.from || '') + ' ~ ' + (meta.to || '') + '）'
      + (meta.since ? ' · 开始采集 ' + meta.since : '') + ' · 更新于 ' + (meta.updated_at || '');
    const warnings = [];
    const status = report.status || {};
    if (status.unavailable) { warnings.push('统计存储暂时不可读，以下为空数据；页面功能不受影响。'); }
    if (status.overflow) { warnings.push('统计存储已达容量上限，部分事件未被记录；数据保留最多 ' + (status.retention_days || 365) + ' 天。'); }
    if (status.truncated) { warnings.push('事件超过报表扫描上限，汇总仅含已扫描事件，图中未完整扫描的时段留空。'); }
    if (status.partial_history) { warnings.push('现有数据从 ' + meta.retained_from + ' 开始，较早时段留空。'); }
    if (status.corrupt) { warnings.push('检测到 ' + status.corrupt + ' 行损坏数据已跳过。'); }
    if (warnings.length) { render.status.replaceChildren(text(warnings.join(' '))); render.status.hidden = false; }
    else { render.status.hidden = true; render.status.replaceChildren(); }

    const isEmpty = !(totals.pv || totals.clicks || totals.allocations);
    render.empty.hidden = !isEmpty;
    render.content.hidden = isEmpty;
    if (isEmpty) return;

    const delta = deltaFor(status.comparison_reliable !== false);
    const cards = [
      ['页面访问 PV', totals.pv ?? 0, delta(totals.pv ?? 0, previous.pv ?? 0)],
      ['独立访客 UV（估算）', totals.uv ?? 0, delta(totals.uv ?? 0, previous.uv ?? 0)],
      ['访问 Sessions', totals.sessions ?? 0, delta(totals.sessions ?? 0, previous.sessions ?? 0)],
      ['前端联系点击', totals.clicks ?? 0, delta(totals.clicks ?? 0, previous.clicks ?? 0)],
      ['联系点击率', rateText(totals.click_rate), (totals.click_visits ?? 0) + ' / ' + (totals.sessions ?? 0) + ' 次访问发生点击'],
      ['服务端成功分配', totals.allocations ?? 0, '未关联 ' + (totals.allocations_unlinked ?? 0) + ' · ' + delta(totals.allocations ?? 0, previous.allocations ?? 0)],
    ];
    // #analytics-metrics is already the metric grid; cards join it directly.
    render.metrics.replaceChildren(...cards.map(([label, value, sub]) => metricCard(label, value, sub)));

    trendRows = report.trend || [];
    activePoint = Math.max(0, trendRows.length - 1);
    const unit = trendRows[0]?.label.length > 10 ? '小时' : '天';
    render.trendNote.textContent = '按' + unit + '汇总 · 指向图中位置或用方向键查看数值。';
    drawTrend();

    fill(render.sources, ['来源', '访问', 'PV', '联系点击', '发生点击的访问', '点击率'],
      (report.sources || []).concat([{
        label: '（未关联联系点击）', sessions: null, pv: null,
        clicks: totals.clicks_unlinked ?? 0, click_visits: null, rate: null,
      }]), row => [cell(row.label, 'table-key'), cell(row.sessions ?? '—', 'num'), cell(row.pv ?? '—', 'num'),
                   cell(row.clicks ?? 0, 'num'), cell(row.click_visits ?? '—', 'num'), cell(rateText(row.rate), 'num')]);
    fill(render.campaigns, ['活动', '访问', 'PV', '发生点击的访问'], report.campaigns || [],
      row => [cell(row.label, 'table-key'), cell(row.sessions ?? 0, 'num'), cell(row.pv ?? 0, 'num'), cell(row.click_visits ?? 0, 'num')]);
    fill(render.devices, ['设备', '访问', 'PV'], report.devices || [], row => [cell(row.label, 'table-key'), cell(row.sessions ?? 0, 'num'), cell(row.pv ?? 0, 'num')]);
    fill(render.browsers, ['浏览器', '访问', 'PV'], report.browsers || [], row => [cell(row.label, 'table-key'), cell(row.sessions ?? 0, 'num'), cell(row.pv ?? 0, 'num')]);
    fill(render.os, ['操作系统', '访问', 'PV'], report.systems || [], row => [cell(row.label, 'table-key'), cell(row.sessions ?? 0, 'num'), cell(row.pv ?? 0, 'num')]);
    fill(render.numbers, ['号码', '分配次数', '关联到访问'],
      (report.numbers || []).concat([{label: '未关联访问的分配', allocations: totals.allocations_unlinked ?? 0, linked: null}]),
      row => [cell(row.label, 'table-key'), cell(row.allocations ?? 0, 'num'), cell(row.linked ?? '—', 'num')]);
  }

  // Each load carries a sequence token: a slower older answer arriving after a
  // newer switch is ignored, so the panel and its CSV link always match the
  // range currently on screen.
  let requestSeq = 0;
  const reportToday = render.to.max;
  function invalidateRange() {
    requestSeq++;
    render.error.hidden = true;
    render.csv.removeAttribute('href');
    render.form.removeAttribute('aria-busy');
  }
  function updateDateBounds() {
    const enabled = render.range.value === 'custom';
    render.custom.hidden = render.customHelp.hidden = !enabled;
    for (const field of [render.from, render.to]) {
      field.disabled = !enabled;
      field.required = enabled;
    }
    const from = render.from.value;
    render.to.min = from || render.from.min;
    const last = new Date(from + 'T00:00:00Z');
    last.setUTCDate(last.getUTCDate() + Number(render.custom.dataset.maxDays) - 1);
    const lastDate = Number.isNaN(last.valueOf()) ? reportToday : last.toISOString().slice(0,10);
    render.to.max = lastDate < reportToday ? lastDate : reportToday;
  }
  async function load() {
    if (!render.form.reportValidity()) return;
    const token = ++requestSeq;
    const params = new URLSearchParams({range: render.range.value});
    if (render.range.value === 'custom') {
      params.set('from', render.from.value);
      params.set('to', render.to.value);
    }
    const query = params.toString();
    render.error.hidden = true;
    render.csv.removeAttribute('href');
    render.form.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch('/admin/api.php?resource=analytics&' + query, {credentials:'same-origin', cache:'no-store'});
      let data;
      try { data = await response.json(); } catch { throw new Error('报表数据解析失败，请刷新重试。'); }
      if (token !== requestSeq) return;
      if (!response.ok) {
        if (response.status === 401) { throw new Error('登录已过期，请刷新页面重新登录。'); }
        throw new Error(data && data.error ? data.error : '报表加载失败，请刷新重试。');
      }
      renderReport(data);
      render.csv.href = '/admin/api.php?resource=analytics_csv&' + query;
    } catch (error) {
      if (token !== requestSeq) return;
      render.error.hidden = false;
      render.error.replaceChildren(text(error.message || '报表加载失败，请刷新重试。'));
    } finally {
      if (token === requestSeq) render.form.removeAttribute('aria-busy');
    }
  }

  render.range.addEventListener('change', () => {
    invalidateRange();
    updateDateBounds();
    if (render.range.value !== 'custom') load();
  });
  for (const field of [render.from, render.to]) {
    field.addEventListener('input', () => { invalidateRange(); updateDateBounds(); });
  }
  render.form.addEventListener('submit', event => { event.preventDefault(); load(); });
  updateDateBounds();
  load();
})();
