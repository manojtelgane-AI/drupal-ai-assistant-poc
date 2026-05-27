/**
 * BrightEdge AI Chat — minimal, dependency-free vanilla JS widget.
 *
 * Talks to /api/brightedge-ai/chat on the same origin. The API key lives
 * server-side (PHP) only — this file never sees it.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.brightedgeAiChat = {
    attach: function (context) {
      // `once` ensures the widget is only injected a single time per page,
      // even if Drupal re-runs behaviors after AJAX updates.
      once('be-ai-chat', 'body', context).forEach(function () {
        renderWidget();
      });
    }
  };

  function renderWidget() {
    var launcher = document.createElement('button');
    launcher.className = 'be-ai-launcher';
    launcher.setAttribute('aria-label', 'Open AI chat');
    launcher.textContent = '💬';

    var panel = document.createElement('div');
    panel.className = 'be-ai-panel';
    panel.innerHTML =
      '<div class="be-ai-header">' +
        '<h3>BrightEdge AI Assistant</h3>' +
        '<button class="be-ai-close" aria-label="Close">×</button>' +
      '</div>' +
      '<div class="be-ai-messages" id="be-ai-messages"></div>' +
      '<div class="be-ai-input">' +
        '<textarea rows="2" placeholder="Ask about SEO, content, or BrightEdge…"></textarea>' +
        '<button class="be-ai-send">Send</button>' +
      '</div>';

    document.body.appendChild(launcher);
    document.body.appendChild(panel);

    var messagesEl = panel.querySelector('.be-ai-messages');
    var textarea = panel.querySelector('textarea');
    var sendBtn = panel.querySelector('.be-ai-send');
    var closeBtn = panel.querySelector('.be-ai-close');

    // Seed message so the panel never opens empty.
    appendMessage('bot', "Hi! I'm the BrightEdge AI Assistant. Ask me anything about SEO, content marketing, or our platform.");

    launcher.addEventListener('click', function () {
      panel.classList.add('is-open');
      textarea.focus();
    });
    closeBtn.addEventListener('click', function () {
      panel.classList.remove('is-open');
    });
    sendBtn.addEventListener('click', send);
    textarea.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        send();
      }
    });

    function send() {
      var message = textarea.value.trim();
      if (!message) return;

      appendMessage('user', message);
      textarea.value = '';
      sendBtn.disabled = true;

      var typing = appendMessage('bot', 'Thinking…', 'be-ai-typing');

      fetch('/api/brightedge-ai/chat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: message })
      })
        .then(function (res) { return res.json().then(function (b) { return { status: res.status, body: b }; }); })
        .then(function (r) {
          typing.remove();
          if (r.body && r.body.ok && r.body.reply) {
            appendMessage('bot', r.body.reply);
          } else {
            var err = (r.body && r.body.error) ? r.body.error : 'Something went wrong. Please try again.';
            appendMessage('error', err);
          }
        })
        .catch(function () {
          typing.remove();
          appendMessage('error', 'Network error. Please check your connection and try again.');
        })
        .finally(function () {
          sendBtn.disabled = false;
          textarea.focus();
        });
    }

    function appendMessage(role, text, extraClass) {
      var wrap = document.createElement('div');
      wrap.className = 'be-ai-msg ' + role;
      var bubble = document.createElement('div');
      bubble.className = 'bubble' + (extraClass ? ' ' + extraClass : '');
      bubble.textContent = text;
      wrap.appendChild(bubble);
      messagesEl.appendChild(wrap);
      messagesEl.scrollTop = messagesEl.scrollHeight;
      return wrap;
    }
  }
})(Drupal, once);
