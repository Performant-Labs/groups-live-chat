/**
 * @file
 * Chat UI JavaScript — auto-scroll to bottom on new messages.
 */
(function (Drupal) {
  'use strict';

  /**
   * Auto-scroll the chat messages container to the bottom after HTMX swaps.
   */
  Drupal.behaviors.matrixChatScroll = {
    attach: function (context) {
      const containers = context.querySelectorAll
        ? context.querySelectorAll('[id^="matrix-chat-messages-"]')
        : [];

      containers.forEach(function (container) {
        // Scroll to bottom after content is loaded.
        container.scrollTop = container.scrollHeight;
      });
    }
  };

})(Drupal);
