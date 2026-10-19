const Verdict = JSON.parse(document.getElementById('verdict-data').textContent);
const root = document.documentElement;

function lookupText(dictionary, key) {
  let node = dictionary;
  for (const part of key.split('.')) {
    if (!node || typeof node !== 'object' || !(part in node)) return null;
    node = node[part];
  }
  return typeof node === 'string' ? node : null;
}

function t(key, vars) {
  let text = lookupText(Verdict.strings, key);
  if (text === null && Verdict.fallback) text = lookupText(Verdict.fallback, key);
  if (text === null) text = key;
  if (vars) {
    for (const name of Object.keys(vars)) text = text.split(`{${name}}`).join(String(vars[name]));
  }
  return text;
}

function errorText(error) {
  return t(`errors.${error && error.code ? error.code : 'server_error'}`);
}

function locale() {
  return Verdict.lang === 'ru' ? 'ru-RU' : 'en-GB';
}

function icon(name, size = 18, className = '') {
  const holder = document.createElement('span');
  const paths = Verdict.icons[name] || '';
  holder.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-${name} ${className}" aria-hidden="true">${paths}</svg>`;
  return holder.firstChild;
}

function h(tag, attributes, ...children) {
  const element = document.createElement(tag);
  if (attributes) {
    for (const [name, value] of Object.entries(attributes)) {
      if (value === null || value === undefined || value === false) continue;
      if (name.startsWith('on') && typeof value === 'function') {
        element.addEventListener(name.slice(2).toLowerCase(), value);
      } else if (name === 'class') {
        element.className = value;
      } else if (name === 'style' && typeof value === 'object') {
        Object.assign(element.style, value);
      } else if (name === 'value') {
        element.value = value;
      } else if (name === 'checked' || name === 'disabled' || name === 'autofocus') {
        element[name] = !!value;
      } else {
        element.setAttribute(name, value === true ? '' : value);
      }
    }
  }
  appendChildren(element, children);
  return element;
}

function appendChildren(element, children) {
  for (const child of children) {
    if (child === null || child === undefined || child === false) continue;
    if (Array.isArray(child)) appendChildren(element, child);
    else if (child instanceof Node) element.append(child);
    else element.append(document.createTextNode(String(child)));
  }
}

function classes(...names) {
  return names.filter(Boolean).join(' ');
}

class ApiError extends Error {
  constructor(code, status, detail) {
    super(code);
    this.code = code;
    this.status = status;
    this.detail = detail;
  }
}

async function apiRequest(method, action, params, body) {
  const query = new URLSearchParams({ action });
  if (params) {
    for (const [key, value] of Object.entries(params)) {
      if (value !== '' && value !== null && value !== undefined) query.set(key, value);
    }
  }
  let response;
  try {
    response = await fetch(`${Verdict.api}?${query}`, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': Verdict.csrf },
      body: body === undefined ? undefined : JSON.stringify(body)
    });
  } catch (e) {
    throw new ApiError('network', 0);
  }
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new ApiError(data.error || 'server_error', response.status, data.detail);
  return data;
}

const api = {
  get: (action, params) => apiRequest('GET', action, params),
  post: (action, body = {}) => apiRequest('POST', action, null, body)
};

function pageUrl(page, params = {}) {
  const query = new URLSearchParams();
  if (page && page !== 'home') query.set('page', page);
  for (const [key, value] of Object.entries(params)) {
    if (value !== '' && value !== null && value !== undefined) query.set(key, value);
  }
  const text = query.toString();
  return Verdict.base + (text ? `?${text}` : '');
}

function can(permission) {
  const user = Verdict.user;
  return !!user && (user.owner || user.permissions.includes(permission));
}

function toast(text, kind = 'ok') {
  const box = document.querySelector('.toasts');
  if (!box) return;
  const item = h('div', { class: `toast toast-${kind}` }, text);
  box.append(item);
  setTimeout(() => item.remove(), 3500);
}

function reloadWithToast(text, url) {
  try {
    sessionStorage.setItem('verdict-toast', text);
  } catch (e) {}
  if (url) window.location.href = url;
  else window.location.reload();
}

function showStoredToasts() {
  for (const item of Verdict.toasts || []) toast(item.text, item.kind);
  try {
    const text = sessionStorage.getItem('verdict-toast');
    if (text) {
      sessionStorage.removeItem('verdict-toast');
      toast(text);
    }
  } catch (e) {}
}

