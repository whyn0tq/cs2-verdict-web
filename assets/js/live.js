const SIDES = ['ct', 't'];
const OTHER_SIDE = { ct: 't', t: 'ct' };

function pingTone(ping) {
  if (ping <= 60) return 'good';
  if (ping <= 120) return 'fair';
  return 'bad';
}

function playerBadges(player) {
  return [
    player.is_admin ? h('span', { class: 'badge badge-role' }, t('servers.admin_badge')) : null,
    player.bot ? h('span', { class: 'badge' }, t('live.bot')) : null,
    player.muted ? h('span', { class: 'badge badge-type-mute' }, t('type.mute')) : null,
    player.gagged ? h('span', { class: 'badge badge-type-gag' }, t('type.gag')) : null
  ];
}

function startServerPage(server) {
  const playersHolder = document.querySelector('[data-players]');
  const countBadge = document.querySelector('[data-players-count]');
  const feedHolder = document.querySelector('[data-feed]');
  const consoleHolder = document.querySelector('[data-console]');
  let live = !!(server.plugin && server.plugin.connected);
  let teams = server.plugin ? server.plugin.teams : null;
  let players = [];
  let lastSnapshot = '';
  let feed = null;

  async function act(path, player, body, message) {
    try {
      await api.post(path, Object.assign({ id: server.id, steamid: player.steamid }, body));
      toast(t(message, { name: player.name }));
      loadPlayers();
    } catch (error) {
      toast(errorText(error), 'error');
    }
  }

  function openAction(type, player) {
    if (type === 'kick') {
      openReasonModal({
        title: `${t('servers.kick_title')}: ${player.name}`,
        confirmLabel: t('servers.kick'),
        onSubmit: async (reason) => {
          await api.post('kick', { id: server.id, steamid: player.steamid, reason });
          toast(t('toast.kicked'));
          loadPlayers();
        }
      });
      return;
    }
    openPunishModal({
      kind: type === 'ban' ? 'bans' : 'comms',
      initial: { steamid: player.steamid, name: player.name, ip: player.ip || '', server_id: server.id },
      onDone: (message) => {
        toast(message);
        loadPlayers();
      }
    });
  }

  function ipChip(ip) {
    const chip = h('button', { type: 'button', class: 'live-ip', title: t('install.copy') }, icon('globe', 11), h('span', null, ip));
    chip.addEventListener('click', () => copyText(chip, ip));
    return chip;
  }

  function iconButton(iconName, title, onClick, extra = '') {
    return h('button', { type: 'button', class: classes('btn btn-ghost btn-icon', extra), title, 'aria-label': title, onclick: onClick }, icon(iconName, 15));
  }

  function liveRow(player) {
    const human = !player.bot && player.steamid;
    const playing = player.team === 't' || player.team === 'ct';
    const card = steamCard(player.steamid);
    const avatar = card && card.avatar
      ? h('img', { class: 'live-avatar', src: card.avatar, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' })
      : h('span', { class: 'live-avatar placeholder' }, player.bot ? icon('bot', 15) : (player.name || '?').slice(0, 1).toUpperCase());
    const minutes = player.joined_at ? Math.max(1, Math.round((Date.now() / 1000 - player.joined_at) / 60)) : null;

    const meta = h('div', { class: 'live-meta' });
    if (playing) {
      if (player.alive) {
        meta.append(
          h(
            'span',
            { class: 'live-hp', title: t('live.health') },
            h('span', { class: 'live-hp-track' }, h('span', { class: 'live-hp-bar', style: { width: `${Math.min(100, player.health)}%` } })),
            h('span', null, player.health)
          )
        );
      } else {
        meta.append(h('span', { class: 'live-dead' }, icon('skull', 12), t('live.dead')));
      }
    }
    if (minutes) meta.append(h('span', null, formatDuration(minutes)));

    const actions = h('div', { class: 'live-actions' });
    if (human && can('players.control')) {
      if (playing && player.alive) actions.append(iconButton('skull', t('live.slay'), () => act('slay', player, {}, 'live.slay_done')));
      if (playing) {
        const target = OTHER_SIDE[player.team];
        actions.append(iconButton('arrow-left-right', t(`live.move_${target}`), () => act('team', player, { team: target }, 'live.move_done')));
      } else {
        for (const side of SIDES) {
          actions.append(
            h('button', { type: 'button', class: `btn btn-ghost btn-sm live-join side-${side}`, title: t(`live.move_${side}`), onclick: () => act('team', player, { team: side }, 'live.move_done') }, side.toUpperCase())
          );
        }
      }
      if (playing) actions.append(iconButton('eye', t('live.move_spec'), () => act('team', player, { team: 'spec' }, 'live.move_done')));
    }
    if (human && can('players.kick')) actions.append(iconButton('user-x', t('servers.kick'), () => openAction('kick', player)));
    if (human && can('comms.add')) actions.append(iconButton('mic-off', t('nav.comms'), () => openAction('comms', player)));
    if (human && can('bans.add')) actions.append(iconButton('ban', t('punish.add_ban'), () => openAction('ban', player), 'text-danger'));

    return h(
      'li',
      { class: classes('live-row', !(player.alive || !playing) && 'dead') },
      h(
        'div',
        { class: 'live-player' },
        avatar,
        h(
          'div',
          { class: 'live-who' },
          h(
            'div',
            { class: 'live-name-line' },
            human ? h('a', { class: 'live-name', href: profileUrl(player.steamid), target: '_blank', rel: 'noreferrer noopener' }, player.name) : h('span', { class: 'live-name' }, player.name),
            playerBadges(player)
          ),
          meta,
          player.ip ? ipChip(player.ip) : null
        )
      ),
      h('span', { class: 'live-kda' }, player.kills, h('i', null, '/'), player.deaths, h('i', null, '/'), player.assists),
      h('span', { class: 'live-score' }, player.score),
      h('span', { class: `live-ping ping-${pingTone(player.ping)}` }, player.bot ? '—' : player.ping),
      actions
    );
  }

  function byTeam(team) {
    return players
      .filter((player) => player.team === team)
      .sort((a, b) => b.score - a.score || b.kills - a.kills || a.name.localeCompare(b.name));
  }

  function scoreboard() {
    const watchers = players.filter((player) => player.team === 'spec' || player.team === 'none');
    const teamsBlock = h(
      'div',
      { class: 'live-teams' },
      SIDES.map((side) => {
        const list = byTeam(side);
        return h(
          'section',
          { class: `live-team side-${side}` },
          h(
            'header',
            { class: 'live-team-head' },
            h('span', { class: 'live-team-mark' }),
            h('span', { class: 'live-team-name' }, t(`live.team_${side}`)),
            h('span', { class: 'live-team-count' }, list.length),
            h('span', { class: 'live-team-score' }, teams ? teams[side] : 0)
          ),
          h(
            'ul',
            { class: 'live-list' },
            h('li', { class: 'live-cols', 'aria-hidden': 'true' }, h('span'), h('span', null, t('live.kda')), h('span', null, t('live.score')), h('span', null, t('live.ping')), h('span')),
            list.map(liveRow),
            list.length === 0 ? h('li', { class: 'live-empty' }, t('live.empty_team')) : null
          )
        );
      })
    );
    const spectators = watchers.length
      ? h(
          'section',
          { class: 'live-team side-spec' },
          h('header', { class: 'live-team-head' }, icon('eye', 15), h('span', { class: 'live-team-name' }, t('live.spectators')), h('span', { class: 'live-team-count' }, watchers.length)),
          h('ul', { class: 'live-list' }, watchers.map(liveRow))
        )
      : null;
    return h('div', { class: 'live' }, teamsBlock, spectators);
  }

  function serverTable() {
    const showIp = can('players.ip');
    return h(
      'div',
      { class: 'table-wrap' },
      h(
        'table',
        { class: 'table' },
        h(
          'thead',
          null,
          h('tr', null, h('th', null, t('punish.player')), showIp ? h('th', null, t('punish.ip')) : null, h('th', null, t('servers.time_on_server')), h('th', { class: 'right' }, t('common.actions')))
        ),
        h(
          'tbody',
          null,
          players.map((player) =>
            h(
              'tr',
              null,
              h('td', null, h('div', { class: 'player-line' }, h('span', { class: 'player-name' }, player.name), h('span', { class: 'badge' }, t('servers.score', { n: player.score })))),
              showIp ? h('td', { class: 'muted' }, '—') : null,
              h('td', { class: 'muted nowrap' }, formatDuration(Math.max(1, Math.round(player.seconds / 60)))),
              h('td')
            )
          )
        )
      )
    );
  }

  function paintPlayers(source) {
    const nodes = [];
    if (source === 'a2s' && players.length) nodes.push(h('div', { class: 'notice' }, t('servers.a2s_players_hint')));
    if (players.length) nodes.push(source === 'plugin' ? scoreboard() : serverTable());
    else nodes.push(emptyState('users', t('servers.no_players'), t('servers.no_players_text')));
    playersHolder.replaceChildren(...nodes);
    countBadge.textContent = players.length;
  }

  async function loadPlayers() {
    try {
      const data = await api.get('players', { id: server.id });
      const wasLive = live;
      live = !!(data.server.plugin && data.server.plugin.connected);
      teams = data.server.plugin ? data.server.plugin.teams : null;
      players = data.players;
      const ids = players.map((player) => player.steamid).filter(Boolean);
      const paintIfChanged = () => {
        const snapshot = JSON.stringify([data.source, players, teams, ids.map((id) => !!steamCard(id))]);
        if (snapshot !== lastSnapshot) {
          lastSnapshot = snapshot;
          paintPlayers(data.source);
        }
      };
      paintIfChanged();
      loadSteamCards(ids).then(paintIfChanged);
      if (feed) feed.update(live, players);
      if (wasLive !== live) schedulePlayers();
    } catch (error) {
      if (!lastSnapshot) playersHolder.replaceChildren(h('div', { class: 'state state-error' }, errorText(error)));
    }
  }

  let playersTimer = null;
  function schedulePlayers() {
    clearInterval(playersTimer);
    playersTimer = setInterval(() => {
      if (document.visibilityState === 'visible' && openLayers === 0) loadPlayers();
    }, live ? 3000 : 10000);
  }

  if (playersHolder) {
    feed = feedHolder ? startFeed(feedHolder, server.id, live) : null;
    loadPlayers();
    schedulePlayers();
  }
  if (consoleHolder) startConsole(consoleHolder, server.id);
}

function startFeed(holder, serverId, initialLive) {
  const CHAT_KINDS = ['chat', 'panel_chat'];
  let live = initialLive;
  let events = null;
  let lastId = 0;
  let filter = 'all';
  let target = '';
  let busy = false;
  let stick = true;
  let humans = [];

  const list = h('div', { class: 'feed-list' });
  list.addEventListener('scroll', () => {
    stick = list.scrollHeight - list.scrollTop - list.clientHeight < 40;
  });

  const filters = h('div', { class: 'feed-filters', role: 'group', 'aria-label': t('feed.title') });
  const paintFilters = () => {
    filters.replaceChildren(
      ...['all', 'chat', 'events'].map((key) =>
        h('button', {
          type: 'button',
          class: classes('feed-filter', filter === key && 'active'),
          'aria-pressed': filter === key ? 'true' : 'false',
          onclick: () => {
            filter = key;
            paintFilters();
            paintList();
          }
        }, t(`feed.filter_${key}`))
      )
    );
  };

  const time = (seconds) => new Date(seconds * 1000).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit', second: '2-digit' });

  function who(steamid, name) {
    if (!name) return null;
    if (!steamid) return h('span', { class: 'feed-name' }, name);
    return h('a', { class: 'feed-name', href: profileUrl(steamid), target: '_blank', rel: 'noreferrer noopener' }, name);
  }

  function line(event) {
    if (event.kind === 'chat') {
      return h(
        'div',
        { class: classes('feed-line chat', `side-${event.team || 'none'}`, event.blocked && 'blocked') },
        h('span', { class: 'feed-time' }, time(event.at)),
        h(
          'span',
          { class: 'feed-body' },
          event.teamOnly ? h('span', { class: 'feed-tag' }, t('feed.team_chat')) : null,
          event.dead ? h('span', { class: 'feed-tag' }, t('feed.dead')) : null,
          event.blocked ? h('span', { class: 'feed-tag gag' }, t('feed.gagged')) : null,
          who(event.steamid, event.name),
          h('span', { class: 'feed-sep' }, ':'),
          h('span', { class: 'feed-text' }, event.text)
        )
      );
    }
    if (event.kind === 'panel_chat') {
      return h(
        'div',
        { class: 'feed-line panel' },
        h('span', { class: 'feed-time' }, time(event.at)),
        h(
          'span',
          { class: 'feed-body' },
          icon('shield', 13, 'feed-icon'),
          h('span', { class: 'feed-name' }, event.admin),
          event.name ? h('span', { class: 'feed-tag' }, `${t('feed.pm_to')} ${event.name}`) : null,
          h('span', { class: 'feed-sep' }, ':'),
          h('span', { class: 'feed-text' }, event.text)
        )
      );
    }
    const icons = { join: 'log-in', leave: 'log-out', rename: 'pen-line', action: 'gavel', ban_kick: 'ban' };
    let content = [];
    if (event.kind === 'join') content = [who(event.steamid, event.name), ` ${t('feed.joined')}`];
    if (event.kind === 'leave') content = [who(event.steamid, event.name), ` ${t('feed.left')}`];
    if (event.kind === 'rename') content = [h('span', { class: 'feed-old' }, event.from), ' → ', who(event.steamid, event.name)];
    if (event.kind === 'ban_kick') content = [who(event.steamid, event.name), ` ${t('feed.ban_kick')}`, event.text ? h('span', { class: 'feed-text' }, ` · ${event.text}`) : null];
    if (event.kind === 'action') content = [h('span', { class: 'feed-text' }, event.text)];
    return h(
      'div',
      { class: `feed-line event ev-${event.kind}` },
      h('span', { class: 'feed-time' }, time(event.at)),
      h('span', { class: 'feed-body' }, icon(icons[event.kind] || 'gavel', 13, 'feed-icon'), content)
    );
  }

  function paintList() {
    const shown = (events || []).filter((event) => {
      if (filter === 'all') return true;
      if (filter === 'chat') return CHAT_KINDS.includes(event.kind);
      return !CHAT_KINDS.includes(event.kind);
    });
    list.replaceChildren();
    if (events && shown.length === 0) list.append(h('div', { class: 'feed-empty' }, t(live ? 'feed.empty' : 'feed.empty_offline')));
    for (const event of shown) list.append(line(event));
    if (stick) list.scrollTop = list.scrollHeight;
  }

  let loading = false;

  async function load() {
    if (loading) return;
    loading = true;
    try {
      const fresh = (await api.get('feed', { id: serverId, after: lastId || '' })).filter((event) => event.id > lastId);
      if (fresh.length) lastId = fresh[fresh.length - 1].id;
      if (fresh.length || events === null) {
        events = (events || []).concat(fresh).slice(-400);
        paintList();
      }
    } catch (error) {
      if (events === null) {
        events = [];
        paintList();
      }
    } finally {
      loading = false;
    }
  }

  const blocks = [
    h('div', { class: 'block-head' }, h('h2', { class: 'with-icon' }, icon('messages-square', 18), t('feed.title')), filters),
    list
  ];

  let targetSelect = null;
  let input = null;
  let send = null;
  if (can('players.chat')) {
    targetSelect = createSelect({
      className: 'feed-target',
      opensUp: true,
      options: [{ value: '', label: t('feed.to_all') }],
      value: '',
      ariaLabel: t('feed.to'),
      onChange: (value) => {
        target = value;
        paintCompose();
      }
    });
    input = h('input', { maxlength: '200', 'aria-label': t('feed.placeholder') });
    send = h('button', { type: 'submit', class: 'btn btn-primary btn-icon-lg', title: t('feed.send'), 'aria-label': t('feed.send') }, icon('send', 16));
    input.addEventListener('input', paintCompose);
    const compose = h('form', { class: 'feed-compose' }, targetSelect.element, input, send);
    compose.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = input.value.trim();
      if (!message || busy) return;
      busy = true;
      paintCompose();
      try {
        await api.post('say', { id: serverId, text: message, steamid: target || undefined });
        input.value = '';
        stick = true;
        setTimeout(load, 400);
      } catch (error) {
        toast(errorText(error), 'error');
      } finally {
        busy = false;
        paintCompose();
      }
    });
    blocks.push(compose);
  }

  function paintCompose() {
    if (!input) return;
    input.disabled = !live;
    input.placeholder = live ? t(target ? 'feed.placeholder_pm' : 'feed.placeholder') : t('feed.offline');
    send.disabled = !live || busy || !input.value.trim();
  }

  holder.replaceWith(h('div', { class: 'card panel-block feed' }, blocks));
  paintFilters();
  paintCompose();
  load();

  let timer = null;
  const schedule = () => {
    clearInterval(timer);
    timer = setInterval(() => {
      if (document.visibilityState === 'visible') load();
    }, live ? 2000 : 15000);
  };
  schedule();

  return {
    update(nextLive, players) {
      const changed = nextLive !== live;
      live = nextLive;
      humans = players.filter((player) => player.steamid && !player.bot);
      if (target && !humans.some((player) => player.steamid === target)) target = '';
      if (targetSelect) {
        targetSelect.setOptions([{ value: '', label: t('feed.to_all') }].concat(humans.map((player) => ({ value: player.steamid, label: player.name }))));
        targetSelect.setValue(target);
      }
      paintCompose();
      if (changed) {
        schedule();
        paintList();
      }
    }
  };
}

