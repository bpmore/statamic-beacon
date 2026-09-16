/* Beacon dismissal. Runs inline right after the banner so a dismissed
 * alert never flashes. localStorage only, keyed by alert id and content
 * fingerprint: an edited alert has a new key and shows again. No cookies.
 * Without this script the banner shows and has no dismiss control, which
 * is the intended degradation. */
(function () {
  'use strict';
  var script = document.currentScript;
  var label = (script && script.getAttribute('data-beacon-dismiss')) || 'Dismiss';
  var store;
  try { store = window.localStorage; } catch (e) { return; }
  if (!store) { return; }

  var regions = document.querySelectorAll('[data-beacon-key][data-beacon-dismissible="1"]');
  var stacks = [];

  function remove(el) {
    var stack = el.parentNode;
    if (stack && stack.parentNode) { stack.removeChild(el); }
    if (stack && stack.hasAttribute('data-beacon') && stacks.indexOf(stack) === -1) { stacks.push(stack); }
  }

  for (var i = 0; i < regions.length; i++) {
    (function (region) {
      var key = region.getAttribute('data-beacon-key');
      var seen = null;
      try { seen = store.getItem(key); } catch (e) { seen = null; }
      if (seen) { remove(region); return; }

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
        try { store.setItem(key, String(Date.now())); } catch (e) { /* private mode: dismiss for this page only */ }
        remove(region);
      });
      var inner = region.querySelector('.beacon__inner') || region;
      inner.appendChild(button);
    })(regions[i]);
  }

  for (var j = 0; j < stacks.length; j++) {
    if (stacks[j].children.length === 0 && stacks[j].parentNode) {
      stacks[j].parentNode.removeChild(stacks[j]);
    }
  }
})();