function debounce(fn, delay) {
  let timer = null;
  const run = (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
  run.cancel = () => clearTimeout(timer);
  return run;
}

function formatDate(seconds) {
  if (!seconds) return '—';
  return new Date(seconds * 1000).toLocaleString(locale(), { dateStyle: 'short', timeStyle: 'short' });
}

function formatDuration(minutes) {
  const total = Number(minutes) || 0;
  if (total <= 0) return t('duration.permanent');
  const days = Math.floor(total / 1440);
  const hours = Math.floor((total % 1440) / 60);
  const rest = total % 60;
  const parts = [];
  if (days) parts.push(t('duration.d', { n: days }));
  if (hours) parts.push(t('duration.h', { n: hours }));
  if (rest) parts.push(t('duration.m', { n: rest }));
  return parts.join(' ');
}

function profileUrl(steamid) {
  return `https://steamcommunity.com/profiles/${steamid}`;
}

function mapLabel(map) {
  return map ? String(map).split('/').pop() : '';
}

let openLayers = 0;

function openModal({ title, wide, onClose }) {
  const titleElement = h('h2', null, title);
  const body = h('div', { class: 'modal-body' });
  const foot = h('div', { class: 'modal-foot' });
  const closeButton = h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'close' }, icon('x', 20));
  const box = h(
    'div',
    { class: classes('modal', wide && 'modal-wide'), role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
    h('div', { class: 'modal-head' }, titleElement, closeButton),
    body,
    foot
  );
  const overlay = h('div', { class: 'overlay' }, box);
  let closed = false;

  const close = () => {
    if (closed) return;
    closed = true;
    openLayers--;
    overlay.remove();
    window.removeEventListener('keydown', onKey);
    if (onClose) onClose();
  };
  const onKey = (event) => {
    if (event.key === 'Escape') close();
  };

  closeButton.addEventListener('click', close);
  overlay.addEventListener('mousedown', (event) => {
    if (event.target === overlay) close();
  });
  window.addEventListener('keydown', onKey);
  document.body.append(overlay);
  openLayers++;

  return {
    body,
    close,
    setTitle(text) {
      titleElement.textContent = text;
      box.setAttribute('aria-label', text);
    },
    setBody(...nodes) {
      body.replaceChildren();
      appendChildren(body, nodes);
      const target = body.querySelector('[autofocus]');
      if (target) setTimeout(() => target.focus(), 0);
    },
    setFooter(...nodes) {
      foot.replaceChildren();
      appendChildren(foot, nodes);
      foot.hidden = foot.childNodes.length === 0;
    }
  };
}

function confirmDialog({ title, text, subject, confirmLabel, tone = 'danger' }) {
  return new Promise((resolve) => {
    const safe = tone === 'danger';
    const previous = document.activeElement;
    const cancel = h('button', { type: 'button', class: 'btn btn-ghost' }, t('common.cancel'));
    const accept = h('button', { type: 'button', class: classes('btn', tone === 'warning' ? 'btn-primary' : 'btn-danger') }, confirmLabel || t('common.delete'));
    const dialog = h(
      'div',
      { class: `confirm tone-${tone}`, role: 'alertdialog', 'aria-modal': 'true' },
      h('span', { class: 'confirm-icon' }, icon(tone === 'warning' ? 'alert-triangle' : 'trash-2', 24)),
      h('h2', null, title),
      subject ? h('div', { class: 'confirm-subject' }, subject) : null,
      text ? h('p', { class: 'confirm-text' }, text) : null,
      h('div', { class: 'confirm-actions' }, cancel, accept)
    );
    const overlay = h('div', { class: 'overlay confirm-overlay' }, dialog);

    const finish = (answer) => {
      window.removeEventListener('keydown', onKey, true);
      overlay.remove();
      openLayers--;
      if (previous && previous.focus) previous.focus();
      resolve(answer);
    };
    const onKey = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        finish(false);
      }
    };

    cancel.addEventListener('click', () => finish(false));
    accept.addEventListener('click', () => finish(true));
    overlay.addEventListener('mousedown', (event) => {
      if (event.target === overlay) finish(false);
    });
    window.addEventListener('keydown', onKey, true);
    document.body.append(overlay);
    openLayers++;
    (safe ? cancel : accept).focus();
  });
}

function field(label, control, { hint, placeholder } = {}) {
  const asLabel = control instanceof HTMLInputElement;
  if (asLabel && placeholder && !control.placeholder) control.placeholder = placeholder;
  return h(asLabel ? 'label' : 'div', { class: 'field' }, h('span', { class: 'field-label' }, label), control, hint ? h('span', { class: 'field-hint' }, hint) : null);
}

function errorLine(error) {
  if (!error) return null;
  return h('div', { class: 'form-error' }, errorText(error), error.detail ? h('div', { class: 'form-error-detail' }, error.detail) : null);
}

