const GROUP_PRESETS = {
  head_admin: {
    flags: 'abcdefghijkm',
    immunity: 90,
    web_permissions: [
      'bans.add', 'bans.edit', 'bans.delete', 'comms.add', 'comms.edit', 'comms.delete',
      'players.view', 'players.kick', 'players.control', 'players.chat', 'players.ip', 'servers.manage', 'servers.rcon',
      'admins.manage', 'groups.manage', 'logs.view'
    ]
  },
  admin: {
    flags: 'abcdefgjk',
    immunity: 50,
    web_permissions: ['bans.add', 'bans.edit', 'comms.add', 'comms.edit', 'players.view', 'players.kick', 'players.control', 'players.chat', 'players.ip', 'logs.view']
  },
  moderator: {
    flags: 'abcdj',
    immunity: 20,
    web_permissions: ['bans.add', 'comms.add', 'players.view', 'players.kick', 'players.chat']
  },
  helper: {
    flags: 'bcj',
    immunity: 5,
    web_permissions: ['comms.add', 'players.view']
  },
  vip: {
    flags: 'ao',
    immunity: 0,
    web_permissions: []
  }
};
const TEMPLATE_ICONS = { head_admin: 'crown', admin: 'shield-check', moderator: 'shield', helper: 'life-buoy', vip: 'gem' };

function cancelButton(modal) {
  return h('button', { type: 'button', class: 'btn btn-ghost', onclick: modal.close }, t('common.cancel'));
}

function slot() {
  return h('div', { class: 'slot' });
}

function fill(holder, node) {
  holder.replaceChildren();
  if (node) holder.append(node);
}

function ipCard(ip, name, onChange) {
  return h(
    'div',
    { class: 'player-card compact' },
    h('span', { class: 'player-avatar placeholder' }, icon('globe', 18)),
    h('div', { class: 'player-card-body' }, h('div', { class: 'player-card-name' }, ip), h('span', { class: 'muted small' }, name || t('punish.by_ip'))),
    onChange ? h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: onChange }, t('punish.change')) : null
  );
}

