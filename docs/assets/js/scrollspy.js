document.addEventListener('DOMContentLoaded', () => {
  const links = document.querySelectorAll('.sidebar-link');
  const targets = Array.from(links)
    .map((link) => document.querySelector(link.getAttribute('href')))
    .filter(Boolean);

  const setActive = (id) => {
    links.forEach((link) => {
      link.classList.toggle('active', link.getAttribute('href') === `#${id}`);
    });
  };

  // Distance from the viewport top a section must cross before it counts as
  // "current". Using a plain threshold (rather than a thin intersection band)
  // means a section scrolled straight to the top by an anchor click still
  // matches, instead of losing the highlight to whichever section happens to
  // land inside a narrow trigger zone.
  const offset = 96;

  const computeCurrent = () => {
    let current = targets[0];
    for (const target of targets) {
      if (target.getBoundingClientRect().top <= offset) {
        current = target;
      }
    }
    return current;
  };

  // While a click-triggered smooth scroll is in flight, the scroll handler
  // below would otherwise recompute against the still-mid-transit position
  // on its early frames and briefly flip the highlight back to the previous
  // section before the target crosses the threshold — a visible flicker.
  // Suppressing recomputation until the scroll settles keeps the
  // click-set link active for the whole animation instead.
  let suppressed = false;

  const updateActive = () => {
    if (suppressed) {
      return;
    }
    const current = computeCurrent();
    if (current) {
      setActive(current.id);
    }
  };

  let ticking = false;
  const onScroll = () => {
    if (ticking) {
      return;
    }
    ticking = true;
    requestAnimationFrame(() => {
      updateActive();
      ticking = false;
    });
  };

  updateActive();
  window.addEventListener('scroll', onScroll, {passive: true});
  window.addEventListener('resize', onScroll);

  links.forEach((link) => {
    link.addEventListener('click', () => {
      setActive(link.getAttribute('href').slice(1));
      suppressed = true;
    });
  });

  if ('onscrollend' in window) {
    window.addEventListener('scrollend', () => {
      suppressed = false;
      updateActive();
    });
  } else {
    // Fallback for browsers without the scrollend event: resume tracking
    // once no scroll event has fired for a short idle period.
    let idleTimer = null;
    window.addEventListener('scroll', () => {
      if (!suppressed) {
        return;
      }
      clearTimeout(idleTimer);
      idleTimer = setTimeout(() => {
        suppressed = false;
        updateActive();
      }, 150);
    }, {passive: true});
  }
});
