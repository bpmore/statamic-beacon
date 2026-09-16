/* Beacon: dismissal, and the optional emergency fast path.
 *
 * Dismissal: localStorage only, keyed by alert id and content fingerprint,
 * so an edited alert has a new key and shows again. No cookies. Without
 * this script the banner shows and has no dismiss control, which is the
 * intended degradation.
 *
 * Fast path (off unless the page carries [data-beacon-live]): every N
 * seconds ask this origin, never the remote source, for the current
 * emergency alerts and put a new one into the live region for its
 * severity, so a visitor with a page open for an hour still sees it.
 * The regions exist at load, empty, so the announcement happens; the
 * server-rendered banner above is a landmark and is never touched.
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var label = (script && script.getAttribute('data-beacon-dismiss')) || 'Dismiss';
  var store = null;
  try { store = window.localStorage; } catch (e) { store = null; }

  function dismissed(key) {
    if (!store) { return false; }
    try { return !!store.getItem(key); } catch (e) { return false; }
  }

  function remember(key) {
    if (!store) { return; }
    try { store.setItem(key, String(Date.now())); } catch (e) { /* private mode: this page only */ }
  }

  function remove(el) {
    var stack = el.parentNode;
    if (stack) { stack.removeChild(el); }
    if (stack && stack.hasAttribute('data-beacon') && stack.children.length === 0 && stack.parentNode) {
      stack.parentNode.removeChild(stack);
    }
  }

  /* Give one region its dismiss button, or drop it if already dismissed. Returns whether it stayed. */
  function wire(region) {
    if (region.getAttribute('data-beacon-dismissible') !== '1') { return true; }
    var key = region.getAttribute('data-beacon-key');
    if (!key) { return true; }
    if (dismissed(key)) { remove(region); return false; }
    if (region.querySelector('.beacon__dismiss')) { return true; }

    var heading = region.querySelector('.beacon__title');
    var title = heading ? heading.textContent : '';
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'beacon__dismiss';
    button.appendChild(document.createTextNode(label));
    if (title) {
      var sr = document.createElement('span');
      sr.className = 'beacon__sr';
      sr.appendChild(document.createTextNode(' alert: ' + title));
      button.appendChild(sr);
    }
    button.addEventListener('click', function () {
      remember(key);
      remove(region);
    });
    (region.querySelector('.beacon__inner') || region.querySelector('.inner-container') || region).appendChild(button);
    return true;
  }

  var regions = document.querySelectorAll('[data-beacon-key]');
  for (var i = 0; i < regions.length; i++) { wire(regions[i]); }

  /* ---- fast path ---- */
  var live = document.querySelector('[data-beacon-live]');
  if (!live || typeof window.fetch !== 'function') { return; }

  var endpoint = live.getAttribute('data-beacon-live');
  var interval = Math.max(15, parseInt(live.getAttribute('data-beacon-interval'), 10) || 60) * 1000;
  var statusRegion = live.querySelector('[role="status"]');
  var alertRegion = live.querySelector('[role="alert"]');
  var timer = null;

  function onPage(key) {
    return !!document.querySelector('[data-beacon-key="' + key.replace(/"/g, '\\"') + '"]');
  }

  function apply(data) {
    if (!data || !data.alerts) { return; }
    var keep = {};
    for (var i = 0; i < data.alerts.length; i++) {
      var a = data.alerts[i];
      if (!a || !a.key || !a.html) { continue; }
      keep[a.key] = true;
      if (onPage(a.key) || dismissed(a.key)) { continue; }

      var holder = document.createElement('div');
      holder.innerHTML = a.html;
      var region = holder.firstElementChild;
      if (!region) { continue; }
      region.setAttribute('data-beacon-injected', '1');
      (a.severity === 'emergency' ? alertRegion : statusRegion).appendChild(region);
      wire(region);
    }

    /* An injected alert the server no longer lists has ended. */
    var injected = live.querySelectorAll('[data-beacon-injected]');
    for (var j = 0; j < injected.length; j++) {
      if (!keep[injected[j].getAttribute('data-beacon-key')]) {
        injected[j].parentNode.removeChild(injected[j]);
      }
    }
  }

  function check() {
    if (document.hidden) { return; }
    fetch(endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(apply)
      .catch(function () { /* a missed check is a missed check; the next one runs */ });
  }

  function schedule() {
    if (timer) { clearInterval(timer); }
    timer = setInterval(check, interval);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { check(); }
  });

  schedule();
})();