function openPunishModal({ kind, record, initial = {}, onDone }) {
  const isComms = kind === 'comms';
  const editing = !!record;
  const known = editing || !!initial.steamid;
  const steps = editing ? ['details'] : [].concat(isComms || !known ? ['mode'] : [], known ? [] : ['target'], ['details']);
  let step = steps[0];
  let mode = !isComms && editing && !record.steamid ? 'ip' : known || isComms ? 'steamid' : null;
  const source = record || initial;
  const form = {
    steamid: source.steamid || '',
    name: source.name || '',
    ip: source.ip || '',
    type: source.type || '',
    duration: record ? record.duration : 1440,
    reason: record ? record.reason : '',
    server_id: source.server_id ? String(source.server_id) : ''
  };
  let query = form.steamid;
  let busy = false;

  const modal = openModal({ title: '' });
  const cardSlot = slot();
  const errorSlot = slot();
  let nextButton = null;

  const lookup = steamLookup(() => {
    paintCard();
    paintNext();
  });
  if (mode === 'steamid' && query) lookup.set(query);

  const canContinue = () => (mode === 'ip' ? form.ip.trim() !== '' : lookup.state.status === 'found');

  function title() {
    const base = t(`punish.${editing ? 'edit' : 'add'}_${isComms ? 'comm' : 'ban'}`);
    if (editing || step === 'mode') return base;
    if (isComms) return `${base} · ${t(`type.${form.type}`)}`;
    if (!known) return `${base} · ${t(`punish.by_${mode}`)}`;
    return base;
  }

  function go(next) {
    step = next;
    render();
  }

  function paintNext() {
    if (nextButton) nextButton.disabled = !canContinue();
  }

  function paintCard() {
    if (step === 'target' && mode === 'steamid') {
      fill(cardSlot, playerCard(lookup.state));
    } else if (step === 'details') {
      if (mode === 'ip') {
        fill(cardSlot, ipCard(form.ip, form.name, known ? null : () => go('target')));
      } else {
        fill(cardSlot, playerCard(lookup.state, { fallback: { steamid: form.steamid, name: form.name }, compact: true, onChange: known ? null : () => go('target') }));
      }
    }
  }

  function backButton() {
    const index = steps.indexOf(step);
    if (index <= 0) return null;
    return h('button', { type: 'button', class: 'btn btn-ghost', onclick: () => go(steps[index - 1]) }, t('common.prev'));
  }

  function renderMode() {
    const options = isComms
      ? [['mute', 'mic-off'], ['gag', 'message-square-off'], ['silence', 'ban']]
      : [['steamid', 'user-round'], ['ip', 'globe']];
    const current = isComms ? form.type : mode;
    const choice = h(
      'div',
      { class: 'mode-choice' },
      h('div', { class: 'mode-choice-title' }, t(isComms ? 'punish.choose_type' : 'punish.choose_mode')),
      options.map(([option, iconName], index) =>
        h(
          'button',
          {
            type: 'button',
            class: classes('mode-option', current === option && 'selected'),
            autofocus: current === option || (!current && index === 0),
            onclick: () => {
              if (isComms) form.type = option;
              else mode = option;
              if (mode === 'steamid' && query) lookup.set(query);
              go(steps[steps.indexOf('mode') + 1]);
            }
          },
          h('span', { class: 'mode-option-icon' }, icon(iconName, 20)),
          h(
            'span',
            { class: 'mode-option-text' },
            h('b', null, t(isComms ? `type.${option}` : `punish.by_${option}`)),
            h('span', null, t(isComms ? `punish.type_${option}_desc` : `punish.by_${option}_desc`))
          ),
          icon('chevron-right', 18, 'mode-option-arrow')
        )
      )
    );
    modal.setBody(stepsBar(steps.length, steps.indexOf(step)), choice);
    modal.setFooter(cancelButton(modal));
  }

  function renderTarget() {
    let fields;
    if (mode === 'steamid') {
      const input = h('input', { value: query, spellcheck: 'false', autofocus: true });
      input.addEventListener('input', () => {
        query = input.value;
        lookup.set(query);
      });
      fields = [field(t('punish.steamid'), input, { placeholder: t('punish.steamid_placeholder') }), cardSlot];
    } else {
      const ipInput = h('input', { value: form.ip, spellcheck: 'false', autofocus: true });
      ipInput.addEventListener('input', () => {
        form.ip = ipInput.value;
        paintNext();
      });
      const nameInput = h('input', { value: form.name, maxlength: '64' });
      nameInput.addEventListener('input', () => (form.name = nameInput.value));
      fields = [field(t('punish.ip'), ipInput, { placeholder: t('punish.ip_placeholder') }), field(`${t('punish.name')} (${t('common.optional')})`, nameInput)];
    }
    const formElement = h('form', { id: 'punish-target', class: 'form' }, fields);
    formElement.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!canContinue()) return;
      if (mode === 'steamid') {
        form.steamid = lookup.state.profile.steamid;
        form.name = lookup.state.profile.name || '';
      }
      go('details');
    });
    nextButton = h('button', { type: 'submit', form: 'punish-target', class: 'btn btn-primary' }, t('common.next'), icon('chevron-right', 16));
    modal.setBody(stepsBar(steps.length, steps.indexOf(step)), formElement);
    modal.setFooter(backButton(), cancelButton(modal), nextButton);
    paintCard();
    paintNext();
  }

  function renderDetails() {
    const children = [cardSlot];
    if (editing) {
      const nameInput = h('input', { value: form.name, maxlength: '64' });
      nameInput.addEventListener('input', () => (form.name = nameInput.value));
      children.push(field(t('punish.name'), nameInput));
    }
    if (isComms && editing) {
      const typeSelect = createSelect({
        options: ['mute', 'gag', 'silence'].map((type) => ({ value: type, label: t(`type.${type}`) })),
        value: form.type,
        disabled: true,
        ariaLabel: t('punish.type')
      });
      children.push(field(t('punish.type'), typeSelect.element));
    }
    children.push(field(t('punish.duration'), durationField(form.duration, (value) => (form.duration = value))));
    const reasonInput = h('input', { value: form.reason, maxlength: '255' });
    reasonInput.addEventListener('input', () => (form.reason = reasonInput.value));
    children.push(field(t('punish.reason'), reasonInput, { placeholder: t('punish.reason_placeholder') }));
    if (!editing) {
      const options = [{ value: '', label: t('punish.any_server') }].concat((Verdict.servers || []).map((server) => ({ value: String(server.id), label: server.name })));
      const serverSelect = createSelect({ options, value: form.server_id, ariaLabel: t('punish.server'), onChange: (value) => (form.server_id = value) });
      children.push(field(t('punish.server'), serverSelect.element));
    }
    children.push(errorSlot);

    const formElement = h('form', { id: 'punish-form', class: 'form' }, children);
    const save = h('button', { type: 'submit', form: 'punish-form', class: 'btn btn-danger' }, t('common.save'));
    formElement.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (busy) return;
      busy = true;
      save.disabled = true;
      fill(errorSlot, null);
      try {
        if (editing) {
          await api.post('punish_edit', { kind, id: record.id, name: form.name, reason: form.reason, duration: form.duration });
        } else {
          await api.post('punish_add', {
            kind,
            steamid: mode === 'ip' ? '' : form.steamid,
            ip: mode === 'ip' ? form.ip : '',
            name: form.name,
            type: form.type,
            duration: form.duration,
            reason: form.reason,
            server_id: form.server_id
          });
        }
        modal.close();
        if (onDone) onDone(t('toast.saved'));
        else reloadWithToast(t('toast.saved'));
      } catch (error) {
        fill(errorSlot, errorLine(error));
        busy = false;
        save.disabled = false;
      }
    });
    nextButton = null;
    modal.setBody(stepsBar(steps.length, steps.indexOf(step)), formElement);
    modal.setFooter(backButton(), cancelButton(modal), save);
    paintCard();
    if (!editing) setTimeout(() => reasonInput.focus(), 0);
  }

  function render() {
    modal.setTitle(title());
    if (step === 'mode') renderMode();
    else if (step === 'target') renderTarget();
    else renderDetails();
  }

  render();
}

