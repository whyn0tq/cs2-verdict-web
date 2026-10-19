const STEAM_INPUT = /^(\d{17}|STEAM_[0-5]:[01]:\d+|\[?U:1:\d+\]?)$|steamcommunity\.com\/(profiles\/\d{17}|id\/[\w-]{2,32})/i;
const DURATION_PRESETS = [0, 30, 60, 360, 1440, 4320, 10080, 43200];
const FLAG_LETTERS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'z'];
const PROTECTED_FLAGS = ['z', 'm'];
const PERMISSION_SECTIONS = [
  ['bans', ['bans.add', 'bans.edit', 'bans.delete']],
  ['comms', ['comms.add', 'comms.edit', 'comms.delete']],
  ['players', ['players.view', 'players.kick', 'players.control', 'players.chat', 'players.ip']],
  ['servers', ['servers.manage', 'servers.rcon']],
  ['panel', ['admins.manage', 'groups.manage', 'logs.view']]
];
const LEVEL_COLORS = ['#9b9b9b', '#c02942', '#d95b43', '#fecc23', '#467a3c', '#4e8ddb', '#7652c9', '#c252c9', '#542437', '#997c52'];

function stepsBar(count, current, labels, onSelect) {
  if (count < 2) return null;
  const bar = h('div', { class: classes('wizard-steps', labels && 'labeled'), 'aria-hidden': onSelect ? null : 'true' });
  for (let i = 0; i < count; i++) {
    const className = classes('wizard-step', i <= current && 'done', i === current && 'current');
    const label = labels ? labels[i] : null;
    if (onSelect) bar.append(h('button', { type: 'button', class: className, onclick: () => onSelect(i) }, label));
    else bar.append(h('span', { class: className }, label));
  }
  return bar;
}

function steamLookup(onChange) {
  let state = { status: 'idle', profile: null };
  let ticket = 0;
  let timer = null;

  const update = (next) => {
    state = next;
    onChange(state);
  };

  return {
    get state() {
      return state;
    },
    set(value) {
      const text = String(value || '').trim();
      clearTimeout(timer);
      ticket++;
      if (!STEAM_INPUT.test(text)) {
        update({ status: 'idle', profile: null });
        return;
      }
      update({ status: 'loading', profile: null });
      const mine = ticket;
      timer = setTimeout(() => {
        api
          .get('steam_lookup', { q: text })
          .then((profile) => mine === ticket && update({ status: 'found', profile }))
          .catch(() => mine === ticket && update({ status: 'missing', profile: null }));
      }, 450);
    },
    found(profile) {
      state = { status: 'found', profile };
    }
  };
}

function steamLevel(level) {
  const color = LEVEL_COLORS[Math.floor(level / 10) % 10];
  return h('span', { class: 'steam-level' }, h('span', { class: 'steam-level-ring', style: { borderColor: color } }, level), t('punish.level'));
}

function playerAvatar(src, compact) {
  const placeholder = () => h('span', { class: 'player-avatar placeholder' }, icon('user-round', compact ? 18 : 26));
  if (!src) return placeholder();
  const image = h('img', { class: 'player-avatar', src, alt: '', referrerpolicy: 'no-referrer' });
  image.addEventListener('error', () => image.replaceWith(placeholder()));
  return image;
}

