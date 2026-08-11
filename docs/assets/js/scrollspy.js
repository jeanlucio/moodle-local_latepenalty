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

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          setActive(entry.target.id);
        }
      });
    },
    {rootMargin: '-10% 0px -80% 0px'},
  );

  targets.forEach((target) => observer.observe(target));
});
