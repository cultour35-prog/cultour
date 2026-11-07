/**
 * CulTour realtime chat — poll + AJAX send
 */
(function () {
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function initChat(cfg) {
    const box = document.getElementById('chatBox');
    const form = document.getElementById('chatForm');
    const peerList = document.getElementById('chatPeerList');
    const withId = Number(cfg.withId) || 0;
    if (!withId && !peerList) return;

    let lastId = cfg.lastId || 0;
    let busy = false;
    const pollUrl = cfg.appUrl + '/api/chat/poll.php';
    const sendUrl = cfg.appUrl + '/api/chat/send_web.php';
    // Default: open the thread (?with=). Profile cards still use ?info= when requested.
    const peerParam = cfg.peerParam === 'info' ? 'info' : 'with';
    const peerBase =
      cfg.peerBase ||
      (cfg.appUrl
        ? cfg.appUrl.replace(/\/$/, '') +
          (peerParam === 'with' ? '/tourguide/chat.php?' : '/tourist/chat.php?') +
          peerParam +
          '='
        : '?' + peerParam + '=');

    function peerHref(id) {
      return peerBase + String(id);
    }

    function scrollBottom(force) {
      if (!box) return;
      const nearBottom =
        box.scrollHeight - box.scrollTop - box.clientHeight < 80;
      if (force || nearBottom) {
        box.scrollTop = box.scrollHeight;
      }
    }

    function renderBubble(m) {
      if (!box || box.querySelector('[data-msg-id="' + m.id + '"]')) return;
      const div = document.createElement('div');
      div.className = 'bubble ' + (m.mine ? 'me' : 'them');
      div.setAttribute('data-msg-id', String(m.id));
      let html = '';
      if (m.body) html += '<div>' + esc(m.body) + '</div>';
      if (m.image_path) {
        html +=
          '<img src="' +
          esc(m.image_path) +
          '" class="img-fluid rounded mt-1" style="max-height:160px" alt="">';
      }
      html +=
        '<div class="small opacity-75 mt-1">' +
        esc(m.time || '') +
        (m.mine && m.is_seen ? ' · Seen' : '') +
        '</div>';
      div.innerHTML = html;
      box.appendChild(div);
      if (m.id > lastId) lastId = m.id;
    }

    function markSeen(ids) {
      if (!box || !ids || !ids.length) return;
      ids.forEach(function (id) {
        const el = box.querySelector('[data-msg-id="' + id + '"]');
        if (!el || !el.classList.contains('me')) return;
        const meta = el.querySelector('.small');
        if (meta && meta.textContent.indexOf('Seen') === -1) {
          meta.textContent = meta.textContent + ' · Seen';
        }
      });
    }

    function updateUnread(unread) {
      if (!unread) return;
      document.querySelectorAll('[data-peer-id]').forEach(function (el) {
        const id = el.getAttribute('data-peer-id');
        const count = unread[id] || 0;
        let badge = el.querySelector('.chat-unread');
        if (count > 0) {
          if (!badge) {
            badge = document.createElement('span');
            badge.className = 'badge bg-danger rounded-pill chat-unread';
            el.appendChild(badge);
          }
          badge.textContent = String(count);
          el.classList.add('has-unread');
        } else if (badge) {
          badge.remove();
          el.classList.remove('has-unread');
        }
      });
    }

    function syncPeers(peers) {
      if (!peerList || !peers || !peers.length) return;
      const infoId = Number(cfg.infoId) || 0;
      peers.forEach(function (p) {
        let el = peerList.querySelector('[data-peer-id="' + p.id + '"]');
        if (!el) {
          el = document.createElement('a');
          el.className = 'chat-peer';
          el.setAttribute('data-peer-id', String(p.id));
          el.innerHTML =
            '<span class="chat-peer__who">' +
              '<span class="chat-peer__avatar"></span>' +
              '<span class="chat-peer__name"></span>' +
            '</span>';
          const empty = peerList.querySelector('.chat-empty');
          if (empty) empty.remove();
          peerList.appendChild(el);
        }
        el.setAttribute('href', peerHref(p.id));
        let who = el.querySelector('.chat-peer__who');
        if (!who) {
          who = document.createElement('span');
          who.className = 'chat-peer__who';
          const legacyName = el.querySelector('.chat-peer__name');
          if (legacyName) {
            who.appendChild(legacyName);
          } else {
            who.innerHTML =
              '<span class="chat-peer__avatar"></span><span class="chat-peer__name"></span>';
          }
          el.insertBefore(who, el.firstChild);
        }
        let av = who.querySelector('.chat-peer__avatar');
        if (!av) {
          av = document.createElement('span');
          av.className = 'chat-peer__avatar';
          who.insertBefore(av, who.firstChild);
        }
        if (p.avatar) {
          if (!av.querySelector('img') || av.querySelector('img').getAttribute('src') !== p.avatar) {
            av.innerHTML = '<img src="' + esc(p.avatar) + '" alt="">';
          }
        } else {
          const initials = p.initials || '?';
          if (!av.querySelector('img')) {
            av.textContent = initials;
          } else {
            av.textContent = initials;
          }
        }
        let nameEl = who.querySelector('.chat-peer__name');
        if (!nameEl) {
          nameEl = document.createElement('span');
          nameEl.className = 'chat-peer__name';
          who.appendChild(nameEl);
        }
        nameEl.textContent = p.name || '';
        el.classList.toggle('is-active', p.id === withId || p.id === infoId);
      });
      updateUnread(
        peers.reduce(function (acc, p) {
          acc[String(p.id)] = p.unread;
          return acc;
        }, {})
      );
    }

    function poll() {
      fetch(
        pollUrl +
          '?with=' +
          encodeURIComponent(withId) +
          '&after=' +
          encodeURIComponent(withId ? lastId : 0),
        { credentials: 'same-origin', cache: 'no-store' }
      )
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.ok) return;
          if (withId && box) {
            const list = data.messages || [];
            if (list.length) {
              list.forEach(renderBubble);
              scrollBottom(false);
            }
            markSeen(data.seen_ids);
          }
          updateUnread(data.unread);
          syncPeers(data.peers);
          if (window.CulTourUnread && data.unread) {
            var total = 0;
            Object.keys(data.unread).forEach(function (k) {
              total += Number(data.unread[k]) || 0;
            });
            window.CulTourUnread.apply({ ok: true, messages: total });
          }
        })
        .catch(function () {});
    }

    if (form && withId) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) return;
        const bodyInput = form.querySelector('[name="body"]');
        const fileInput = form.querySelector('[name="image"]');
        const body = (bodyInput && bodyInput.value.trim()) || '';
        const hasFile = fileInput && fileInput.files && fileInput.files.length;
        if (!body && !hasFile) return;

        busy = true;
        const btn = form.querySelector('button[type="submit"],button.btn-ct');
        if (btn) btn.disabled = true;

        const fd = new FormData(form);
        fd.set('with', String(withId));
        if (cfg.csrf && !fd.get('csrf_token')) {
          fd.set('csrf_token', cfg.csrf);
        }

        fetch(sendUrl, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        })
          .then(function (r) {
            return r.json();
          })
          .then(function (data) {
            if (data && data.ok && data.message) {
              renderBubble(data.message);
              scrollBottom(true);
              if (bodyInput) bodyInput.value = '';
              if (fileInput) fileInput.value = '';
            }
          })
          .catch(function () {})
          .finally(function () {
            busy = false;
            if (btn) btn.disabled = false;
            if (bodyInput) bodyInput.focus();
          });
      });
      scrollBottom(true);
    }

    poll();
    setInterval(poll, 1500);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) poll();
    });
  }

  window.CulTourChat = { init: initChat };
})();
