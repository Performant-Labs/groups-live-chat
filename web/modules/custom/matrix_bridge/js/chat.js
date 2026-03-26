/**
 * @file
 * Chat UI JavaScript — real-time sync via long-poll + auto-scroll.
 *
 * Uses an intelligent long-poll loop against Drupal's sync endpoint:
 * 1. On page load, do an initial sync (since=0 to get all messages).
 * 2. Store the last_id from the response.
 * 3. Long-poll with since=last_id — returns only new messages.
 * 4. When new messages arrive, append them and re-poll immediately.
 * 5. On empty response, re-poll after 2s delay.
 * 6. On error, exponential backoff up to 30s.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.matrixChatSync = {
    attach: function (context) {
      // Only attach once per chat panel.
      var panels = context.querySelectorAll
        ? context.querySelectorAll('.matrix-chat[data-group-id]')
        : [];

      panels.forEach(function (panel) {
        if (panel.dataset.syncAttached) return;
        panel.dataset.syncAttached = 'true';

        var groupId = panel.dataset.groupId;
        var messagesEl = panel.querySelector('[id^="matrix-chat-messages-"]');
        if (!messagesEl) return;

        var lastId = 0;
        var retryDelay = 2000;
        var initialLoad = true;

        /**
         * Renders messages into the message container.
         */
        function renderMessages(messages) {
          messages.forEach(function (msg) {
            var wrapper = document.createElement('div');
            wrapper.className = 'matrix-chat__message' +
              (msg.is_own ? ' matrix-chat__message--own' : '');

            var bubble = document.createElement('div');
            bubble.className = 'matrix-chat__bubble';

            if (!msg.is_own) {
              var author = document.createElement('span');
              author.className = 'matrix-chat__author';
              author.textContent = msg.author;
              bubble.appendChild(author);
            }

            var body = document.createElement('span');
            body.className = 'matrix-chat__body';
            body.textContent = msg.body;
            bubble.appendChild(body);

            var time = document.createElement('span');
            time.className = 'matrix-chat__time';
            time.textContent = msg.time;
            bubble.appendChild(time);

            wrapper.appendChild(bubble);
            messagesEl.appendChild(wrapper);
          });

          // Auto-scroll to bottom.
          messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        /**
         * Long-poll sync loop.
         */
        function poll() {
          var url = '/group/' + groupId + '/chat/sync?since=' + lastId;

          fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
              if (!response.ok) throw new Error('Sync failed: ' + response.status);
              return response.json();
            })
            .then(function (data) {
              retryDelay = 2000; // Reset on success.

              if (data.last_id) {
                lastId = data.last_id;
              }

              if (data.has_new && data.messages && data.messages.length > 0) {
                // On initial load, clear any loading/HTMX-rendered content first.
                if (initialLoad) {
                  messagesEl.innerHTML = '';
                  initialLoad = false;
                }

                // Remove "No messages" placeholder if present.
                var empty = messagesEl.querySelector('.matrix-chat__empty');
                if (empty) empty.remove();
                var loading = messagesEl.querySelector('.matrix-chat__loading');
                if (loading) loading.remove();

                renderMessages(data.messages);
                // Re-poll quickly when there are new messages.
                setTimeout(poll, 500);
              } else {
                initialLoad = false;
                // No new messages — poll again after a short delay.
                setTimeout(poll, retryDelay);
              }
            })
            .catch(function (err) {
              console.warn('Matrix sync error:', err);
              retryDelay = Math.min(retryDelay * 2, 30000);
              setTimeout(poll, retryDelay);
            });
        }

        // Start the sync loop after a brief delay
        // (let HTMX load the initial messages first).
        setTimeout(poll, 2000);
      });
    }
  };

  /**
   * Auto-scroll on initial load and HTMX swaps (for sent messages).
   */
  Drupal.behaviors.matrixChatScroll = {
    attach: function (context) {
      var containers = context.querySelectorAll
        ? context.querySelectorAll('[id^="matrix-chat-messages-"]')
        : [];

      containers.forEach(function (container) {
        container.scrollTop = container.scrollHeight;
      });
    }
  };

})(Drupal);