function emptyState(iconName, title, text) {
  return h(
    'div',
    { class: 'empty' },
    h('span', { class: 'empty-icon' }, icon(iconName || 'inbox', 26)),
    title ? h('div', { class: 'empty-title' }, title) : null,
    text ? h('div', { class: 'empty-text' }, text) : null
  );
}

function createSelect({ options, value, onChange, className = '', ariaLabel, disabled = false, opensUp = false }) {
  let currentOptions = options;
  let currentValue = value;
  let isDisabled = disabled;
  let open = false;
  let active = 0;
  let list = null;

  const valueLabel = h('span', { class: 'select-value' });
  const trigger = h(
    'button',
    { type: 'button', class: 'select-trigger', 'aria-haspopup': 'listbox', 'aria-expanded': 'false', 'aria-label': ariaLabel },
    valueLabel,
    icon('chevron-down', 17, 'select-chevron')
  );
  const element = h('div', { class: classes('select', className) }, trigger);

  const indexOf = () => currentOptions.findIndex((option) => String(option.value) === String(currentValue));

  function paint() {
    const option = currentOptions[indexOf()];
    valueLabel.textContent = option ? option.label : '—';
    element.className = classes('select', open && 'open', isDisabled && 'disabled', className);
    trigger.disabled = isDisabled;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function paintList() {
    if (!list) return;
    list.querySelectorAll('.select-option').forEach((item, index) => {
      item.classList.toggle('active', index === active);
    });
    const item = list.querySelector(`[data-index="${active}"]`);
    if (item) item.scrollIntoView({ block: 'nearest' });
  }

  function choose(option) {
    hide();
    if (String(option.value) !== String(currentValue)) {
      currentValue = option.value;
      paint();
      if (onChange) onChange(option.value);
    }
  }

  function show() {
    if (isDisabled) return;
    const rect = element.getBoundingClientRect();
    const below = window.innerHeight - rect.bottom;
    const up = opensUp ? rect.top > 160 : below < 280 && rect.top > below;
    active = Math.max(0, indexOf());
    list = h('ul', { class: classes('select-list', up && 'up'), role: 'listbox', tabindex: '-1' });
    currentOptions.forEach((option, index) => {
      const selected = String(option.value) === String(currentValue);
      const item = h(
        'li',
        { 'data-index': index, role: 'option', 'aria-selected': selected ? 'true' : 'false', class: classes('select-option', selected && 'selected') },
        h('span', null, option.label),
        selected ? icon('check', 16) : null
      );
      item.addEventListener('mouseenter', () => {
        active = index;
        paintList();
      });
      item.addEventListener('mousedown', (event) => event.preventDefault());
      item.addEventListener('click', (event) => {
        event.preventDefault();
        choose(option);
      });
      list.append(item);
    });
    element.append(list);
    open = true;
    paint();
    paintList();
    document.addEventListener('mousedown', onOutside);
  }

  function hide() {
    if (list) list.remove();
    list = null;
    open = false;
    paint();
    document.removeEventListener('mousedown', onOutside);
  }

  function onOutside(event) {
    if (!element.contains(event.target)) hide();
  }

  trigger.addEventListener('click', () => (open ? hide() : show()));
  trigger.addEventListener('keydown', (event) => {
    if (isDisabled) return;
    if (!open) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
        event.preventDefault();
        show();
      }
      return;
    }
    if (event.key === 'Escape' || event.key === 'Tab') {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
      }
      hide();
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      active = Math.min(currentOptions.length - 1, active + 1);
      paintList();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      active = Math.max(0, active - 1);
      paintList();
    } else if (event.key === 'Home') {
      event.preventDefault();
      active = 0;
      paintList();
    } else if (event.key === 'End') {
      event.preventDefault();
      active = currentOptions.length - 1;
      paintList();
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      if (currentOptions[active]) choose(currentOptions[active]);
    }
  });

  paint();
  return {
    element,
    setValue(next) {
      currentValue = next;
      paint();
    },
    setOptions(next) {
      currentOptions = next;
      paint();
    },
    setDisabled(next) {
      isDisabled = next;
      paint();
    }
  };
}