function openReasonModal({ title, confirmLabel, danger = true, onSubmit }) {
  const modal = openModal({ title });
  const input = h('input', { maxlength: '255', autofocus: true });
  const errorSlot = slot();
  const submit = h('button', { type: 'submit', form: 'reason-form', class: classes('btn', danger ? 'btn-danger' : 'btn-primary') }, confirmLabel);
  const formElement = h('form', { id: 'reason-form', class: 'form' }, field(t('punish.reason'), input, { placeholder: t('punish.reason_placeholder') }), errorSlot);
  formElement.addEventListener('submit', async (event) => {
    event.preventDefault();
    submit.disabled = true;
    fill(errorSlot, null);
    try {
      await onSubmit(input.value);
      modal.close();
    } catch (error) {
      fill(errorSlot, errorLine(error));
      submit.disabled = false;
    }
  });
  modal.setBody(formElement);
  modal.setFooter(cancelButton(modal), submit);
}

function wizardFooter(modal, { step, count, editing, formId, canContinue, onBack, onSave }) {
  const isLast = step === count - 1;
  const buttons = [];
  if (step > 0) buttons.push(h('button', { type: 'button', class: 'btn btn-ghost', onclick: onBack }, t('common.prev')));
  buttons.push(cancelButton(modal));
  let next = null;
  if (!isLast) {
    next = h('button', { type: 'submit', form: formId, class: classes('btn', editing ? 'btn-ghost' : 'btn-primary'), disabled: !canContinue }, t('common.next'), icon('chevron-right', 16));
    buttons.push(next);
  }
  let save = null;
  if (isLast || editing) {
    save = h('button', { type: isLast ? 'submit' : 'button', form: formId, class: 'btn btn-primary', disabled: !canContinue }, t('common.save'));
    if (!isLast) save.addEventListener('click', onSave);
    buttons.push(save);
  }
  modal.setFooter(buttons);
  return { next, save };
}