function startConsole(holder, serverId) {
  const history = [];
  let cursor = -1;
  let busy = false;
  const output = h('pre', { class: 'console-output' });
  const input = h('input', { placeholder: t('servers.command'), 'aria-label': t('servers.command') });
  const submit = h('button', { type: 'submit', class: 'btn btn-primary' }, t('servers.send'));
  const quick = ['status', 'vd_sync', 'vd_reload', 'meta list'].map((command) =>
    h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => run(command) }, command)
  );

  function add(kind, text) {
    output.append(h('div', { class: `console-${kind}` }, text));
    output.scrollTop = output.scrollHeight;
  }

  function setBusy(value) {
    busy = value;
    input.disabled = value;
    submit.disabled = value;
    quick.forEach((button) => (button.disabled = value));
  }

  async function run(command) {
    const text = command.trim();
    if (!text || busy) return;
    setBusy(true);
    const index = history.indexOf(text);
    if (index >= 0) history.splice(index, 1);
    history.unshift(text);
    history.length = Math.min(history.length, 50);
    cursor = -1;
    input.value = '';
    add('in', `> ${text}`);
    try {
      const data = await api.post('rcon', { id: serverId, command: text });
      add('out', data.output || '—');
    } catch (error) {
      add('err', errorText(error));
    } finally {
      setBusy(false);
      input.focus();
    }
  }

  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowUp' && history.length) {
      event.preventDefault();
      cursor = Math.min(cursor + 1, history.length - 1);
      input.value = history[cursor];
    } else if (event.key === 'ArrowDown') {
      event.preventDefault();
      cursor = Math.max(cursor - 1, -1);
      input.value = cursor >= 0 ? history[cursor] : '';
    }
  });

  const form = h('form', { class: 'console-input' }, input, submit);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    run(input.value);
  });

  holder.replaceWith(
    h(
      'div',
      { class: 'card console' },
      h('div', { class: 'console-head' }, h('h2', { class: 'with-icon' }, icon('terminal', 18), t('servers.console')), h('div', { class: 'actions' }, quick)),
      output,
      form
    )
  );
}
