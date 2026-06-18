/**
 * RAG Assistant floating widget
 * Import in your project: import { initAssistant } from 'path/to/assistent.js'
 * Or load client/dist/js/assistent.js as a standalone script.
 */

export function initAssistant() {
    document.querySelectorAll('.js-assistant').forEach(mount);
}

function mount(el) {
    var toggle   = el.querySelector('.js-assistant-toggle');
    var panel    = el.querySelector('.assistant-widget__panel');
    var form     = el.querySelector('.js-assistant-form');
    var input    = el.querySelector('.js-assistant-input');
    var btn      = el.querySelector('.js-assistant-btn');
    var status   = el.querySelector('.js-assistant-status');
    var messages = el.querySelector('.js-assistant-messages');
    var typing   = el.querySelector('.js-assistant-typing');
    var result   = el.querySelector('.js-assistant-result'); // template, stays hidden

    if (!form || !toggle || !panel) return;

    var endpoint  = el.dataset.endpoint || '/api/assistant/ask';
    var isOffline = el.dataset.offline === '1';
    var maxLen    = parseInt(el.dataset.maxLength, 10) || 300;
    var history   = [];

    input.maxLength = maxLen;
    var i18n = {
        error:           el.dataset.i18nError           || 'An error occurred, please try again.',
        connectionError: el.dataset.i18nConnectionError || 'Connection error, please try again.',
        offline:         el.dataset.i18nOffline         || 'Offline',
        offlineMessage:  el.dataset.i18nOfflineMessage  || 'The assistant is temporarily unavailable.',
    };

    // Apply offline state
    if (isOffline) {
        var dot = el.querySelector('.assistant-widget__online-dot');
        var onlineEl = el.querySelector('.assistant-widget__online');
        if (dot) dot.classList.add('assistant-widget__online-dot--offline');
        if (onlineEl) {
            onlineEl.innerHTML = '<span class="assistant-widget__online-dot assistant-widget__online-dot--offline"></span>' + i18n.offline;
        }
        input.disabled = true;
        input.placeholder = i18n.offlineMessage;
        btn.disabled = true;
    }

    // All elements with js-assistant-toggle trigger open/close
    el.querySelectorAll('.js-assistant-toggle').forEach(function(toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            var isOpen = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            panel.hidden = isOpen;
            if (!isOpen && !isOffline) {
                input.focus();
                scrollMessages();
            }
        });
    });

    function scrollMessages() {
        if (messages) messages.scrollTop = messages.scrollHeight;
    }

    function setLoading(loading) {
        btn.disabled   = loading;
        input.disabled = loading;
        if (typing) typing.hidden = !loading;
        if (loading) scrollMessages();
        if (!loading && !isOffline) input.focus();
    }

    function showError(msg) {
        status.textContent = msg;
    }

    function linkify(node, text) {
        var urlPattern = /(https?:\/\/[^\s]+)/g;
        var parts = text.split(urlPattern);
        node.innerHTML = '';
        parts.forEach(function(part) {
            if (/(https?:\/\/[^\s]+)/.test(part)) {
                var a = document.createElement('a');
                a.href = part.replace(/[.,;:!?)]+$/, ''); // strip trailing punctuation
                a.textContent = part.replace(/[.,;:!?)]+$/, '');
                a.className = 'assistant-widget__inline-link';
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                node.appendChild(a);
            } else {
                node.appendChild(document.createTextNode(part));
            }
        });
    }

    function showResult(data, question) {
        status.textContent = '';

        // Clone the template to create a new answer bubble
        var msg = result.cloneNode(true);
        msg.classList.remove('js-assistant-result');
        msg.hidden = false;

        var answerEl = msg.querySelector('.js-assistant-answer');
        var moreBtnEl = msg.querySelector('.js-assistant-more-btn');

        linkify(answerEl, data.answer);

        var firstSource = data.sources && data.sources[0];
        if (moreBtnEl) {
            if (firstSource) {
                moreBtnEl.href   = firstSource.url;
                moreBtnEl.hidden = false;
            } else {
                moreBtnEl.hidden = true;
            }
        }

        messages.insertBefore(msg, typing);
        scrollMessages();

        // Store turn in history for follow-up context
        history.push({role: 'user',      content: question});
        history.push({role: 'assistant', content: data.answer});
    }

    function addUserMessage(text) {
        var msg = document.createElement('div');
        msg.className = 'assistant-widget__message assistant-widget__message--user';
        var bubble = document.createElement('div');
        bubble.className = 'assistant-widget__message-bubble';
        bubble.textContent = text;
        msg.appendChild(bubble);
        messages.insertBefore(msg, typing);
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        var question = input.value.trim();
        if (question.length < 5) return;

        input.value = '';
        status.textContent = '';
        addUserMessage(question);
        setLoading(true);

        fetch(endpoint, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ question: question, history: history }),
        })
        .then(function(res) {
            return res.json().then(function(data) {
                return { ok: res.ok, data: data };
            });
        })
        .then(function(r) {
            if (!r.ok || r.data.error) {
                showError(r.data.error || i18n.error);
            } else {
                showResult(r.data, question);
            }
        })
        .catch(function() {
            showError(i18n.connectionError);
        })
        .finally(function() {
            setLoading(false);
        });
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', function() {
        if (document.querySelector('.js-assistant')) {
            initAssistant();
        }
    });
}