function grantablePreset(preset) {
  const user = Verdict.user;
  if (user.owner) return preset;
  return {
    flags: preset.flags.split('').filter((flag) => !PROTECTED_FLAGS.includes(flag)).join(''),
    web_permissions: preset.web_permissions.filter((permission) => user.permissions.includes(permission)),
    immunity: Math.min(preset.immunity, Math.max(0, user.immunity - 1))
  };
}

function openGroupModal(group) {
  const user = Verdict.user;
  const steps = group ? ['main', 'panel', 'flags'] : ['template', 'main', 'panel', 'flags'];
  let step = 0;
  let template = null;
  let busy = false;
  const form = {
    name: group ? group.name : '',
    flags: group ? group.flags : '',
    immunity: group ? group.immunity : 0,
    web_permissions: group ? group.web_permissions.slice() : [],
    max_ban: group ? group.max_ban : 0,
    max_mute: group ? group.max_mute : 0
  };
  const modal = openModal({ title: t(group ? 'groups.edit' : 'groups.add'), wide: true });
  const errorSlot = slot();
  let buttons = { next: null, save: null };

  const canContinue = () => steps[step] !== 'main' || form.name.trim() !== '';
  const paintButtons = () => {
    if (buttons.next) buttons.next.disabled = !canContinue();
    if (buttons.save) buttons.save.disabled = busy || !canContinue();
  };

  function go(next) {
    step = next;
    render();
  }

  function pickTemplate(key) {
    const preset = key ? grantablePreset(GROUP_PRESETS[key]) : { flags: '', immunity: 0, web_permissions: [] };
    const autoName = !form.name || (template && form.name === t(`groups.presets.${template}`));
    form.flags = preset.flags;
    form.immunity = preset.immunity;
    form.web_permissions = preset.web_permissions.slice();
    if (autoName) form.name = key ? t(`groups.presets.${key}`) : '';
    template = key;
    go(step + 1);
  }

  async function save() {
    if (busy) return;
    busy = true;
    paintButtons();
    fill(errorSlot, null);
    try {
      await api.post('group_save', Object.assign({ id: group ? group.id : 0 }, form));
      modal.close();
      reloadWithToast(t('toast.saved'));
    } catch (error) {
      fill(errorSlot, errorLine(error));
      busy = false;
      paintButtons();
    }
  }

  function renderTemplate() {
    const grid = h('div', { class: 'template-grid' });
    Object.keys(GROUP_PRESETS).forEach((key, index) => {
      const preset = grantablePreset(GROUP_PRESETS[key]);
      grid.append(
        h(
          'button',
          { type: 'button', class: classes('mode-option template-option', template === key && 'selected'), autofocus: template ? template === key : index === 0, onclick: () => pickTemplate(key) },
          h('span', { class: 'mode-option-icon' }, icon(TEMPLATE_ICONS[key], 20)),
          h(
            'span',
            { class: 'mode-option-text' },
            h('b', null, t(`groups.presets.${key}`)),
            h('span', null, t(`groups.preset_desc.${key}`)),
            h(
              'span',
              { class: 'template-meta' },
              h('span', { class: 'tag' }, t('groups.meta_immunity', { n: preset.immunity })),
              h('span', { class: 'tag' }, t('groups.meta_permissions', { n: preset.web_permissions.length })),
              h('span', { class: 'tag' }, t('groups.meta_flags', { n: preset.flags.length }))
            )
          )
        )
      );
    });
    grid.append(
      h(
        'button',
        { type: 'button', class: classes('mode-option template-option template-blank', template === '' && 'selected'), onclick: () => pickTemplate('') },
        h('span', { class: 'mode-option-icon' }, icon('file-plus', 20)),
        h('span', { class: 'mode-option-text' }, h('b', null, t('groups.blank')), h('span', null, t('groups.blank_desc')))
      )
    );
    return h('div', { class: 'mode-choice' }, h('div', { class: 'mode-choice-title' }, t('groups.choose_template')), grid);
  }

  function numberInput(key, max) {
    const input = h('input', { type: 'number', min: '0', max: max ? String(max) : null, value: String(form[key]) });
    input.addEventListener('input', () => (form[key] = Number(input.value) || 0));
    return input;
  }

  function renderForm() {
    const current = steps[step];
    const children = [];
    if (current === 'main') {
      const nameInput = h('input', { value: form.name, maxlength: '64', autofocus: true });
      nameInput.addEventListener('input', () => {
        form.name = nameInput.value;
        paintButtons();
      });
      children.push(
        h('div', { class: 'row-2' }, field(t('groups.name'), nameInput, { placeholder: t('groups.name_placeholder') }), field(t('admins.immunity'), numberInput('immunity', 100), { hint: t('groups.immunity_hint') })),
        h('div', { class: 'row-2' }, field(t('groups.max_ban'), numberInput('max_ban'), { hint: t('groups.max_hint') }), field(t('groups.max_mute'), numberInput('max_mute'), { hint: t('groups.max_hint') }))
      );
    }
    if (current === 'panel') {
      children.push(
        field(
          t('groups.panel'),
          permissionsField(form.web_permissions, (value) => (form.web_permissions = value), user.owner ? null : user.permissions, user.owner),
          { hint: t('groups.panel_hint') }
        )
      );
    }
    if (current === 'flags') {
      children.push(field(t('groups.game'), flagsField(form.flags, (value) => (form.flags = value), user.owner ? [] : PROTECTED_FLAGS), { hint: t('groups.game_hint') }));
    }
    children.push(errorSlot);
    const formElement = h('form', { id: 'group-form', class: 'form' }, children);
    formElement.addEventListener('submit', (event) => {
      event.preventDefault();
      if (step === steps.length - 1) save();
      else if (canContinue()) go(step + 1);
    });
    return formElement;
  }

  function render() {
    const current = steps[step];
    const bar = stepsBar(steps.length, step, steps.map((key) => t(`groups.steps.${key}`)), group && canContinue() ? go : null);
    if (current === 'template') {
      modal.setBody(bar, renderTemplate());
      modal.setFooter(cancelButton(modal));
      buttons = { next: null, save: null };
      return;
    }
    modal.setBody(bar, renderForm());
    buttons = wizardFooter(modal, {
      step,
      count: steps.length,
      editing: !!group,
      formId: 'group-form',
      canContinue: canContinue(),
      onBack: () => go(step - 1),
      onSave: save
    });
    paintButtons();
  }

  render();
}

