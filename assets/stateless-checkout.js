(() => {
  'use strict';

  const card = document.querySelector('[data-stateless-checkout]');
  if (!(card instanceof HTMLElement)) return;

  const timer = card.querySelector('[data-invoice-timer]');
  const statusPill = card.querySelector('[data-status-pill]');
  const received = card.querySelector('[data-received-amount]');
  const missing = card.querySelector('[data-missing-amount]');
  const statusUrl = card.dataset.statusUrl || '';
  let seconds = Number.parseInt(card.dataset.secondsRemaining || '0', 10);
  let status = card.dataset.status || 'unpaid';
  let pollHandle = 0;

  const labels = {
    unpaid: 'Čeká na platbu',
    underpaid: 'Částečná platba',
    pending_mempool: 'Platba zachycena v síti',
    paid: 'Zaplaceno',
    expired: 'Platnost vypršela'
  };

  const terminal = (value) => value === 'paid' || value === 'expired';

  const renderStatus = (nextStatus) => {
    status = Object.prototype.hasOwnProperty.call(labels, nextStatus) ? nextStatus : 'unpaid';
    card.dataset.status = status;
    card.dataset.terminal = terminal(status) ? 'true' : 'false';
    if (statusPill instanceof HTMLElement) {
      statusPill.dataset.status = status;
      statusPill.textContent = labels[status];
    }
    if (terminal(status) && pollHandle) {
      window.clearInterval(pollHandle);
      pollHandle = 0;
    }
  };

  const formatDuration = (totalSeconds) => {
    const safe = Math.max(0, totalSeconds);
    const days = Math.floor(safe / 86400);
    const hours = Math.floor((safe % 86400) / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const secs = safe % 60;
    const clock = [hours, minutes, secs].map((part) => String(part).padStart(2, '0')).join(':');
    return days > 0 ? `${days} ${days === 1 ? 'den' : (days < 5 ? 'dny' : 'dní')} ${clock}` : clock;
  };

  const renderTimer = () => {
    if (!(timer instanceof HTMLElement)) return;
    if (status === 'paid') {
      timer.textContent = 'Platba byla úspěšně přijata.';
    } else if (status === 'pending_mempool') {
      timer.textContent = 'Platba byla přijata; čekáme na potvrzení sítě.';
    } else if (status === 'expired' || seconds <= 0) {
      timer.textContent = 'Čas pro úhradu vypršel.';
    } else {
      timer.textContent = `Zbývající čas: ${formatDuration(seconds)}`;
    }
  };

  const deadline = card.querySelector('[data-invoice-deadline]');
  if (deadline) {
    const date = new Date(deadline.getAttribute('datetime'));
    if (!Number.isNaN(date.getTime())) {
      deadline.textContent = new Intl.DateTimeFormat('cs-CZ', {
        day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit',
      }).format(date);
    }
  }

  card.querySelectorAll('[data-copy-value]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) return;
    button.addEventListener('click', async () => {
      const value = button.dataset.copyValue || '';
      if (!value) return;
      const label = button.textContent;
      let copied = false;
      if (window.isSecureContext && navigator.clipboard) {
        try {
          await navigator.clipboard.writeText(value);
          copied = true;
        } catch (_error) { copied = false; }
      }
      if (!copied) {
        const input = document.createElement('textarea');
        input.value = value;
        input.readOnly = true;
        input.style.cssText = 'position:fixed;opacity:0;';
        document.body.appendChild(input);
        input.select();
        try { copied = document.execCommand('copy'); } catch (_error) { copied = false; }
        input.remove();
      }
      button.textContent = copied ? 'Zkopírováno' : 'Nelze kopírovat';
      window.setTimeout(() => { button.textContent = label; }, 1800);
    });
  });

  const refresh = async () => {
    if (!statusUrl || terminal(status)) return;
    try {
      const response = await fetch(statusUrl, {
        method: 'GET',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
        credentials: 'same-origin'
      });
      if (!response.ok) return;
      const payload = await response.json();
      if (!payload || typeof payload !== 'object') return;
      if (typeof payload.status === 'string') renderStatus(payload.status);
      if (Number.isInteger(payload.seconds_remaining)) seconds = Math.max(0, payload.seconds_remaining);
      if (received instanceof HTMLElement && typeof payload.received_amount === 'string') {
        received.textContent = `${payload.received_amount} BTC`;
      }
      if (missing instanceof HTMLElement && typeof payload.missing_amount === 'string') {
        missing.textContent = `${payload.missing_amount} BTC`;
      }
      renderTimer();
    } catch (_error) {
      // A transient RPC/network failure must not replace the last known state.
    }
  };

  renderStatus(status);
  renderTimer();
  window.setInterval(() => {
    if (seconds > 0 && !terminal(status)) seconds -= 1;
    renderTimer();
  }, 1000);

  if (!terminal(status)) {
    pollHandle = window.setInterval(refresh, 10000);
  }
})();