function enhanceSelects(container) {
  container.querySelectorAll('select[data-select]').forEach((native) => {
    if (native.dataset.enhanced) return;
    native.dataset.enhanced = '1';
    const options = Array.from(native.options).map((option) => ({ value: option.value, label: option.textContent }));
    const select = createSelect({
      options,
      value: native.value,
      className: native.className,
      ariaLabel: native.getAttribute('aria-label'),
      onChange: (value) => {
        native.value = value;
        native.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    native.hidden = true;
    native.after(select.element);
  });
}

function hydrate(container) {
  container.querySelectorAll('time[data-ts]').forEach((element) => {
    const date = new Date(Number(element.dataset.ts) * 1000);
    if (element.dataset.format === 'time') {
      element.textContent = date.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' });
      element.title = date.toLocaleString(locale(), { dateStyle: 'long', timeStyle: 'medium' });
    } else {
      element.textContent = date.toLocaleString(locale(), { dateStyle: 'short', timeStyle: 'short' });
    }
  });
  container.querySelectorAll('img[data-map-image]').forEach(watchMapImage);
  enhanceSelects(container);
  groupLogDays(container);
  fillSteamCards(container);
}

function watchMapImage(image) {
  if (image.dataset.watched) return;
  image.dataset.watched = '1';
  const ready = () => image.classList.add('loaded');
  if (image.complete && image.naturalWidth > 0) ready();
  else if (image.complete) image.remove();
  image.addEventListener('load', ready);
  image.addEventListener('error', () => image.remove());
}

const steamCards = new Map();

async function loadSteamCards(ids) {
  const missing = [...new Set(ids.filter((id) => id && !steamCards.has(id)))];
  if (missing.length) {
    try {
      const found = await api.get('avatars', { ids: missing.join(',') });
      for (const id of missing) steamCards.set(id, found[id] || null);
    } catch (e) {
      for (const id of missing) steamCards.set(id, null);
    }
  }
}

function steamCard(steamid) {
  return steamid ? steamCards.get(steamid) || null : null;
}

async function fillSteamCards(container) {
  const avatarHolders = Array.from(container.querySelectorAll('[data-avatar-for]'));
  const nameHolders = Array.from(container.querySelectorAll('[data-steam-name]'));
  const ids = avatarHolders.map((item) => item.dataset.avatarFor).concat(nameHolders.map((item) => item.dataset.steamName));
  if (!ids.length) return;
  await loadSteamCards(ids);
  for (const holder of avatarHolders) {
    const card = steamCard(holder.dataset.avatarFor);
    if (!card || !card.avatar || !holder.isConnected) continue;
    const image = h('img', { class: 'row-avatar', src: card.avatar, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' });
    image.addEventListener('error', () => image.replaceWith(holder));
    holder.replaceWith(image);
  }
  for (const holder of nameHolders) {
    const card = steamCard(holder.dataset.steamName);
    if (card && card.name && holder.closest('[data-prefer-steam-name]')) holder.textContent = card.name;
  }
}

function dayKey(date) {
  return `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
}

function dayLabel(date) {
  const today = new Date();
  const yesterday = new Date();
  yesterday.setDate(today.getDate() - 1);
  if (dayKey(date) === dayKey(today)) return t('logs.today');
  if (dayKey(date) === dayKey(yesterday)) return t('logs.yesterday');
  return date.toLocaleDateString(locale(), {
    weekday: 'short',
    day: 'numeric',
    month: 'long',
    year: date.getFullYear() === today.getFullYear() ? undefined : 'numeric'
  });
}

function groupLogDays(container) {
  container.querySelectorAll('ul[data-log-days]').forEach((list) => {
    const days = [];
    for (const item of Array.from(list.children)) {
      const date = new Date(Number(item.dataset.ts) * 1000);
      const key = dayKey(date);
      if (!days.length || days[days.length - 1].key !== key) days.push({ key, date, items: [] });
      days[days.length - 1].items.push(item);
    }
    const sections = days.map((day) =>
      h(
        'div',
        { class: 'log-day' },
        h('div', { class: 'log-day-head' }, h('span', { class: 'log-day-label' }, dayLabel(day.date)), h('span', { class: 'log-day-count' }, day.items.length)),
        h('ul', { class: 'log-list' }, day.items)
      )
    );
    list.replaceWith(...sections);
  });
}

async function swapRegion(id, url) {
  const response = await fetch(url, { credentials: 'same-origin' });
  if (!response.ok) return false;
  const html = await response.text();
  const fresh = new DOMParser().parseFromString(html, 'text/html').getElementById(id);
  const current = document.getElementById(id);
  if (!fresh || !current) return false;
  current.innerHTML = fresh.innerHTML;
  hydrate(current);
  return true;
}

function startRefreshRegions() {
  document.querySelectorAll('[data-refresh]').forEach((region) => {
    const delay = Number(region.dataset.refresh);
    setInterval(() => {
      if (document.visibilityState !== 'visible' || openLayers > 0) return;
      if (region.contains(document.activeElement) && document.activeElement !== document.body) return;
      swapRegion(region.id, window.location.href).catch(() => {});
    }, delay);
  });
}