function localInputFromSeconds(seconds) {
  if (!seconds) return '';
  return serializeLocalDate(new Date(seconds * 1000));
}

function openAdminModal(admin) {
  const user = Verdict.user;
  const steps = ['player', 'group', 'access', 'panel', 'flags'];
  const isSelf = !!admin && admin.id === user.id;
  let step = 0;
  let busy = false;
  let panel = !!admin && admin.panel;
  const form = {
    steamid: admin && admin.steamid ? admin.steamid : '',
    name: admin ? admin.username : '',
    username: admin && admin.panel ? admin.username : '',
    password: '',
    group_id: admin && admin.group_id ? String(admin.group_id) : '',
    web_permissions: admin ? admin.web_permissions.slice() : [],
    flags: admin ? admin.flags : '',
    immunity: admin ? admin.immunity : 0,
    expires_at: admin ? localInputFromSeconds(admin.expires_at) : '',
    servers: admin ? admin.servers.slice() : [],
    reset_2fa: false
  };
  const modal = openModal({ title: t(admin ? 'admins.edit' : 'admins.add'), wide: true });
  const errorSlot = slot();
  const cardSlot = slot();
  let buttons = { next: null, save: null };

  const lookup = steamLookup(() => {
    if (steps[step] === 'player') fill(cardSlot, playerCard(lookup.state));
    paintButtons();
  });
  if (form.steamid) lookup.set(form.steamid);

  const canContinue = () => steps[step] !== 'player' || !form.steamid.trim() || lookup.state.status === 'found';
  const paintButtons = () => {
    if (buttons.next) buttons.next.disabled = !canContinue();
    if (buttons.save) buttons.save.disabled = busy || !canContinue();
  };
  const groups = Verdict.groups || [];
  const servers = Verdict.servers || [];

  function go(next) {
    step = next;
    render();
  }

  async function save() {
    if (busy) return;
    busy = true;
    paintButtons();
    fill(errorSlot, null);
    const profile = lookup.state.profile;
    const expires = parseLocalDate(form.expires_at);
    const body = {
      id: admin ? admin.id : 0,
      steamid: profile ? profile.steamid : form.steamid,
      name: profile && profile.name ? profile.name : form.name,
      panel,
      username: panel ? form.username : '',
      password: panel ? form.password : '',
      group_id: form.group_id,
      web_permissions: panel ? form.web_permissions : [],
      flags: form.flags,
      immunity: form.immunity,
      expires_at: expires ? Math.floor(expires.getTime() / 1000) : 0,
      servers: form.servers,
      reset_2fa: form.reset_2fa
    };
    try {
      await api.post('admin_save', body);
      modal.close();
      reloadWithToast(t('toast.saved'));
    } catch (error) {
      fill(errorSlot, errorLine(error));
      busy = false;
      paintButtons();
    }
  }

  function checkbox(checked, label, onChange, disabled) {
    const input = h('input', { type: 'checkbox', checked, disabled });
    input.addEventListener('change', () => onChange(input.checked));
    return h('label', { class: 'check' }, input, h('span', null, label));
  }

  function renderStep() {
    const current = steps[step];
    const children = [];

    if (current === 'player') {
      const input = h('input', { value: form.steamid, spellcheck: 'false', autofocus: true });
      const hint = h('span', { class: 'field-hint' }, t('admins.no_steamid_hint'));
      hint.hidden = !!form.steamid.trim();
      input.addEventListener('input', () => {
        form.steamid = input.value;
        hint.hidden = !!form.steamid.trim();
        lookup.set(form.steamid);
      });
      const wrapper = field(t('punish.steamid'), input, { placeholder: t('punish.steamid_placeholder') });
      wrapper.append(hint);
      children.push(wrapper, cardSlot);
      fill(cardSlot, playerCard(lookup.state));
    }

    if (current === 'group') {
      const group = () => groups.find((item) => String(item.id) === String(form.group_id));
      const groupHint = h('span', { class: 'field-hint' });
      const immunityHint = h('span', { class: 'field-hint' });
      const paintHints = () => {
        const selected = group();
        groupHint.textContent = selected ? t('admins.group_hint') : '';
        groupHint.hidden = !selected;
        immunityHint.textContent = selected ? t('admins.immunity_hint', { n: selected.immunity }) : '';
        immunityHint.hidden = !selected;
      };
      const select = createSelect({
        options: [{ value: '', label: t('admins.no_group') }].concat(groups.map((item) => ({ value: String(item.id), label: item.name }))),
        value: form.group_id,
        ariaLabel: t('admins.group'),
        onChange: (value) => {
          form.group_id = value;
          paintHints();
        }
      });
      const immunity = h('input', { type: 'number', min: '0', max: '100', value: String(form.immunity) });
      immunity.addEventListener('input', () => (form.immunity = Number(immunity.value) || 0));
      const groupField = field(t('admins.group'), select.element);
      groupField.append(groupHint);
      const immunityField = field(t('admins.immunity'), immunity);
      immunityField.append(immunityHint);
      paintHints();
      children.push(h('div', { class: 'row-2' }, groupField, immunityField));
    }

    if (current === 'access') {
      children.push(
        field(
          t('admins.expires'),
          dateTimePicker({ value: form.expires_at, onChange: (value) => (form.expires_at = value), min: new Date(), placeholder: t('admins.never'), ariaLabel: t('admins.expires') })
        )
      );
      if (servers.length) {
        const grid = h('div', { class: 'flags-grid' });
        for (const server of servers) {
          grid.append(
            checkbox(form.servers.includes(server.id), server.name, (checked) => {
              form.servers = checked ? form.servers.concat(server.id) : form.servers.filter((id) => id !== server.id);
            })
          );
        }
        children.push(field(t('admins.servers'), grid, { hint: t('admins.all_servers') }));
      }
    }

    if (current === 'panel') {
      const box = h('div', { class: 'section-box' });
      box.append(
        checkbox(panel, h('b', null, t('admins.panel_toggle')), (checked) => {
          panel = checked;
          render();
        }, isSelf)
      );
      if (panel) {
        const username = h('input', { value: form.username, autocomplete: 'off', disabled: isSelf });
        username.addEventListener('input', () => (form.username = username.value));
        const password = h('input', { type: 'password', value: form.password, autocomplete: 'new-password', disabled: isSelf });
        password.addEventListener('input', () => (form.password = password.value));
        const passwordHint = isSelf ? t('security.self_password') : admin && admin.panel ? t('admins.password_keep') : t('security.password_rules');
        box.append(
          h('div', { class: 'row-2' }, field(t('admins.username'), username), field(t('admins.password'), password, { hint: passwordHint })),
          field(
            t('admins.extra_permissions'),
            permissionsField(form.web_permissions, (value) => (form.web_permissions = value), user.owner ? null : user.permissions, user.owner),
            { hint: t('admins.extra_permissions_hint') }
          )
        );
        if (admin && admin.totp_enabled && !isSelf) {
          box.append(checkbox(form.reset_2fa, t('security.reset_2fa'), (checked) => (form.reset_2fa = checked)));
        }
      }
      children.push(box);
    }

    if (current === 'flags') {
      children.push(
        field(t('admins.extra_flags'), flagsField(form.flags, (value) => (form.flags = value), user.owner ? [] : PROTECTED_FLAGS), { hint: t('admins.extra_flags_hint') })
      );
    }

    children.push(errorSlot);
    const formElement = h('form', { id: 'admin-form', class: 'form' }, children);
    formElement.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!canContinue()) return;
      if (step === steps.length - 1) {
        save();
        return;
      }
      if (current === 'player') {
        if (lookup.state.profile) {
          form.steamid = lookup.state.profile.steamid;
          if (!admin && lookup.state.profile.name) form.name = lookup.state.profile.name;
        } else if (!form.steamid.trim()) {
          panel = true;
        }
      }
      go(step + 1);
    });
    return formElement;
  }

  function render() {
    const bar = stepsBar(steps.length, step, steps.map((key) => t(`admins.steps.${key}`)), admin && canContinue() ? go : null);
    modal.setBody(bar, renderStep());
    buttons = wizardFooter(modal, {
      step,
      count: steps.length,
      editing: !!admin,
      formId: 'admin-form',
      canContinue: canContinue(),
      onBack: () => go(step - 1),
      onSave: save
    });
    paintButtons();
  }

  render();
}

