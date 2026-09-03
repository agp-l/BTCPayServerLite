(() => {
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