function playerCard(lookup, { fallback, compact, onChange } = {}) {
  if (lookup.status === 'loading') {
    return h(
      'div',
      { class: classes('player-card', compact && 'compact'), 'aria-busy': 'true' },
      h('span', { class: 'player-avatar skeleton' }),
      h('div', { class: 'player-card-body' }, h('span', { class: 'skeleton-line' }), h('span', { class: 'skeleton-line short' }))
    );
  }
  if (lookup.status === 'missing' && !fallback) {
    return h(
      'div',
      { class: 'player-card missing' },
      h('span', { class: 'player-avatar placeholder' }, icon('user-x', 22)),
      h('div', { class: 'player-card-body' }, h('b', null, t('punish.player_missing')), h('span', { class: 'muted small' }, t('punish.player_missing_hint')))
    );
  }
  const profile = lookup.profile || fallback;
  if (!profile || !profile.steamid) return null;

  const tags = compact
    ? null
    : h(
        'div',
        { class: 'player-card-tags' },
        profile.level !== null && profile.level !== undefined ? steamLevel(profile.level) : null,
        profile.private ? h('span', { class: 'tag' }, t('punish.private')) : null,
        profile.vac ? h('span', { class: 'tag tag-danger' }, icon('shield-alert', 12), t('punish.vac')) : null,
        profile.offline ? h('span', { class: 'tag' }, t('punish.steam_offline')) : null
      );

  return h(
    'div',
    { class: classes('player-card', compact && 'compact') },
    playerAvatar(profile.avatar, compact),
    h(
      'div',
      { class: 'player-card-body' },
      h('div', { class: 'player-card-name' }, profile.name || t('punish.no_name')),
      h('a', { class: 'player-card-id', href: profileUrl(profile.steamid), target: '_blank', rel: 'noreferrer noopener' }, profile.steamid, icon('external-link', 12)),
      tags
    ),
    onChange ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: onChange }, t('punish.change')) : null
  );
}

function durationField(value, onChange) {
  let custom = !DURATION_PRESETS.includes(Number(value));
  const input = h('input', {
    type: 'number',
    min: '0',
    value: String(value),
    placeholder: t('duration.minutes'),
    'aria-label': t('duration.minutes'),
    oninput: (event) => onChange(Math.max(0, Number(event.target.value) || 0))
  });
  input.hidden = !custom;
  const options = DURATION_PRESETS.map((minutes) => ({ value: String(minutes), label: formatDuration(minutes) }));
  options.push({ value: 'custom', label: t('duration.custom') });
  const select = createSelect({
    options,
    value: custom ? 'custom' : String(value),
    ariaLabel: t('punish.duration'),
    onChange: (next) => {
      custom = next === 'custom';
      input.hidden = !custom;
      if (custom) {
        input.focus();
        return;
      }
      input.value = next;
      onChange(Number(next));
    }
  });
  return h('div', { class: 'duration-field' }, select.element, input);
}

function flagsField(value, onChange, locked) {
  let letters = String(value || '');
  const known = FLAG_LETTERS;
  const custom = () => letters.split('').filter((flag) => !known.includes(flag)).join('');
  const grid = h('div', { class: 'flags-grid' });
  for (const flag of known) {
    grid.append(
      h(
        'label',
        { class: 'check' },
        h('input', {
          type: 'checkbox',
          checked: letters.includes(flag),
          disabled: locked.includes(flag),
          onchange: (event) => {
            letters = event.target.checked ? letters + flag : letters.split(flag).join('');
            onChange(letters);
          }
        }),
        h('span', null, h('b', null, flag), ` — ${t(`flags.${flag}`)}`)
      )
    );
  }
  const extra = h('input', {
    type: 'text',
    value: custom(),
    placeholder: t('admins.custom_flags'),
    onblur: (event) => {
      const typed = event.target.value.toLowerCase().replace(/[^a-y]/g, '').split('').filter((flag) => !known.includes(flag));
      const base = letters.split('').filter((flag) => known.includes(flag));
      letters = [...new Set(base.concat(typed))].join('');
      event.target.value = custom();
      onChange(letters);
    }
  });
  return h('div', { class: 'flags-field' }, grid, extra);
}