function openServerModal(server) {
  const steps = ['address', 'rcon'];
  let step = 0;
  let busy = false;
  const form = {
    name: server ? server.name : '',
    ip: server ? server.ip : '',
    port: server ? server.port : 27015,
    rcon_password: '',
    clear_rcon: false
  };
  const modal = openModal({ title: t(server ? 'servers.edit' : 'servers.add') });
  const errorSlot = slot();
  let buttons = { next: null, save: null };

  const canContinue = () => !!String(form.ip).trim() && Number(form.port) >= 1 && Number(form.port) <= 65535;
  const paintButtons = () => {
    if (buttons.next) buttons.next.disabled = !canContinue();
    if (buttons.save) buttons.save.disabled = busy || !canContinue();
  };

  function go(next) {
    step = next;
    render();
  }

  async function save() {
    if (busy) return;
    busy = true;
    paintButtons();
    fill(errorSlot, null);
    try {
      await api.post('server_save', { id: server ? server.id : 0, ip: form.ip, port: form.port, rcon_password: form.rcon_password, clear_rcon: form.clear_rcon });
      modal.close();
      reloadWithToast(t('toast.saved'));
    } catch (error) {
      fill(errorSlot, errorLine(error));
      busy = false;
      paintButtons();
    }
  }

  function renderStep() {
    const children = [];
    if (step === 0) {
      children.push(
        serverAddress({
          ip: form.ip,
          port: form.port,
          probeAction: 'server_query',
          autofocus: true,
          onChange: (patch) => {
            Object.assign(form, patch);
            paintButtons();
          }
        })
      );
    } else {
      children.push(
        h(
          'div',
          { class: 'player-card compact' },
          h('span', { class: 'player-avatar placeholder' }, icon('server', 18)),
          h('div', { class: 'player-card-body' }, h('div', { class: 'player-card-name' }, form.name || `${form.ip}:${form.port}`), h('span', { class: 'muted small' }, `${form.ip}:${form.port}`)),
          h('button', { type: 'button', class: 'btn btn-ghost btn-sm', onclick: () => go(0) }, t('punish.change'))
        )
      );
      const password = h('input', { type: 'password', value: form.rcon_password, autocomplete: 'new-password', autofocus: true });
      password.addEventListener('input', () => (form.rcon_password = password.value));
      children.push(field(t('servers.rcon_password'), password, { hint: server ? t('servers.rcon_keep') : t('install.rcon_hint') }));
      if (server && server.has_rcon) {
        const clear = h('input', { type: 'checkbox', checked: form.clear_rcon });
        clear.addEventListener('change', () => (form.clear_rcon = clear.checked));
        children.push(h('label', { class: 'check' }, clear, h('span', null, t('servers.clear_rcon'))));
      }
    }
    children.push(errorSlot);
    const formElement = h('form', { id: 'server-form', class: 'form' }, children);
    formElement.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!canContinue()) return;
      if (step === steps.length - 1) save();
      else go(step + 1);
    });
    return formElement;
  }

  function render() {
    const bar = stepsBar(steps.length, step, steps.map((key) => t(`servers.steps.${key}`)), server && canContinue() ? go : null);
    modal.setBody(bar, renderStep());
    buttons = wizardFooter(modal, {
      step,
      count: steps.length,
      editing: !!server,
      formId: 'server-form',
      canContinue: canContinue(),
      onBack: () => go(step - 1),
      onSave: save
    });
    paintButtons();
  }

  render();
}

