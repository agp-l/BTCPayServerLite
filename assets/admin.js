(() => {
  const showCopyMessage = (message) => {
    const toast = document.getElementById('toast');
    const text = document.getElementById('toastMsg');
    if (!toast || !text) return;
    text.textContent = message;
    toast.classList.add('show');
    window.setTimeout(() => toast.classList.remove('show'), 3000);
  };

  const fallbackCopy = (value) => {
    const activeElement = document.activeElement;
    const selection = window.getSelection();
    const ranges = [];
    if (selection) {
      for (let index = 0; index < selection.rangeCount; index += 1) {
        ranges.push(selection.getRangeAt(index).cloneRange());
      }
    }
    const inputSelection = activeElement && typeof activeElement.selectionStart === 'number'
      ? [activeElement.selectionStart, activeElement.selectionEnd, activeElement.selectionDirection]
      : null;
    const input = document.createElement('textarea');
    input.value = value;
    input.readOnly = true;
    input.setAttribute('aria-hidden', 'true');
    input.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none;';
    document.body.appendChild(input);
    let copied = false;
    try {
      input.focus({ preventScroll: true });
      input.select();
      input.setSelectionRange(0, value.length);
      copied = document.execCommand('copy');
    } catch (_error) {
      copied = false;
    } finally {
      input.remove();
      if (activeElement && typeof activeElement.focus === 'function') {
        activeElement.focus({ preventScroll: true });
        if (inputSelection) activeElement.setSelectionRange(...inputSelection);
      }
      if (selection) {
        selection.removeAllRanges();
        ranges.forEach((range) => selection.addRange(range));
      }
    }
    return copied;
  };

  document.querySelectorAll('[data-copy-select]').forEach((input) => {
    input.addEventListener('click', () => input.select());
  });

  document.querySelectorAll('[data-copy], [data-copy-input]').forEach((button) => {
    button.addEventListener('click', async () => {
      const input = button.hasAttribute('data-copy-input')
        ? button.parentElement.querySelector('input')
        : null;
      const value = input ? input.value : (button.dataset.copy || '');
      if (!value) {
        showCopyMessage('Není co kopírovat.');
        return;
      }
      let copied = false;
      if (window.isSecureContext && navigator.clipboard) {
        try {
          await navigator.clipboard.writeText(value);
          copied = true;
        } catch (_error) {
          copied = false;
        }
      }
      if (!copied) copied = fallbackCopy(value);
      if (!copied && input && input.hasAttribute('data-copy-select')) {
        input.focus({ preventScroll: true });
        input.select();
        showCopyMessage('Text je označený. Zkopírujte jej pomocí Ctrl+C nebo nabídky prohlížeče.');
        return;
      }
      showCopyMessage(copied
        ? 'Zkopírováno do schránky.'
        : 'Kopírování se nepodařilo. Zobrazte text a zkopírujte jej ručně.');
    });
  });

  const body = document.body;
  const openButton = document.querySelector('[data-sidebar-open]');
  const closeTargets = document.querySelectorAll('[data-sidebar-close]');
  const navigationLinks = document.querySelectorAll('.admin-nav a');

  const setSidebar = (open) => {
    body.classList.toggle('sidebar-open', open);

    if (openButton) {
      openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
  };

  if (openButton) {
    openButton.addEventListener('click', () => setSidebar(true));
  }

  closeTargets.forEach((target) => {
    target.addEventListener('click', () => setSidebar(false));
  });

  navigationLinks.forEach((link) => {
    link.addEventListener('click', () => setSidebar(false));
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setSidebar(false);
    }
  });

  const desktopViewport = window.matchMedia('(min-width: 861px)');
  const closeSidebarOnDesktop = (event) => {
    if (event.matches) {
      setSidebar(false);
    }
  };

  if (typeof desktopViewport.addEventListener === 'function') {
    desktopViewport.addEventListener('change', closeSidebarOnDesktop);
  }

  document.querySelectorAll('.data-table-wrap .data-table').forEach((table) => {
    const headings = Array.from(table.querySelectorAll('thead th')).map((heading) =>
      heading.textContent.trim()
    );

    if (headings.length === 0) {
      return;
    }

    table.querySelectorAll('tbody tr').forEach((row) => {
      const cells = Array.from(row.children).filter((cell) => cell.tagName === 'TD');

      if (cells.length === 1 && cells[0].hasAttribute('colspan')) {
        cells[0].classList.add('responsive-table-full');
        return;
      }

      cells.forEach((cell, index) => {
        cell.dataset.label = headings[index] || 'Akce';
      });
    });

    table.classList.add('is-responsive');

    const wrapper = table.closest('.data-table-wrap');
    if (wrapper) {
      wrapper.classList.add('has-responsive-table');
    }
  });
})();