function permissionsField(value, onChange, allowed, ownerOption) {
  let list = value.slice();
  const holder = h('div', { class: 'perm-field' });

  const toggle = (permission) => {
    list = list.includes(permission) ? list.filter((item) => item !== permission) : list.concat(permission);
    onChange(list);
    paint();
  };

  function paint() {
    holder.replaceChildren();
    const isOwner = list.includes('owner');
    if (ownerOption) {
      holder.append(
        h(
          'label',
          { class: 'check perm-owner' },
          h('input', { type: 'checkbox', checked: isOwner, onchange: () => toggle('owner') }),
          h('span', null, h('b', null, t('permissions.owner')), h('span', { class: 'muted small' }, ` — ${t('permissions.owner_hint')}`))
        )
      );
    }
    if (isOwner) return;
    for (const [section, permissions] of PERMISSION_SECTIONS) {
      holder.append(
        h(
          'div',
          { class: 'perm-section' },
          h('div', { class: 'perm-title' }, t(`permissions.sections.${section}`)),
          h(
            'div',
            { class: 'perm-list' },
            permissions.map((permission) =>
              h(
                'label',
                { class: 'check' },
                h('input', {
                  type: 'checkbox',
                  checked: list.includes(permission),
                  disabled: allowed && !allowed.includes(permission) && !list.includes(permission),
                  onchange: () => toggle(permission)
                }),
                h('span', null, t(`permissions.list.${permission.replace('.', '_')}`))
              )
            )
          )
        )
      );
    }
  }

  paint();
  return holder;
}

function pad(number) {
  return String(number).padStart(2, '0');
}

function parseLocalDate(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value || '');
  return match ? new Date(+match[1], match[2] - 1, +match[3], +match[4], +match[5]) : null;
}

function serializeLocalDate(date) {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function startOfDay(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function addDays(date, count) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate() + count, date.getHours(), date.getMinutes());
}

function addMonths(date, count) {
  const target = new Date(date.getFullYear(), date.getMonth() + count, 1, date.getHours(), date.getMinutes());
  const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
  target.setDate(Math.min(date.getDate(), last));
  return target;
}

function nowToMinute() {
  const date = new Date();
  date.setSeconds(0, 0);
  return date;
}

function timeSpinner(value, max, label, onChange) {
  let current = value;
  const input = h('input', { class: 'dtp-spin-input', value: pad(value), inputmode: 'numeric', 'aria-label': label });
  const step = (delta) => onChange((current + delta + max + 1) % (max + 1));
  input.addEventListener('focus', () => input.select());
  input.addEventListener('input', () => {
    const digits = input.value.replace(/\D/g, '').slice(-2);
    input.value = digits;
    if (digits !== '' && Number(digits) <= max) {
      current = Number(digits);
      onChange(current, true);
    }
  });
  input.addEventListener('blur', () => (input.value = pad(current)));
  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
      event.preventDefault();
      step(event.key === 'ArrowUp' ? 1 : -1);
    }
  });
  return h(
    'div',
    { class: 'dtp-spin' },
    h('button', { type: 'button', class: 'dtp-spin-btn', tabindex: '-1', 'aria-label': '+', onclick: () => step(1) }, icon('chevron-up', 15)),
    input,
    h('button', { type: 'button', class: 'dtp-spin-btn', tabindex: '-1', 'aria-label': '-', onclick: () => step(-1) }, icon('chevron-down', 15))
  );
}

