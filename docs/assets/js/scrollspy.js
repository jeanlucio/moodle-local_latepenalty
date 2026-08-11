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

  const updateActive = () => {
    let current = targets[0];
    for (const target of targets) {
      if (target.getBoundingClientRect().top <= offset) {
        current = target;
      }
    }
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

  // Set the clicked link active immediately, so the sidebar is correct for
  // the whole duration of the smooth-scroll animation, not just after it
  // settles.
  links.forEach((link) => {
    link.addEventListener('click', () => {
      setActive(link.getAttribute('href').slice(1));
    });
  });
});
