// One shared observer of client-side navigation (history.pushState / replaceState / popstate /
// hashchange), so several features can react without each wrapping the History API again.
//
// Subscribers run SYNCHRONOUSLY right after the address changed. Session replay depends on that: when a
// single-page app navigates to an excluded page (checkout, account), recording must stop before the app
// renders it, not on the next tick.

const MARK = '__ziploggerNavigation';

export function installNavigation(win) {
  if (win[MARK]) return win[MARK];

  const subscribers = [];
  let lastHref = win.location.href;

  function fire(how) {
    const href = win.location.href;
    if (href === lastHref) return;
    lastHref = href;
    subscribers.slice().forEach(function (cb) {
      try { cb(how); } catch (e) { /* a subscriber must never break the page's navigation */ }
    });
  }

  const history = win.history;
  const restore = [];
  ['pushState', 'replaceState'].forEach(function (method) {
    const original = history && history[method];
    if (typeof original !== 'function') return;
    const wrapper = function () {
      const result = original.apply(this, arguments);
      fire('spa');
      return result;
    };
    history[method] = wrapper;
    restore.push(function () { if (history[method] === wrapper) history[method] = original; });
  });
  const onPop = function () { fire('spa'); };
  win.addEventListener('popstate', onPop);
  win.addEventListener('hashchange', onPop);

  const api = {
    subscribe: function (cb) {
      subscribers.push(cb);
      return function () {
        const i = subscribers.indexOf(cb);
        if (i !== -1) subscribers.splice(i, 1);
      };
    },
    stop: function () {
      subscribers.length = 0;
      restore.forEach(function (fn) { fn(); });
      win.removeEventListener('popstate', onPop);
      win.removeEventListener('hashchange', onPop);
      try { delete win[MARK]; } catch (e) { win[MARK] = undefined; }
    },
  };
  try {
    Object.defineProperty(win, MARK, { value: api, configurable: true });
  } catch (e) { win[MARK] = api; }
  return api;
}