function dateTimePicker({ value, onChange, min, placeholder, ariaLabel }) {
  const weekStart = Verdict.lang === 'ru' ? 1 : 0;
  const pickerLocale = Verdict.lang === 'ru' ? 'ru-RU' : 'en-US';
  const minDay = min ? startOfDay(min) : null;
  let current = value || '';
  let cursor = parseLocalDate(current) || nowToMinute();
  let open = false;
  let focusGrid = false;
  let popup = null;

  const element = h('div', { class: 'dtp' });

  const selected = () => parseLocalDate(current);
  const emit = (date) => {
    current = date ? serializeLocalDate(date) : '';
    onChange(current);
    paintTrigger();
  };

  function paintTrigger() {
    const date = selected();
    const label = date
      ? new Intl.DateTimeFormat(pickerLocale, { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date)
      : null;
    const trigger = h(
      'button',
      { type: 'button', class: 'select-trigger dtp-trigger', 'aria-haspopup': 'dialog', 'aria-expanded': open ? 'true' : 'false', 'aria-label': ariaLabel },
      icon('calendar-days', 17, 'dtp-icon'),
      h('span', { class: classes('select-value', !label && 'dtp-placeholder') }, label || placeholder),
      label ? null : icon('chevron-down', 17, 'select-chevron')
    );
    trigger.addEventListener('click', () => (open ? close() : show()));
    const wrap = h(
      'div',
      { class: 'dtp-trigger-wrap' },
      trigger,
      label ? h('button', { type: 'button', class: 'dtp-clear', 'aria-label': t('picker.clear'), title: t('picker.clear'), onclick: () => emit(null) }, icon('x', 15)) : null
    );
    element.className = classes('dtp', open && 'open');
    const old = element.querySelector('.dtp-trigger-wrap');
    if (old) old.replaceWith(wrap);
    else element.prepend(wrap);
  }

  function show() {
    const rect = element.getBoundingClientRect();
    const below = window.innerHeight - rect.bottom;
    cursor = selected() || nowToMinute();
    focusGrid = false;
    open = true;
    popup = h('div', { class: classes('dtp-pop', below < 470 && rect.top > below && 'up'), role: 'dialog', 'aria-label': ariaLabel });
    element.append(popup);
    paintPopup();
    paintTrigger();
    document.addEventListener('mousedown', onOutside);
  }

  function close() {
    open = false;
    if (popup) popup.remove();
    popup = null;
    paintTrigger();
    document.removeEventListener('mousedown', onOutside);
  }

  function onOutside(event) {
    if (!element.contains(event.target)) close();
  }

  function isDisabled(day) {
    return !!minDay && day < minDay;
  }

  function pickDay(day) {
    if (isDisabled(day)) return;
    const base = selected() || nowToMinute();
    const next = new Date(day.getFullYear(), day.getMonth(), day.getDate(), base.getHours(), base.getMinutes());
    cursor = next;
    emit(next);
    paintPopup();
  }

  function setTime(part, typing) {
    const base = selected() || cursor;
    const hours = part.hours === undefined ? base.getHours() : part.hours;
    const minutes = part.minutes === undefined ? base.getMinutes() : part.minutes;
    const next = new Date(base.getFullYear(), base.getMonth(), base.getDate(), hours, minutes);
    cursor = next;
    emit(next);
    if (!typing) paintPopup();
  }

  function moveCursor(next) {
    focusGrid = true;
    cursor = next;
    paintPopup();
  }

  function paintPopup() {
    if (!popup) return;
    const date = selected();
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const gridStart = addDays(first, -((first.getDay() - weekStart + 7) % 7));
    const todayKey = dayKey(new Date());
    const monthName = new Intl.DateTimeFormat(pickerLocale, { month: 'long' }).format(first);
    const time = date || cursor;

    const presets = h(
      'div',
      { class: 'dtp-presets' },
      [
        ['day', (d) => addDays(d, 1)],
        ['week', (d) => addDays(d, 7)],
        ['month', (d) => addMonths(d, 1)],
        ['quarter', (d) => addMonths(d, 3)],
        ['year', (d) => addMonths(d, 12)]
      ].map(([key, apply]) =>
        h('button', {
          type: 'button',
          class: 'dtp-chip',
          onclick: () => {
            const next = apply(nowToMinute());
            cursor = next;
            emit(next);
            close();
          }
        }, t(`picker.presets.${key}`))
      )
    );

    const head = h(
      'div',
      { class: 'dtp-head' },
      h('button', { type: 'button', class: 'icon-btn dtp-nav', 'aria-label': 'prev', onclick: () => { cursor = addMonths(cursor, -1); paintPopup(); } }, icon('chevron-left', 18)),
      h('span', { class: 'dtp-month' }, `${monthName.charAt(0).toUpperCase()}${monthName.slice(1)} ${first.getFullYear()}`),
      h('button', { type: 'button', class: 'icon-btn dtp-nav', 'aria-label': 'next', onclick: () => { cursor = addMonths(cursor, 1); paintPopup(); } }, icon('chevron-right', 18))
    );

    const grid = h('div', { class: 'dtp-grid', role: 'grid' });
    for (let i = 0; i < 7; i++) {
      grid.append(h('span', { class: 'dtp-weekday' }, new Intl.DateTimeFormat(pickerLocale, { weekday: 'short' }).format(new Date(2024, 0, 7 + weekStart + i))));
    }
    for (let i = 0; i < 42; i++) {
      const day = addDays(startOfDay(gridStart), i);
      const key = dayKey(day);
      const isSelected = date && key === dayKey(date);
      grid.append(
        h('button', {
          type: 'button',
          'data-day': key,
          class: classes('dtp-day', day.getMonth() !== cursor.getMonth() && 'outside', isSelected && 'selected', key === todayKey && 'today'),
          tabindex: key === dayKey(cursor) ? '0' : '-1',
          disabled: isDisabled(day),
          'aria-selected': isSelected ? 'true' : 'false',
          onclick: () => pickDay(day)
        }, day.getDate())
      );
    }
    grid.addEventListener('keydown', (event) => {
      const moves = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
      if (moves[event.key]) {
        event.preventDefault();
        moveCursor(addDays(cursor, moves[event.key]));
      } else if (event.key === 'PageUp' || event.key === 'PageDown') {
        event.preventDefault();
        moveCursor(addMonths(cursor, event.key === 'PageUp' ? -1 : 1));
      } else if (event.key === 'Home' || event.key === 'End') {
        event.preventDefault();
        const offset = (cursor.getDay() - weekStart + 7) % 7;
        moveCursor(addDays(cursor, event.key === 'Home' ? -offset : 6 - offset));
      }
    });

    const timeRow = h(
      'div',
      { class: 'dtp-time' },
      h('span', { class: 'dtp-time-label' }, t('picker.time')),
      timeSpinner(time.getHours(), 23, t('picker.hours'), (hours, typing) => setTime({ hours }, typing)),
      h('span', { class: 'dtp-colon' }, ':'),
      timeSpinner(time.getMinutes(), 59, t('picker.minutes'), (minutes, typing) => setTime({ minutes }, typing))
    );

    const foot = h(
      'div',
      { class: 'dtp-foot' },
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => { emit(null); close(); } }, placeholder),
      h('button', { type: 'button', class: 'btn btn-primary btn-sm', onclick: close }, t('picker.done'))
    );

    popup.replaceChildren(presets, head, grid, timeRow, foot);
    if (focusGrid) {
      const target = grid.querySelector(`[data-day="${dayKey(cursor)}"]`);
      if (target) target.focus();
    }
  }

  element.addEventListener('keydown', (event) => {
    if (open && event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      close();
    }
  });

  paintTrigger();
  return element;
}

