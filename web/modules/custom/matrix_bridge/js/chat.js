/**
 * @file
 * Chat UI JavaScript — real-time sync with edit/delete support.
 *
 * Uses an intelligent long-poll loop against Drupal's sync endpoint:
 * 1. On page load, do an initial sync (since=0 to get all messages).
 * 2. Store the last_id and sync_ts from the response.
 * 3. Long-poll with since=last_id&since_ts=sync_ts — returns new + mutated messages.
 * 4. Handle three message types: new (append), edited (update in-place), deleted (replace with placeholder).
 * 5. On empty response, re-poll after 2s delay.
 * 6. On error, exponential backoff up to 30s.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.matrixChatSync = {
    attach: function (context) {
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
        var syncTs = 0;
        var retryDelay = 2000;
        var initialLoad = true;

        /**
         * Creates the action buttons (edit/delete) for own messages.
         */
        function createActions(msgId) {
          var actions = document.createElement('div');
          actions.className = 'matrix-chat__actions';

          var editBtn = document.createElement('button');
          editBtn.className = 'matrix-chat__action-btn matrix-chat__action-edit';
          editBtn.textContent = '✏️';
          editBtn.title = 'Edit';
          editBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            startEdit(msgId);
          });

          var deleteBtn = document.createElement('button');
          deleteBtn.className = 'matrix-chat__action-btn matrix-chat__action-delete';
          deleteBtn.textContent = '🗑️';
          deleteBtn.title = 'Delete';
          deleteBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            deleteMessage(msgId);
          });

          actions.appendChild(editBtn);
          actions.appendChild(deleteBtn);
          return actions;
        }

        /**
         * Starts inline edit mode for a message.
         */
        function startEdit(msgId) {
          var el = messagesEl.querySelector('[data-message-id="' + msgId + '"]');
          if (!el) return;

          var bodyEl = el.querySelector('.matrix-chat__body');
          if (!bodyEl) return;

          var currentText = bodyEl.textContent;
          var bubble = el.querySelector('.matrix-chat__bubble');

          // Hide the actions and body.
          var actionsEl = el.querySelector('.matrix-chat__actions');
          if (actionsEl) actionsEl.style.display = 'none';
          bodyEl.style.display = 'none';

          // Create edit form.
          var editForm = document.createElement('div');
          editForm.className = 'matrix-chat__edit-form';

          var input = document.createElement('input');
          input.type = 'text';
          input.className = 'matrix-chat__edit-input';
          input.value = currentText;

          var saveBtn = document.createElement('button');
          saveBtn.className = 'matrix-chat__edit-save';
          saveBtn.textContent = '✓';
          saveBtn.title = 'Save';

          var cancelBtn = document.createElement('button');
          cancelBtn.className = 'matrix-chat__edit-cancel';
          cancelBtn.textContent = '✕';
          cancelBtn.title = 'Cancel';

          function cancelEdit() {
            editForm.remove();
            bodyEl.style.display = '';
            if (actionsEl) actionsEl.style.display = '';
          }

          function saveEdit() {
            var newBody = input.value.trim();
            if (!newBody || newBody === currentText) {
              cancelEdit();
              return;
            }

            fetch('/group/' + groupId + '/chat/message/' + msgId + '/edit', {
              method: 'PATCH',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ body: newBody }),
            })
              .then(function (r) { return r.json(); })
              .then(function (data) {
                if (data.ok) {
                  bodyEl.textContent = data.body;
                  // Add edited indicator if not present.
                  if (!el.querySelector('.matrix-chat__edited')) {
                    var edited = document.createElement('span');
                    edited.className = 'matrix-chat__edited';
                    edited.textContent = '(edited)';
                    var timeEl = el.querySelector('.matrix-chat__time');
                    if (timeEl) timeEl.parentNode.insertBefore(edited, timeEl);
                  }
                }
                cancelEdit();
              })
              .catch(function () { cancelEdit(); });
          }

          saveBtn.addEventListener('click', saveEdit);
          cancelBtn.addEventListener('click', cancelEdit);
          input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') saveEdit();
            if (e.key === 'Escape') cancelEdit();
          });

          editForm.appendChild(input);
          editForm.appendChild(saveBtn);
          editForm.appendChild(cancelBtn);
          bubble.appendChild(editForm);
          input.focus();
          input.select();
        }

        /**
         * Deletes a message (soft-delete).
         */
        function deleteMessage(msgId) {
          if (!confirm('Delete this message?')) return;

          fetch('/group/' + groupId + '/chat/message/' + msgId + '/delete', {
            method: 'DELETE',
            credentials: 'same-origin',
          })
            .then(function (r) { return r.json(); })
            .then(function (data) {
              if (data.ok) {
                markAsDeleted(msgId);
              }
            })
            .catch(function (err) {
              console.warn('Delete failed:', err);
            });
        }

        /**
         * Marks a message element as deleted in the DOM.
         */
        function markAsDeleted(msgId) {
          var el = messagesEl.querySelector('[data-message-id="' + msgId + '"]');
          if (!el) return;

          el.classList.add('matrix-chat__message--deleted');
          var bubble = el.querySelector('.matrix-chat__bubble');
          if (bubble) {
            bubble.innerHTML = '<span class="matrix-chat__deleted-text">Message deleted</span>';
          }
        }

        /**
         * Renders a single message into the message container.
         */
        function renderMessage(msg) {
          var wrapper = document.createElement('div');
          wrapper.className = 'matrix-chat__message' +
            (msg.is_own ? ' matrix-chat__message--own' : '');
          wrapper.setAttribute('data-message-id', msg.id);

          if (msg.type === 'deleted') {
            wrapper.classList.add('matrix-chat__message--deleted');
            var deletedBubble = document.createElement('div');
            deletedBubble.className = 'matrix-chat__bubble';
            deletedBubble.innerHTML = '<span class="matrix-chat__deleted-text">Message deleted</span>';
            wrapper.appendChild(deletedBubble);
            return wrapper;
          }

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

          if (msg.edited) {
            var edited = document.createElement('span');
            edited.className = 'matrix-chat__edited';
            edited.textContent = '(edited)';
            bubble.appendChild(edited);
          }

          var time = document.createElement('span');
          time.className = 'matrix-chat__time';
          time.textContent = msg.time;
          bubble.appendChild(time);

          wrapper.appendChild(bubble);

          // Add action buttons for own messages.
          if (msg.is_own && msg.type !== 'deleted') {
            wrapper.appendChild(createActions(msg.id));
          }

          return wrapper;
        }

        /**
         * Processes messages from sync response.
         */
        function processMessages(messages) {
          messages.forEach(function (msg) {
            var existing = messagesEl.querySelector('[data-message-id="' + msg.id + '"]');

            if (msg.type === 'deleted') {
              if (existing) {
                markAsDeleted(msg.id);
              } else {
                // New message that's already deleted — still show placeholder.
                messagesEl.appendChild(renderMessage(msg));
              }
              return;
            }

            if (msg.type === 'edited' && existing) {
              // Update body in place.
              var bodyEl = existing.querySelector('.matrix-chat__body');
              if (bodyEl) bodyEl.textContent = msg.body;
              // Add edited indicator if not present.
              if (!existing.querySelector('.matrix-chat__edited')) {
                var edited = document.createElement('span');
                edited.className = 'matrix-chat__edited';
                edited.textContent = '(edited)';
                var timeEl = existing.querySelector('.matrix-chat__time');
                if (timeEl) timeEl.parentNode.insertBefore(edited, timeEl);
              }
              return;
            }

            // New message — append.
            if (!existing) {
              messagesEl.appendChild(renderMessage(msg));
            }
          });

          // Auto-scroll to bottom.
          messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        /**
         * Long-poll sync loop.
         */
        function poll() {
          var url = '/group/' + groupId + '/chat/sync?since=' + lastId + '&since_ts=' + syncTs;

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
              if (data.sync_ts) {
                syncTs = data.sync_ts;
              }

              if (data.has_new && data.messages && data.messages.length > 0) {
                if (initialLoad) {
                  messagesEl.innerHTML = '';
                  initialLoad = false;
                }

                // Remove placeholders.
                var empty = messagesEl.querySelector('.matrix-chat__empty');
                if (empty) empty.remove();
                var loading = messagesEl.querySelector('.matrix-chat__loading');
                if (loading) loading.remove();

                processMessages(data.messages);
                setTimeout(poll, 500);
              } else {
                initialLoad = false;
                setTimeout(poll, retryDelay);
              }
            })
            .catch(function (err) {
              console.warn('Matrix sync error:', err);
              retryDelay = Math.min(retryDelay * 2, 30000);
              setTimeout(poll, retryDelay);
            });
        }

        // Start the sync loop after a brief delay.
        setTimeout(poll, 2000);
      });
    }
  };

  /**
   * Auto-scroll on initial load and HTMX swaps.
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