function codeBlock(path, text, fileName) {
  const copy = h('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, icon('copy', 15), t('install.copy'));
  copy.addEventListener('click', () => copyText(copy, text));
  const download = h('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, icon('download', 15), t('install.download'));
  download.addEventListener('click', () => downloadText(fileName, text));
  return h(
    'div',
    null,
    h('div', { class: 'code-head' }, h('span', { class: 'field-label' }, path), h('div', { class: 'actions' }, copy, download)),
    h('pre', { class: 'code-block' }, text)
  );
}

function copyText(button, text) {
  const done = () => {
    const label = button.lastChild;
    const original = label.textContent;
    label.textContent = t('install.copied');
    setTimeout(() => (label.textContent = original), 2000);
  };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(() => {});
    return;
  }
  const area = h('textarea', { style: { position: 'fixed', opacity: '0' } }, text);
  document.body.append(area);
  area.select();
  try {
    document.execCommand('copy');
    done();
  } catch (e) {}
  area.remove();
}

function downloadText(fileName, text) {
  const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
  const link = h('a', { href: url, download: fileName });
  document.body.append(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

async function openPluginConfigModal(serverId) {
  const modal = openModal({ title: t('security.plugin_config'), wide: true });
  modal.setBody(h('div', { class: 'form' }, h('div', { class: 'state' }, t('common.loading'))));
  modal.setFooter(h('button', { type: 'button', class: 'btn btn-primary', onclick: modal.close }, t('common.close')));
  try {
    const data = await api.get('plugin_config', { id: serverId });
    modal.setBody(
      h(
        'div',
        { class: 'form' },
        codeBlock('addons/configs/databases.cfg', data.databases, 'databases.cfg'),
        codeBlock('addons/configs/verdict/core.ini', data.core, 'core.ini'),
        h('div', { class: 'notice' }, t('security.plugin_db_note')),
        data.local ? h('div', { class: 'notice' }, t('install.host_note')) : null
      )
    );
  } catch (error) {
    modal.setBody(h('div', { class: 'form' }, h('div', { class: 'state state-error' }, errorText(error))));
  }
}