function splitAddress(value) {
  const at = value.lastIndexOf(':');
  const host = value.slice(0, at);
  const port = Number(value.slice(at + 1));
  return /^[A-Za-z0-9.-]{3,64}$/.test(host) && port >= 1 && port <= 65535 ? [host, port] : null;
}

function serverProbeCard(probe) {
  if (probe.status === 'loading') {
    return h(
      'div',
      { class: 'server-probe', 'aria-busy': 'true' },
      h('span', { class: 'probe-dot loading' }),
      h('div', { class: 'player-card-body' }, h('span', { class: 'skeleton-line' }), h('span', { class: 'skeleton-line short' }))
    );
  }
  if (probe.status === 'missing') {
    return h(
      'div',
      { class: 'server-probe missing' },
      icon('server-crash', 20, 'probe-icon'),
      h('div', { class: 'player-card-body' }, h('b', null, t('servers.probe_missing')), h('span', { class: 'muted small' }, t('servers.probe_missing_hint')))
    );
  }
  if (probe.status !== 'found') return null;
  const info = probe.info;
  const backdrop = mapBackdrop(info.map);
  return h(
    'div',
    { class: 'server-probe found' },
    backdrop,
    h('span', { class: 'probe-dot' }),
    h(
      'div',
      { class: 'player-card-body' },
      h('div', { class: 'player-card-name' }, info.name || '—'),
      h(
        'div',
        { class: 'player-card-tags' },
        info.map ? h('span', { class: 'tag' }, icon('map', 12), mapLabel(info.map)) : null,
        h('span', { class: 'tag' }, icon('users', 12), `${info.players}/${info.max_players}`, info.bots > 0 ? ` · ${t('servers.probe_bots', { n: info.bots })}` : ''),
        info.vac ? h('span', { class: 'tag' }, icon('shield-check', 12), 'VAC') : null
      )
    )
  );
}

