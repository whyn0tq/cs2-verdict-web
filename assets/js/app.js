function currentTheme() {
  return root.dataset.theme === 'light' ? 'light' : 'dark';
}

function paintThemeButtons() {
  const theme = currentTheme();
  const label = t(theme === 'light' ? 'theme.to_dark' : 'theme.to_light');
  document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
    button.className = `theme-toggle is-${theme}`;
    button.setAttribute('aria-label', label);
    button.title = label;
  });
}

function applyTheme(theme) {
  root.classList.add('theme-swap');
  root.dataset.theme = theme;
  try {
    localStorage.setItem('theme', theme);
  } catch (e) {}
  paintThemeButtons();
  requestAnimationFrame(() => requestAnimationFrame(() => root.classList.remove('theme-swap')));
}

function toggleTheme(event) {
  const next = currentTheme() === 'light' ? 'dark' : 'light';
  if (!document.startViewTransition || matchMedia('(prefers-reduced-motion: reduce)').matches) {
    applyTheme(next);
    return;
  }
  const rect = event.currentTarget.getBoundingClientRect();
  const x = rect.left + rect.width / 2;
  const y = rect.top + rect.height / 2;
  const radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
  const transition = document.startViewTransition(() => applyTheme(next));
  const stall = setTimeout(() => transition.skipTransition(), 1000);
  transition.ready
    .then(() => {
      root.animate(
        { clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`] },
        { duration: 700, easing: 'cubic-bezier(0.65, 0, 0.35, 1)', pseudoElement: '::view-transition-new(root)' }
      );
    })
    .catch(() => {})
    .finally(() => clearTimeout(stall));
}

function setupPreferences() {
  paintThemeButtons();
  document.querySelectorAll('[data-theme-toggle]').forEach((button) => button.addEventListener('click', toggleTheme));
  document.querySelectorAll('[data-lang]').forEach((button) => {
    button.addEventListener('click', () => {
      document.cookie = `lang=${button.dataset.lang}; path=${Verdict.base}; max-age=31536000; samesite=lax`;
      window.location.reload();
    });
  });
}

function setupSidebar() {
  const sidebar = document.querySelector('.sidebar');
  const backdrop = document.querySelector('.sidebar-backdrop');
  if (!sidebar) return;
  const setOpen = (open) => {
    sidebar.classList.toggle('open', open);
    if (backdrop) backdrop.classList.toggle('show', open);
  };
  document.querySelectorAll('[data-sidebar-open]').forEach((button) => button.addEventListener('click', () => setOpen(true)));
  document.querySelectorAll('[data-sidebar-close]').forEach((button) => button.addEventListener('click', () => setOpen(false)));

  const nav = sidebar.querySelector('.sidebar-nav');
  const indicator = sidebar.querySelector('.nav-indicator');
  if (!nav || !indicator) return;
  let shown = false;
  const measure = () => {
    const active = nav.querySelector('.side-link.active');
    if (!active) {
      indicator.classList.remove('show');
      return;
    }
    const navRect = nav.getBoundingClientRect();
    const rect = active.getBoundingClientRect();
    indicator.style.transform = `translate(${rect.left - navRect.left}px, ${rect.top - navRect.top + nav.scrollTop}px)`;
    indicator.style.width = `${rect.width}px`;
    indicator.style.height = `${rect.height}px`;
    indicator.classList.add('show');
    indicator.classList.toggle('animate', shown);
    shown = true;
  };
  measure();
  if (window.ResizeObserver) new ResizeObserver(measure).observe(nav);
}

function setupSearchBoxes(container) {
  container.querySelectorAll('.searchbox').forEach((box) => {
    const input = box.querySelector('input');
    const icon = box.querySelector('.searchbox-icon');
    const clear = box.querySelector('.searchbox-clear');
    const paint = () => {
      box.classList.toggle('open', document.activeElement === input || input.value.length > 0);
      if (clear) clear.hidden = input.value.length === 0;
      icon.tabIndex = box.classList.contains('open') ? -1 : 0;
      input.tabIndex = box.classList.contains('open') ? 0 : -1;
    };
    icon.addEventListener('mousedown', (event) => event.preventDefault());
    icon.addEventListener('click', () => input.focus());
    input.addEventListener('focus', paint);
    input.addEventListener('blur', paint);
    input.addEventListener('input', paint);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.blur();
      }
    });
    if (clear) {
      clear.addEventListener('mousedown', (event) => event.preventDefault());
      clear.addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.blur();
      });
    }
    paint();
  });
}

function setupLiveForms() {
  document.querySelectorAll('form[data-live-form]').forEach((form) => {
    const targetId = form.dataset.liveForm;
    let ticket = 0;
    const apply = async () => {
      const params = new URLSearchParams();
      for (const [key, value] of new FormData(form).entries()) {
        if (value !== '') params.set(key, value);
      }
      const url = `${form.getAttribute('action') || Verdict.base}?${params}`;
      const mine = ++ticket;
      try {
        const response = await fetch(url, { credentials: 'same-origin' });
        if (mine !== ticket || !response.ok) return;
        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById(targetId);
        const current = document.getElementById(targetId);
        if (!fresh || !current) return;
        current.innerHTML = fresh.innerHTML;
        hydrate(current);
        history.replaceState(null, '', url);
      } catch (e) {}
    };
    const later = debounce(apply, 300);
    form.addEventListener('input', (event) => {
      if (event.target.matches('input[type="text"]')) later();
    });
    form.addEventListener('change', (event) => {
      if (event.target.matches('select')) apply();
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      apply();
    });
    form.applyFilters = apply;
  });
}

function setupLogFilters() {
  const form = document.querySelector('form[data-live-form="log-results"]');
  if (!form) return;
  const category = form.querySelector('input[name="category"]');
  const search = form.querySelector('input[name="search"]');
  const paint = () => {
    form.querySelectorAll('[data-log-category]').forEach((button) => {
      const active = button.dataset.logCategory === category.value;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  };
  form.querySelectorAll('[data-log-category]').forEach((button) => {
    button.addEventListener('click', () => {
      category.value = button.dataset.logCategory;
      paint();
      form.applyFilters();
    });
  });
  document.addEventListener('click', (event) => {
    const filter = event.target.closest('[data-log-search]');
    const reset = event.target.closest('[data-log-reset]');
    if (filter) {
      search.value = filter.dataset.logSearch;
      search.dispatchEvent(new Event('input', { bubbles: true }));
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    if (reset) {
      search.value = '';
      category.value = '';
      paint();
      search.dispatchEvent(new Event('focus'));
      form.applyFilters();
    }
  });
}

function recordOf(element) {
  const row = element.closest('[data-record]');
  return row ? JSON.parse(row.dataset.record) : null;
}

function setupRowActions() {
  document.addEventListener('click', async (event) => {
    if (event.target.closest('[data-stop]') && !event.target.closest('button')) {
      return;
    }
    const button = event.target.closest('button');
    const expand = event.target.closest('tr[data-expand]');
    if (expand && !button && !event.target.closest('a')) {
      const details = document.querySelector(`tr[data-details="${expand.dataset.expand}"]`);
      if (details) details.hidden = !details.hidden;
      return;
    }
    if (!button) return;

    if (button.dataset.punishAdd) {
      openPunishModal({ kind: button.dataset.punishAdd });
    } else if (button.dataset.punishEdit) {
      openPunishModal({ kind: button.dataset.punishEdit, record: recordOf(button) });
    } else if (button.dataset.punishRemove) {
      const kind = button.dataset.punishRemove;
      const record = recordOf(button);
      openReasonModal({
        title: t('punish.unban_title'),
        confirmLabel: t(kind === 'comms' ? 'punish.remove' : 'punish.unban'),
        danger: false,
        onSubmit: async (reason) => {
          await api.post('punish_remove', { kind, id: record.id, reason });
          reloadWithToast(t('toast.removed'));
        }
      });
    } else if (button.dataset.punishDelete) {
      const kind = button.dataset.punishDelete;
      const record = recordOf(button);
      if (!(await confirmDialog({ title: t('confirm.delete_title'), text: t('confirm.delete_text'), subject: button.dataset.subject }))) return;
      deleteAndReload('punish_delete', { kind, id: record.id });
    } else if (button.hasAttribute('data-admin-add')) {
      openAdminModal(null);
    } else if (button.hasAttribute('data-admin-edit')) {
      openAdminModal(recordOf(button));
    } else if (button.hasAttribute('data-admin-delete')) {
      const record = recordOf(button);
      const card = steamCard(record.steamid);
      if (!(await confirmDialog({ title: t('confirm.delete_title'), text: t('confirm.delete_text'), subject: (card && card.name) || record.username || record.steamid }))) return;
      deleteAndReload('admin_delete', { id: record.id });
    } else if (button.hasAttribute('data-group-add')) {
      openGroupModal(null);
    } else if (button.hasAttribute('data-group-edit')) {
      openGroupModal(recordOf(button));
    } else if (button.hasAttribute('data-group-delete')) {
      const record = recordOf(button);
      if (!(await confirmDialog({ title: t('confirm.delete_title'), text: t('confirm.delete_text'), subject: record.name }))) return;
      deleteAndReload('group_delete', { id: record.id });
    } else if (button.hasAttribute('data-server-add')) {
      openServerModal(null);
    } else if (button.hasAttribute('data-server-edit')) {
      openServerModal(Verdict.server);
    } else if (button.hasAttribute('data-server-delete')) {
      const server = Verdict.server;
      if (!(await confirmDialog({ title: t('confirm.delete_title'), text: t('confirm.delete_text'), subject: server.hostname || `${server.ip}:${server.port}` }))) return;
      try {
        await api.post('server_delete', { id: server.id });
        reloadWithToast(t('toast.deleted'), pageUrl('servers'));
      } catch (error) {
        toast(errorText(error), 'error');
      }
    } else if (button.hasAttribute('data-plugin-config')) {
      openPluginConfigModal(Verdict.server.id);
    } else if (button.hasAttribute('data-copy')) {
      copyText(button, button.closest('.code-head').parentElement.querySelector('.code-block').textContent);
    } else if (button.dataset.download) {
      downloadText(button.dataset.download, button.closest('.code-head').parentElement.querySelector('.code-block').textContent);
    }
  });
}

async function deleteAndReload(action, body) {
  try {
    await api.post(action, body);
    reloadWithToast(t('toast.deleted'));
  } catch (error) {
    toast(errorText(error), 'error');
  }
}

function setupForms() {
  document.querySelectorAll('input[data-digits]').forEach((input) => {
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(0, 6);
    });
  });
  document.querySelectorAll('form[data-confirm-title]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const yes = await confirmDialog({
        tone: form.dataset.confirmTone || 'danger',
        title: form.dataset.confirmTitle,
        text: form.dataset.confirmText,
        confirmLabel: form.dataset.confirmLabel
      });
      if (yes) form.submit();
    });
  });
  document.querySelectorAll('[data-busy-text]').forEach((button) => {
    const form = button.form;
    if (!form) return;
    form.addEventListener('submit', (event) => {
      if (event.submitter === button) {
        button.textContent = button.dataset.busyText;
        button.classList.add('is-busy');
      }
    });
  });
}

function setupInstallServer() {
  const holder = document.querySelector('[data-server-address]');
  if (!holder) return;
  holder.replaceWith(
    serverAddress({
      ip: holder.dataset.ip,
      port: Number(holder.dataset.port) || 27015,
      probeAction: holder.dataset.probe,
      autofocus: true,
      withNames: true,
      onChange: () => {}
    })
  );
}

showStoredToasts();
setupPreferences();
setupSidebar();
hydrate(document);
setupSearchBoxes(document);
setupLiveForms();
setupLogFilters();
setupRowActions();
setupForms();
setupInstallServer();
startRefreshRegions();
if (Verdict.server) startServerPage(Verdict.server);