function mapBackdrop(map) {
  const name = String(map || '').toLowerCase().split('/').pop();
  if (!/^[a-z0-9_]{1,64}$/.test(name)) return null;
  const image = h('img', { class: 'map-backdrop', src: `${Verdict.api}?action=map&name=${name}`, alt: '', 'aria-hidden': 'true', loading: 'lazy' });
  image.dataset.mapImage = '';
  watchMapImage(image);
  return image;
}

function serverAddress({ ip, port, probeAction, onChange, autofocus, withNames }) {
  let probe = { status: 'idle', info: null };
  let ticket = 0;
  const card = h('div', { class: 'slot' });
  const ipInput = h('input', { value: ip || '', spellcheck: 'false', autofocus, name: withNames ? 'ip' : null, required: withNames });
  const portInput = h('input', { type: 'number', value: String(port || 27015), min: '1', max: '65535', name: withNames ? 'port' : null });

  const paintCard = () => {
    const next = serverProbeCard(probe);
    card.replaceChildren();
    if (next) card.append(next);
  };

  const run = debounce(() => {
    const address = splitAddress(`${ipInput.value.trim()}:${Number(portInput.value) || ''}`);
    if (!address) return;
    const mine = ++ticket;
    api
      .get(probeAction, { ip: address[0], port: address[1] })
      .then((info) => {
        if (mine !== ticket) return;
        probe = { status: 'found', info };
        paintCard();
        onChange({ name: info.name || '' });
      })
      .catch(() => {
        if (mine !== ticket) return;
        probe = { status: 'missing', info: null };
        paintCard();
        onChange({ name: '' });
      });
  }, 600);

  const changed = () => {
    ticket++;
    const address = splitAddress(`${ipInput.value.trim()}:${Number(portInput.value) || ''}`);
    probe = { status: address ? 'loading' : 'idle', info: null };
    paintCard();
    onChange({ ip: ipInput.value.trim(), port: Number(portInput.value), name: '' });
    if (address) run();
  };

  ipInput.addEventListener('input', () => {
    const match = /^([A-Za-z0-9.-]+):(\d{1,5})$/.exec(ipInput.value.trim());
    if (match) {
      ipInput.value = match[1];
      portInput.value = match[2];
    }
    changed();
  });
  portInput.addEventListener('input', changed);

  if (splitAddress(`${ipInput.value.trim()}:${Number(portInput.value) || ''}`)) changed();

  return h(
    'div',
    { class: 'server-address' },
    h(
      'div',
      { class: 'row-address' },
      field(t('servers.address'), ipInput, { placeholder: t('servers.address_placeholder') }),
      field(t('servers.port'), portInput)
    ),
    card
  );
}
